<?php

namespace Tests\Feature;

use App\Models\ShopUser;
use App\Support\StoreAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use DatabaseTransactions;
 protected function setUp():void {parent::setUp();
  // Existing development orders are outside this test's mail batch; transaction rollback restores them.
  DB::table('order_email_outbox')->whereNull('sent_at')->update(['available_at'=>now()->addDay()]);
 }

    private function staff(string $role = 'super_admin', ?array $permissions = null): ShopUser
    {
        $id = DB::table('users')->insertGetId(['name' => 'QA Staff', 'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make('Strong test password!'), 'role' => $role, 'permissions' => $permissions === null ? null : json_encode($permissions)]);
        $u = ShopUser::findOrFail($id);
        $this->withSession(['auth_version' => $u->auth_version]);
        return $u;
    }

    private function product(): array
    {
        $id = DB::table('products')->insertGetId(['name' => 'QA Saree', 'description'=>'QA product description', 'category' => DB::table('categories')->value('name'), 'brand' => DB::table('brands')->value('name'), 'price' => 1000, 'stock' => 5, 'image' => '/images/gold.jpg', 'active' => 1, 'stitching_stock_mode' => 'separate']);
        $a = DB::table('product_variants')->insertGetId(['product_id' => $id, 'type' => 'Unstitched', 'regular_price' => 1500, 'sale_price' => 1000, 'sale_enabled' => 1, 'stock' => 3, 'enabled' => 1]);
        $b = DB::table('product_variants')->insertGetId(['product_id' => $id, 'type' => 'Stitched', 'regular_price' => 2000, 'stock' => 2, 'enabled' => 1]);
        return [$id, $a, $b];
    }

    private function payload(int $id, int $variant): array
    {
        return ['country'=>'Pakistan','name' => 'Guest Buyer', 'email' => 'guest@example.test', 'phone' => '03001234567', 'address' => 'QA delivery address', 'city' => 'Lahore', 'postal_code' => '54000', 'payment_method' => 'cod', 'checkout_token' => (string) Str::uuid(), 'items' => [['id' => $id, 'variant_id' => $variant, 'quantity' => 1]], 'total' => 1, 'role' => 'super_admin'];
    }

    public function test_guest_checkout_tracking_prices_and_idempotency(): void
    {
        DB::table('store_settings')->where('id', 1)->update(['cod_enabled' => true]);
        [$id, $variant, $stitched] = $this->product(); $payload = $this->payload($id, $variant); $users = DB::table('users')->count();
        $response = $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('total', 1350);
        $reference = $response->json('reference'); $this->assertMatchesRegularExpression('/^RM-SR-[0-9]{8}-[0-9]{6,}$/', $reference);
        $this->assertDatabaseHas('orders', ['reference' => $reference, 'user_id' => null, 'postal_code' => '54000']);
        $this->assertDatabaseHas('order_items', ['variant_id' => $variant, 'variant' => 'Unstitched', 'price' => 1000]);
        $this->assertEquals(2, DB::table('product_variants')->find($variant)->stock);
        $this->assertEquals(2, DB::table('product_variants')->find($stitched)->stock);
        $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('reference', $reference);
        $this->assertEquals(1, DB::table('orders')->where('checkout_token', $payload['checkout_token'])->count());
        $this->assertEquals(2, DB::table('product_variants')->find($variant)->stock);
        $this->postJson('/api/orders', [...$payload, 'city' => 'Karachi'])->assertStatus(409);
        $this->postJson('/api/track', ['reference' => $reference, 'email' => 'guest@example.test'])->assertOk()->assertJsonPath('order.status', 'Pending')->assertJsonPath('order.items.0.variant', 'Unstitched')->assertJsonMissingPath('order.email')->assertJsonMissingPath('order.address');
        $this->getJson('/api/admin/data')->assertForbidden();
        $this->getJson('/api/auth')->assertJsonPath('user', null);
        $this->assertEquals($users, DB::table('users')->count());
    }

    public function test_stock_validation_and_disabled_payments(): void
    {
        [$id, $variant, $stitched] = $this->product(); $v = $this->payload($id, $variant);
        $this->postJson('/api/orders', [...$v, 'payment_method' => 'card'])->assertUnprocessable();
        DB::table('store_settings')->where('id', 1)->update(['cod_enabled' => false]);
        $this->postJson('/api/orders', $v)->assertStatus(400);
        DB::table('store_settings')->where('id', 1)->update(['cod_enabled' => true]);
        $this->postJson('/api/orders', [...$v, 'items' => [['id' => $id, 'variant_id' => $variant, 'quantity' => 4]]])->assertStatus(409);
        $this->postJson('/api/orders', [...$v, 'items' => [['id' => $id, 'variant_id' => $variant, 'quantity' => 15], ['id' => $id, 'variant_id' => $variant, 'quantity' => 15]]])->assertUnprocessable();
        $this->postJson('/api/orders', [...$v, 'postal_code' => ''])->assertUnprocessable();
        $this->assertEquals(3, DB::table('product_variants')->find($variant)->stock);
        $this->assertDatabaseMissing('orders', ['checkout_token' => $v['checkout_token']]);
    }

    public function test_order_status_flow_and_single_stock_restore(): void
    {
        [$id, $variant] = $this->product();
        $res = $this->postJson('/api/orders', $this->payload($id, $variant))->assertCreated();
        $order = DB::table('orders')->where('reference', $res->json('reference'))->first();
        $this->actingAs($this->staff());
        $this->putJson('/api/admin/orders', ['id' => $order->id, 'status' => 'Delivered'])->assertStatus(409);
        foreach (['Confirmed', 'Processing', 'Packed', 'Shipped', 'Out for Delivery', 'Delivered'] as $status) $this->putJson('/api/admin/orders', ['id' => $order->id, 'status' => $status,'delivery_method'=>'direct'])->assertOk();
        $this->putJson('/api/admin/orders', ['id' => $order->id, 'status' => 'Cancelled'])->assertStatus(409);
        $second = $this->postJson('/api/orders', $this->payload($id, $variant))->assertCreated();
        $order2 = DB::table('orders')->where('reference', $second->json('reference'))->first();
        $this->putJson('/api/admin/orders', ['id' => $order2->id, 'status' => 'Cancelled'])->assertOk();
        $this->putJson('/api/admin/orders', ['id' => $order2->id, 'status' => 'Cancelled'])->assertOk();
        $this->assertEquals(2, DB::table('product_variants')->find($variant)->stock);
    }

    public function test_registration_is_disabled_and_customer_cannot_login_to_dashboard(): void
    {
        $u = $this->staff('customer');
        $this->postJson('/api/auth/register', ['role' => 'super_admin'])->assertNotFound();
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Strong test password!'])->assertUnauthorized();
        $this->actingAs($u)->getJson('/api/admin/data')->assertForbidden();
    }

    public function test_roles_are_enforced_by_backend_and_data_is_scoped(): void
    {
        $this->actingAs($this->staff('staff'));
        $this->postJson('/api/admin/products', [])->assertForbidden();
        $this->putJson('/api/admin/orders', [])->assertForbidden();
        $this->putJson('/api/admin/settings', [])->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($this->staff('order_manager'));
        $this->getJson('/api/admin/data')->assertOk()->assertJsonPath('products', [])->assertJsonPath('categories', []);
        $this->postJson('/api/admin/products', [])->assertForbidden();
        $this->actingAs($this->staff('product_manager'));
        $this->getJson('/api/admin/data')->assertOk()->assertJsonPath('orders', [])->assertJsonPath('customers', []);
        $this->putJson('/api/admin/orders', [])->assertForbidden();
        $this->actingAs($this->staff('staff', ['users.manage', 'roles.manage']));
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_user_crud_activation_permissions_and_owner_protection(): void
    {
        $owner = $this->staff(); $this->actingAs($owner);
        $email = Str::uuid().'@example.test';
        $body = ['name' => 'QA Manager', 'email' => $email, 'role' => 'product_manager', 'active' => true, 'password' => 'New strong password!', 'permissions' => null];
        $this->postJson('/api/admin/users', $body)->assertOk();
        $u = ShopUser::where('email', $email)->firstOrFail(); $this->assertTrue(Hash::check($body['password'], $u->password_hash));
        $this->getJson('/api/admin/users')->assertOk()->assertJsonMissingPath('users.0.password_hash');
        $this->putJson('/api/admin/users', [...$body, 'id' => $u->id, 'active' => false, 'password' => null])->assertOk();
        $this->assertFalse((bool) $u->fresh()->active);
        $this->assertEquals(2, $u->fresh()->auth_version);
        $this->deleteJson('/api/admin/users', ['id' => $owner->id])->assertUnprocessable();
        $this->putJson('/api/admin/users', ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'role' => 'staff', 'active' => true])->assertUnprocessable();
        $this->deleteJson('/api/admin/users', ['id' => $u->id])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $u->id]);
        $this->actingAs($owner->fresh());
        $this->assertContains('roles.manage', StoreAccess::permissions($owner));
    }

    public function test_sale_featured_and_variation_management(): void
    {
        $this->actingAs($this->staff()); [$id, $a, $b] = $this->product();
        $p = DB::table('products')->find($id);
        $payload = ['id' => $id, 'name' => $p->name, 'category' => $p->category, 'brand' => $p->brand, 'price' => 900, 'stock' => 7, 'image' => $p->image, 'featured' => true, 'images' => [$p->image], 'variants' => [
            ['type' => 'Unstitched', 'regular_price' => 1500, 'sale_price' => 900, 'sale_enabled' => true, 'stock' => 4, 'enabled' => true],
            ['type' => 'Stitched', 'regular_price' => 2000, 'sale_price' => null, 'sale_enabled' => false, 'stock' => 3, 'enabled' => true],
        ]];
        $this->putJson('/api/admin/products', $payload)->assertOk();
        // Round-trip catalog fields from the editor; derived fields and forged IDs are ignored.
        $payload['variants'][0]['product_id'] = 999999;
        $payload['variants'][0]['id'] = 999999;
        $payload['variants'][0]['price'] = 1;
        $payload['variants'][0]['discount'] = 99;
        $this->putJson('/api/admin/products', $payload)->assertOk();
        $this->assertDatabaseHas('product_variants', ['id' => $a, 'product_id' => $id, 'sale_price' => 900]);
        $catalog = collect($this->getJson('/api/catalog')->assertOk()->json('products'))->firstWhere('id', $id);
        $this->assertEquals(900, $catalog['price']); $this->assertEquals(40, $catalog['discount']); $this->assertEquals(7, $catalog['stock']); $this->assertTrue((bool) $catalog['featured']);
        $payload['variants'][0]['sale_enabled'] = false;
        $this->putJson('/api/admin/products', $payload)->assertOk();
        $catalog = collect($this->getJson('/api/catalog')->json('products'))->firstWhere('id', $id);
        $this->assertEquals(1500, $catalog['price']); $this->assertEquals(0, $catalog['discount']);
        $this->actingAs($this->staff('staff', ['products.view', 'products.edit']));
        $payload['variants'][0]['stock'] = 500;
        $this->putJson('/api/admin/products', $payload)->assertForbidden();
    }

    public function test_image_upload_compresses_webp_and_validates_limits(): void
    {
        $this->actingAs($this->staff('staff'));
        $this->post('/api/admin/media', ['images' => [UploadedFile::fake()->image('photo.jpg')]], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->staff('product_manager'));
        $this->post('/api/admin/media', ['images' => array_map(fn () => UploadedFile::fake()->image('photo.jpg'), range(1, 6))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/admin/media', ['images' => [UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $response = $this->post('/api/admin/media', ['images' => [UploadedFile::fake()->image('photo.jpg', 1800, 1200), UploadedFile::fake()->image('photo.png', 100, 100)]], ['Accept' => 'application/json'])->assertCreated();
        try {
            foreach ($response->json('images') as $url) {
                $this->assertMatchesRegularExpression('~^/media/products/[a-f0-9]{32}\.webp$~', $url);
                $path = storage_path('app/public/products/'.basename($url));
                $info = getimagesize($path); $this->assertSame('image/webp', $info['mime']); $this->assertLessThanOrEqual(4096, max($info[0], $info[1]));
                $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
            }
        } finally { foreach ($response->json('images') as $url) @unlink(storage_path('app/public/products/'.basename($url))); }
    }

    public function test_confirmation_email_when_configured_and_card_stays_hidden(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']); [$id, $variant] = $this->product();
        $payload = $this->payload($id, $variant);
        $response = $this->postJson('/api/orders', $payload)->assertCreated();
        $this->postJson('/api/orders', $payload)->assertCreated();
        $orderId = DB::table('orders')->where('reference', $response->json('reference'))->value('id');
        $this->assertEquals(1, DB::table('order_email_outbox')->where('order_id', $orderId)->count());
        $this->assertEquals(1, \App\Support\OrderEmails::deliver());
        $this->assertEquals(0, \App\Support\OrderEmails::deliver());
        Mail::assertSent(\App\Mail\OrderUpdate::class, function ($mail) use ($response) {
            $html = $mail->render();
            return $mail->hasTo('guest@example.test') && str_contains($html, $response->json('reference')) && str_contains($html, 'Track your order') && str_contains($html, 'QA Saree');
        });
        $this->actingAs($this->staff());
        $this->putJson('/api/admin/orders', ['id' => $orderId, 'status' => 'Confirmed'])->assertOk();
        $this->putJson('/api/admin/orders', ['id' => $orderId, 'status' => 'Confirmed'])->assertOk();
        $this->assertEquals(2, DB::table('order_email_outbox')->where('order_id', $orderId)->count());
        $this->assertEquals(1, \App\Support\OrderEmails::deliver());
        Mail::assertSent(\App\Mail\OrderUpdate::class, 2);
        $this->assertDatabaseHas('orders', ['email' => 'guest@example.test', 'user_id' => null]);
        $this->getJson('/api/settings')->assertJsonPath('payments.card_enabled', false);
        $this->actingAs($this->staff());
        $this->putJson('/api/admin/settings', ['cod_enabled' => true, 'bank_enabled' => false, 'card_enabled' => true])->assertUnprocessable();
    }

    public function test_all_actual_order_statuses_enqueue_branded_messages_once(): void
    {
        Mail::fake();config(['mail.default'=>'smtp','mail.from.address'=>'info@rangmahal.site','mail.from.name'=>'Rang Mahal']);
        [$id,$variant]=$this->product();$r=$this->postJson('/api/orders',$this->payload($id,$variant))->assertCreated();
        $orderId=DB::table('orders')->where('reference',$r->json('reference'))->value('id');$this->actingAs($this->staff());
        foreach(['Confirmed','Processing','Packed','Shipped','Out for Delivery','Delivered','Returned'] as $status){
            $this->putJson('/api/admin/orders',['id'=>$orderId,'status'=>$status,'delivery_method'=>'direct'])->assertOk();
            $this->putJson('/api/admin/orders',['id'=>$orderId,'status'=>$status,'delivery_method'=>'direct'])->assertOk();
        }
        $this->assertEquals(8,DB::table('order_email_outbox')->where('order_id',$orderId)->count());
        $this->assertEquals(8,\App\Support\OrderEmails::deliver());
        Mail::assertSent(\App\Mail\OrderUpdate::class,8);
        Mail::assertSent(\App\Mail\OrderUpdate::class,function($mail){$html=$mail->render();return $mail->details['order']['status']==='Shipped' && str_contains($mail->heading(),'dispatched') && str_contains($html,'alt="Rang Mahal"') && str_contains($html,'info@rangmahal.site') && str_contains($html,'Track your order');});
        Mail::assertSent(\App\Mail\OrderUpdate::class,fn($mail)=>$mail->details['event']==='placed' && str_contains($mail->heading(),'received'));
    }

    public function test_email_failures_retry_without_changing_order_or_stock(): void
    {
        config(['mail.default' => 'smtp']);
        [$id, $variant] = $this->product();
        $r = $this->postJson('/api/orders', $this->payload($id, $variant))->assertCreated();
        $orderId = DB::table('orders')->where('reference', $r->json('reference'))->value('id');
        Mail::shouldReceive('getDefaultDriver')->andReturn('smtp');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Simulated SMTP outage'));
        $this->assertEquals(0, \App\Support\OrderEmails::deliver());
        $entry = DB::table('order_email_outbox')->where('order_id', $orderId)->first();
        $this->assertNull($entry->sent_at);
        $this->assertEquals(1, $entry->attempts);
        $this->assertDatabaseHas('order_email_attempts', ['outbox_id'=>$entry->id,'attempt'=>1,'outcome'=>'failed']);
        $this->assertGreaterThan(now()->timestamp, strtotime($entry->available_at));
        $this->assertEquals(2, DB::table('product_variants')->find($variant)->stock);
        Mail::fake();
        DB::table('order_email_outbox')->where('id', $entry->id)->update(['available_at' => now()]);
        $this->assertEquals(1, \App\Support\OrderEmails::deliver());
        Mail::assertSent(\App\Mail\OrderUpdate::class, 1);
        $this->assertNotNull(DB::table('order_email_outbox')->find($entry->id)->sent_at);
        $this->assertDatabaseHas('order_email_attempts', ['outbox_id'=>$entry->id,'attempt'=>2,'outcome'=>'accepted']);
    }

    public function test_disabled_staff_and_stale_sessions_are_rejected(): void
    {
        $u = $this->staff('admin');
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Strong test password!'])->assertOk();
        DB::table('users')->where('id', $u->id)->increment('auth_version');
        $this->actingAs($u->fresh())->getJson('/api/admin/data')->assertForbidden();
        DB::table('users')->where('id', $u->id)->update(['active' => false]);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'Strong test password!'])->assertUnauthorized();
    }
}
