<?php
namespace Tests\Feature;
use App\Models\ShopUser;
use App\Support\{BusinessSettings,SizeGuide};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class MeasurementsTest extends TestCase {
 use DatabaseTransactions;
 private function product():array {$id=DB::table('products')->insertGetId(['name'=>'QA Sized Saree','description'=>'QA description','category'=>DB::table('categories')->value('name'),'brand'=>DB::table('brands')->value('name'),'price'=>1000,'stock'=>8,'image'=>'/images/gold.jpg']);$a=DB::table('product_variants')->insertGetId(['product_id'=>$id,'type'=>'Unstitched','regular_price'=>1000,'stock'=>8,'enabled'=>1]);$b=DB::table('product_variants')->insertGetId(['product_id'=>$id,'type'=>'Stitched','regular_price'=>1,'stock'=>0,'enabled'=>0]);return[$id,$a,$b];}
 private function payload($items):array {return ['name'=>'QA Size Buyer','email'=>'size@example.test','phone'=>'03001234567','address'=>'QA address','country'=>'Pakistan','city'=>'Lahore','postal_code'=>'54000','payment_method'=>'cod','checkout_token'=>(string)Str::uuid(),'items'=>$items];}
 private function owner($role='super_admin'):void {$id=DB::table('users')->insertGetId(['name'=>'QA Staff','email'=>Str::uuid().'@example.test','password_hash'=>bcrypt('Strong test password!'),'role'=>$role]);$u=ShopUser::find($id);$this->withSession(['auth_version'=>$u->auth_version]);$this->actingAs($u);}
 public function test_independent_standard_sizes_and_purchase_snapshots():void {
  [$p,$a,$b]=$this->product();$chart=SizeGuide::defaults();$chart['unit']='cm';$chart['rows']['M']['bust']=96;DB::table('products')->where('id',$p)->update(['size_chart'=>json_encode($chart),'sku'=>'QA-SIZE']);
  $body=$this->payload([['id'=>$p,'variant_id'=>$b,'quantity'=>2,'selected_size'=>'M'],['id'=>$p,'variant_id'=>$b,'quantity'=>1,'selected_size'=>'XS'],['id'=>$p,'variant_id'=>$a,'quantity'=>1]]);
  $r=$this->postJson('/api/orders',$body)->assertCreated();$id=DB::table('orders')->where('reference',$r->json('reference'))->value('id');$lines=DB::table('order_items')->where('order_id',$id)->get();$this->assertCount(3,$lines);$m=$lines->firstWhere('selected_size','M');$this->assertEquals(96,json_decode($m->size_chart_snapshot,true)['measurements']['bust']);$this->assertEquals('QA-SIZE',$m->sku_snapshot);$this->assertEquals(5000,$m->stitching_charge);$this->assertNull($m->measurement_data);$this->assertEquals(4,DB::table('product_variants')->find($a)->stock);
  $this->postJson('/api/orders',$body)->assertCreated()->assertJsonPath('reference',$r->json('reference'));$body['items'][0]['selected_size']='L';$this->postJson('/api/orders',$body)->assertStatus(409);DB::table('products')->where('id',$p)->update(['stitching_charge'=>10,'size_chart'=>null]);$this->assertEquals(6000,$m->price);$this->assertEquals(96,json_decode(DB::table('order_items')->find($m->id)->size_chart_snapshot,true)['measurements']['bust']);
  $this->getJson('/api/admin/order-items?id='.$id)->assertForbidden();$this->owner('order_manager');$this->getJson('/api/admin/order-items?id='.$id)->assertOk()->assertJsonPath('items.1.selected_size','M');
 }
 public function test_sizes_and_every_required_checkout_field_are_validated():void {
  [$p,$a,$b]=$this->product();$line=['id'=>$p,'variant_id'=>$b,'quantity'=>1];$count=DB::table('orders')->count();foreach([null,'XXL','invalid'] as $size)$this->postJson('/api/orders',$this->payload([[...$line,'selected_size'=>$size]]))->assertUnprocessable();
  $this->postJson('/api/orders',$this->payload([[...$line,'selected_size'=>'M','measurements'=>['unit'=>'in','values'=>['bust'=>36]]]]))->assertUnprocessable();
  $this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$a,'quantity'=>1,'selected_size'=>'M']]))->assertUnprocessable();
  foreach(['name','phone','email','address','country','city','postal_code'] as $key){$body=$this->payload([['id'=>$p,'variant_id'=>$a,'quantity'=>1]]);unset($body[$key]);$this->postJson('/api/orders',$body)->assertUnprocessable()->assertJsonValidationErrors($key);}
  $this->assertEquals($count,DB::table('orders')->count());$this->assertEquals(8,DB::table('product_variants')->find($a)->stock);
  $this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$a,'quantity'=>1]]))->assertCreated();
 }
 public function test_configured_size_options_and_legacy_measurements_remain_independent():void {
  [$p,$a,$b]=$this->product();DB::table('products')->where('id',$p)->update(['size_options'=>json_encode(['S','M'])]);$this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$b,'quantity'=>1,'selected_size'=>'XL']]))->assertUnprocessable();
  $r=$this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$b,'quantity'=>1,'selected_size'=>'M']]))->assertCreated();$id=DB::table('orders')->where('reference',$r->json('reference'))->value('id');$old=json_encode(['unit'=>'cm','values_cm'=>['bust'=>90]]);DB::table('order_items')->where('order_id',$id)->update(['measurement_data'=>$old]);$this->owner();$chart=SizeGuide::defaults();$chart['rows']['S']['waist']=30;$this->putJson('/api/admin/business-settings',['size_chart'=>$chart])->assertOk();$this->getJson('/api/catalog')->assertOk()->assertJsonPath('business.size_chart.rows.S.waist',30);$this->assertEquals($old,DB::table('order_items')->where('order_id',$id)->value('measurement_data'));
  $chart['rows']['S']['waist']=-1;$this->putJson('/api/admin/business-settings',['size_chart'=>$chart])->assertUnprocessable();$this->owner('staff');$this->putJson('/api/admin/business-settings',['size_chart'=>SizeGuide::defaults()])->assertForbidden();
 }
}
