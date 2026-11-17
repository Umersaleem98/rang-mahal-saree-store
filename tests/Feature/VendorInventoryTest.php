<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorInventoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_vendor_submission_owner_review_and_public_checkout(): void
    {
        $password = 'Test-password-1234';
        $owner = Str::uuid().'@example.test';
        $vendor = Str::uuid().'@example.test';
        $other = Str::uuid().'@example.test';
        DB::table('users')->insert([
            'name' => 'Test owner', 'email' => $owner,
            'password_hash' => Hash::make($password), 'role' => 'super_admin',
        ]);

        $login = function (string $email) use ($password) {
            $this->postJson('/api/auth/logout')->assertOk();
            $this->postJson('/api/auth/login', compact('email', 'password'))->assertOk();
        };
        $login($owner);
        foreach ([$vendor, $other] as $email) {
            $this->postJson('/api/vendor/accounts', [
                'name' => 'Test supplier', 'email' => $email, 'password' => $password, 'active' => true,
            ])->assertOk();
        }

        $login($vendor);
        $this->getJson('/api/admin/data')->assertForbidden();
        $this->putJson('/api/vendor/review', ['id' => 1])->assertForbidden();
        $upload = $this->post('/api/vendor/media', [
            'images' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $images = $upload->json('images');

        try {
            $draft = [
                'name' => 'Test vendor saree', 'category' => DB::table('categories')->value('name'),
                'type' => 'Stitched', 'description' => 'Vendor test product',
                'fabric' => 'Silk', 'color' => 'Gold', 'sizes' => ['XXL'], 'stock' => 3, 'images' => $images,
                'purchase_cost' => 9000, 'margin' => 1000, 'proposed_price' => 14500, 'payable' => 10000,
            ];
            $this->postJson('/api/vendor/listings', $draft + ['code' => 'manual'])->assertUnprocessable();
            $submitted = $this->postJson('/api/vendor/listings', $draft)->assertOk();
            $id = $submitted->json('id');
            $code = $submitted->json('code');
            $this->assertMatchesRegularExpression('/^HS-[A-Z0-9]+-\d{4,}$/', $code);
            $this->assertDatabaseHas('vendor_submission_images',['listing_id'=>$id,'position'=>1]);
            $this->get($images[0])->assertOk();
            $this->assertFalse(collect($this->getJson('/api/catalog')->json('products'))->contains('name', $draft['name']));

            $login($other);
            $this->getJson('/api/vendor/data')->assertJsonCount(0, 'listings');
            $this->get($images[0])->assertNotFound();
            $this->getJson('/api/admin/order-items?id=1')->assertForbidden();
            $this->putJson('/api/vendor/listings', $draft + ['id' => $id])->assertNotFound();
            $this->postJson('/api/vendor/listings', $draft)->assertUnprocessable();
            $this->getJson('/api/orders')->assertForbidden();
            DB::table('categories')->insertOrIgnore(['name'=>'Dupatta']);
            $secondUpload=$this->post('/api/vendor/media',['images'=>[UploadedFile::fake()->image('detail.jpg')]],['Accept'=>'application/json'])->assertCreated();
            $secondImages=$secondUpload->json('images');
            $second=$this->postJson('/api/vendor/listings',array_replace($draft,['category'=>'Dupatta','name'=>'Test second supplier','images'=>$secondImages]))->assertOk();
            $this->assertStringStartsWith('HS-DUP-',$second->json('code'));
            $this->assertNotSame($code,$second->json('code'));
            $secondId=$second->json('id');

            $login($owner);
            $price = ['id'=>$id,'approved_payable'=>9900];
            $this->putJson('/api/vendor/review',$price+['status'=>'Published'])->assertUnprocessable();
            $this->putJson('/api/vendor/review',$price+['status'=>'Changes Requested','reason'=>'Add care details'])->assertOk();
            $login($vendor);
            $this->getJson('/api/vendor/data')->assertJsonPath('listings.0.review_reason','Add care details');
            $this->putJson('/api/vendor/listings',$draft+['id'=>$id])->assertOk();
            $login($owner);
            $this->putJson('/api/vendor/review',$price+['status'=>'Rejected','reason'=>'Review again'])->assertOk();
            $this->putJson('/api/vendor/review',$price+['status'=>'Approved'])->assertOk();
            $this->assertDatabaseMissing('vendor_purchases',['listing_id'=>$id]);
            $this->assertFalse(collect($this->getJson('/api/catalog')->json('products'))->contains('name',$draft['name']));
            $this->postJson('/api/vendor/purchase',['id'=>$id,'quantity'=>2])->assertOk();
            $purchase=DB::table('vendor_purchases')->where('listing_id',$id)->first();
            $this->postJson('/api/vendor/payment',['purchase_id'=>$purchase->id,'amount'=>5000])->assertOk();
            $this->postJson('/api/vendor/payment',['purchase_id'=>$purchase->id,'amount'=>14801])->assertUnprocessable();
            $new=$this->postJson('/api/admin/products',[
                'name'=>'Independent public product','description'=>'Public product description',
                'category'=>$draft['category'],'brand'=>DB::table('brands')->value('name'),'image'=>'/images/gold.jpg',
                'publication_status'=>'published','stitching_enabled'=>true,'stitching_charge'=>0,'stitching_stock_mode'=>'separate',
                'size_options'=>['XXL'],'variants'=>[
                    ['type'=>'Unstitched','regular_price'=>14000,'sale_enabled'=>false,'stock'=>0,'enabled'=>false],
                    ['type'=>'Stitched','regular_price'=>14000,'sale_enabled'=>false,'stock'=>3,'enabled'=>true],
                ],
            ])->assertOk();
            $this->postJson('/api/vendor/link',['id'=>$id,'product_id'=>$new->json('id')])->assertOk();
            $this->putJson('/api/vendor/review',['id'=>$secondId,'approved_payable'=>750,'status'=>'Approved'])->assertOk();
            $secondPublic=$this->postJson('/api/admin/products',[
                'name'=>'Independent unstitched product','description'=>'Public product from second supplier',
                'category'=>'Dupatta','brand'=>DB::table('brands')->value('name'),'image'=>'/images/gold.jpg',
                'publication_status'=>'published','stitching_enabled'=>false,'price'=>1000,'stock'=>3,
            ])->assertOk()->json('id');
            $this->postJson('/api/vendor/link',['id'=>$secondId,'product_id'=>$secondPublic])->assertOk();
            $secondVariant=DB::table('product_variants')->where('product_id',$secondPublic)->where('type','Unstitched')->value('id');
            $p=collect($this->getJson('/api/catalog')->assertOk()->json('products'))->firstWhere('id',$new->json('id'));
            $this->assertNotNull($p);
            foreach (['vendor_id','approved_payable','purchase_cost','cost_price','margin'] as $key) {
                $this->assertArrayNotHasKey($key, $p);
            }
            $variant = collect($p['variants'])->firstWhere('type', 'Stitched');
            $this->assertTrue($variant['available']);
            DB::table('store_settings')->where('id', 1)->update(['cod_enabled' => true]);
            $checkout = $this->postJson('/api/orders', [
                'name' => 'Test buyer', 'email' => 'buyer@example.test', 'phone' => '03001234567',
                'address' => 'Test shipping address', 'city' => 'Lahore', 'country' => 'Pakistan',
                'postal_code' => '54000', 'payment_method' => 'cod', 'checkout_token' => (string) Str::uuid(),
                'items' => [['id' => $p['id'], 'variant_id' => $variant['id'], 'selected_size' => 'XXL', 'quantity' => 1],
                    ['id'=>$secondPublic,'variant_id'=>$secondVariant,'quantity'=>1]],
            ])->assertCreated()->assertJsonPath('shipping', 400)->assertJsonPath('total', 15400);
            $checkout->assertJsonMissingPath('items.0.supplier');
            $orderId=DB::table('orders')->where('reference',$checkout->json('reference'))->value('id');
            $items=collect($this->getJson('/api/admin/order-items?id='.$orderId)->assertOk()->json('items'))->keyBy('product_id');
            $this->assertSame($code,$items[$p['id']]['supplier']['inventory_code']);
            $this->assertSame($second->json('code'),$items[$secondPublic]['supplier']['inventory_code']);
            $this->assertEquals(750,$items[$secondPublic]['supplier']['unit_payable']);
            $this->putJson('/api/vendor/review',['id'=>$secondId,'status'=>'Approved','approved_payable'=>900])->assertOk();
            $this->assertDatabaseHas('vendor_order_payables',['order_id'=>$orderId,'listing_id'=>$secondId,'unit_payable'=>750]);
            $login($vendor);
            $this->getJson('/api/vendor/data')->assertJsonMissingPath('listings.0.live_stock')->assertJsonMissingPath('sales');
            $this->assertDatabaseHas('vendor_order_payables', ['listing_id' => $id, 'unit_payable' => 9900, 'quantity' => 1]);
        } finally {
            foreach ([...$images,...($secondImages??[])] as $path) @unlink(storage_path('app/public/products/'.basename($path)));
        }
    }
}
