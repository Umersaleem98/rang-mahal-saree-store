<?php
namespace Tests\Feature;

use App\Models\ShopUser;
use App\Support\VendorSettlement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role):ShopUser
    {
        $id=DB::table('users')->insertGetId(['name'=>'QA '.$role,'email'=>Str::uuid().'@example.test','role'=>$role,'password_hash'=>Hash::make('Test-only-password-123')]);
        return ShopUser::findOrFail($id);
    }

    private function login(ShopUser $user):void
    {
        $this->actingAs($user)->withSession(['auth_version'=>$user->auth_version]);
    }

    private function sale(bool $stitched=false):array
    {
        $vendor=$this->user('vendor');$owner=$this->user('super_admin');
        $product=DB::table('products')->insertGetId([
            'name'=>'QA settlement saree','description'=>'Official photo listing','category'=>DB::table('categories')->value('name'),
            'brand'=>DB::table('brands')->value('name'),'price'=>10000,'stock'=>5,'image'=>'/images/gold.jpg',
            'stitching_enabled'=>$stitched,'stitching_charge'=>5000,'sku'=>'HS-SAR-'.Str::random(8),
        ]);
        $base=DB::table('product_variants')->insertGetId(['product_id'=>$product,'type'=>'Unstitched','regular_price'=>10000,'stock'=>5,'enabled'=>true]);
        $stitch=DB::table('product_variants')->insertGetId(['product_id'=>$product,'type'=>'Stitched','regular_price'=>15000,'stock'=>0,'enabled'=>false]);
        $listing=DB::table('vendor_listings')->insertGetId([
            'vendor_id'=>$vendor->id,'product_id'=>$product,'details'=>json_encode(['name'=>'QA submission','category'=>'Saree']),
            'status'=>'Approved','approved_payable'=>5000,'inventory_code'=>'HS-SAR-'.Str::random(8),'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('vendor_submission_finances')->insert(['listing_id'=>$listing,'purchase_cost'=>4000,'margin'=>1000,'payable'=>5000]);
        DB::table('store_settings')->where('id',1)->update(['cod_enabled'=>true]);
        $payload=['name'=>'QA buyer','email'=>'buyer@example.test','phone'=>'03001234567','address'=>'QA shipping address','city'=>'Lahore','postal_code'=>'54000','country'=>'Pakistan','payment_method'=>'cod','checkout_token'=>(string)Str::uuid(),'items'=>[['id'=>$product,'variant_id'=>$stitched?$stitch:$base,'quantity'=>1,'selected_size'=>$stitched?'M':null]]];
        $order=$this->postJson('/api/orders',$payload)->assertCreated()->assertJsonPath('shipping',400);
        $this->postJson('/api/orders',$payload)->assertCreated()->assertJsonPath('reference',$order->json('reference'));
        $id=DB::table('orders')->where('reference',$order->json('reference'))->value('id');
        $terms=DB::table('vendor_sale_terms')->where('order_id',$id)->first();
        $fulfillment=DB::table('vendor_fulfillments')->where('order_id',$id)->first();
        $this->assertEquals($vendor->id,$fulfillment->vendor_id);
        $this->assertSame(1,DB::table('vendor_fulfillments')->where('order_id',$id)->count());
        return [$owner,$vendor,$terms,$fulfillment,$listing,$product];
    }

    public function test_standard_sale_partial_payments_and_return_reversal():void
    {
        [$owner,$vendor,$sale,$fulfillment,$listing,$product]=$this->sale();
        $this->login($owner);
        $body=['sale_id'=>$sale->id,'courier_cost'=>400,'collected'=>10400,'refunded'=>0,'collector'=>'vendor','notes'=>'Verified COD statement and courier invoice'];
        $this->postJson('/api/settlement/reconcile',$body)->assertOk();
        $this->assertDatabaseMissing('vendor_settlement_entries',['sale_id'=>$sale->id,'kind'=>'Sale']);
        DB::table('vendor_fulfillments')->where('id',$fulfillment->id)->update(['status'=>'Delivered']);
        DB::transaction(fn()=>VendorSettlement::syncOrder($sale->order_id));
        $entry=DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->first();
        $components=json_decode($entry->components,true);
        $this->assertSame(460000,$components['product_net']);
        $this->assertSame(40000,$components['shipping']);
        $this->assertEquals(500000,$entry->amount);
        $this->postJson('/api/settlement/reconcile',$body)->assertOk();
        $this->assertSame(1,DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->count());
        $payment=['vendor_id'=>$vendor->id,'amount'=>2000,'paid_on'=>today()->toDateString(),'method'=>'Bank transfer','reference'=>'QA-'.Str::uuid(),'token'=>(string)Str::uuid()];
        $this->postJson('/api/settlement/payment',$payment)->assertOk();
        $this->postJson('/api/settlement/payment',$payment)->assertOk();
        $this->postJson('/api/settlement/payment',array_replace($payment,['token'=>(string)Str::uuid(),'reference'=>'QA-'.Str::uuid(),'amount'=>3000.01]))->assertUnprocessable();
        $data=$this->getJson('/api/settlement/data?vendor_id='.$vendor->id)->assertOk()->assertJsonPath('balances.0.outstanding',300000);
        $this->login($vendor);
        $this->getJson('/api/settlement/data?vendor_id='.$owner->id)->assertOk()->assertJsonCount(1,'sales');
        $this->postJson('/api/settlement/reconcile',$body)->assertForbidden();
        $this->get('/api/settlement/dispatch')->assertOk();
        $pdf=$this->get('/api/settlement/statement?format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF',$pdf->getContent());
        $xlsx=$this->get('/api/settlement/statement?format=xlsx')->assertOk();
        $zip=new \ZipArchive();$this->assertTrue($zip->open($xlsx->baseResponse->getFile()->getPathname()));$this->assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml'));$zip->close();
        $other=$this->user('vendor');$this->login($other);
        $this->getJson('/api/settlement/data?vendor_id='.$vendor->id)->assertOk()->assertJsonCount(0,'sales')->assertJsonCount(0,'payments');
        DB::table('products')->where('id',$product)->update(['price'=>1]);
        DB::table('vendor_submission_finances')->where('listing_id',$listing)->update(['purchase_cost'=>1]);
        $this->assertDatabaseHas('vendor_sale_terms',['id'=>$sale->id,'purchase_cost'=>400000,'product_retail'=>1000000]);
        DB::table('vendor_fulfillments')->where('id',$fulfillment->id)->update(['status'=>'Returned']);
        DB::transaction(fn()=>VendorSettlement::syncOrder($sale->order_id));
        DB::transaction(fn()=>VendorSettlement::syncOrder($sale->order_id));
        $this->assertSame(3,DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->count());
        $this->assertEquals(-40000,DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->sum('amount'));
    }

    public function test_incomplete_collection_and_expense_payer_are_separate():void
    {
        [$owner,$vendor,$sale,$fulfillment]=$this->sale();
        $this->login($owner);
        DB::table('vendor_fulfillments')->where('id',$fulfillment->id)->update(['status'=>'Delivered']);
        $body=['sale_id'=>$sale->id,'courier_cost'=>600,'collected'=>1000,'refunded'=>0,'collector'=>'vendor','courier_payer'=>'store','notes'=>'Partial collection'];
        $this->postJson('/api/settlement/reconcile',$body)->assertOk();
        $this->assertDatabaseMissing('vendor_settlement_entries',['sale_id'=>$sale->id]);
        $body['collected']=10400;
        $this->postJson('/api/settlement/reconcile',$body)->assertOk();
        $row=DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->latest('id')->first();
        $this->assertEquals(540000,$row->amount);
        $this->assertEquals(480000,$row->earnings);
        $body['collector']='store';
        $this->postJson('/api/settlement/reconcile',$body)->assertOk();
        $this->assertEquals(-500000,DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->sum('amount'));
        $this->assertSame(3,DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->count());
    }

    public function test_stitching_components_and_dispatch_gate():void
    {
        [$owner,$vendor,$sale,$fulfillment]=$this->sale(true);
        $this->assertEquals(500000,$sale->entitlement);
        $this->assertEquals(500000,$sale->stitch_charge);
        $this->assertEquals(250000,$sale->vendor_stitch);
        $this->login($vendor);
        $this->postJson('/api/settlement/stitching',['id'=>$fulfillment->id,'status'=>'Stitching Completed'])->assertUnprocessable();
        foreach(['Stitching Requested','Awaiting Vendor Confirmation','Stitching in Progress','Stitching Completed','Ready for Dispatch'] as $status)$this->postJson('/api/settlement/stitching',['id'=>$fulfillment->id,'status'=>$status])->assertOk();
        $this->login($owner);
        DB::table('vendor_fulfillments')->where('id',$fulfillment->id)->update(['status'=>'Delivered']);
        $this->postJson('/api/settlement/reconcile',['sale_id'=>$sale->id,'courier_cost'=>400,'collected'=>15400,'refunded'=>0,'collector'=>'vendor','notes'=>'Verified'])->assertOk();
        $entry=DB::table('vendor_settlement_entries')->where('sale_id',$sale->id)->first();
        $c=json_decode($entry->components,true);
        $this->assertSame(250000,$c['stitch_revenue']);$this->assertSame(460000,$c['product_net']);
        $this->assertEquals(750000,$entry->amount);
    }
}

