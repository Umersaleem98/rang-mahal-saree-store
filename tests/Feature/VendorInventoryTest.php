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
                'purchase_cost' => 9000, 'margin' => 1000, 'margin_type' => 'amount', 'payable' => 10000,
            ];
            $this->postJson('/api/vendor/listings', $draft + ['code' => 'manual'])->assertUnprocessable();
            $submitted = $this->postJson('/api/vendor/listings', $draft)->assertOk();
            $id = $submitted->json('id');
            $code = $submitted->json('code');
            $this->assertMatchesRegularExpression('/^HS-SAR-\d{4,}$/', $code);
            $this->assertFalse(collect($this->getJson('/api/catalog')->json('products'))->contains('name', $draft['name']));

            $login($other);
            $this->getJson('/api/vendor/data')->assertJsonCount(0, 'listings');
            $this->putJson('/api/vendor/listings', $draft + ['id' => $id])->assertNotFound();
            $this->postJson('/api/vendor/listings', $draft)->assertUnprocessable();

            $login($owner);
            $price = ['id' => $id, 'retail_price' => 14000, 'approved_payable' => 9900];
            $this->putJson('/api/vendor/review', $price + ['status' => 'Published'])->assertUnprocessable();
            $this->putJson('/api/vendor/review', $price + ['status' => 'Approved'])->assertOk();
            $this->putJson('/api/vendor/review', $price + ['status' => 'Published'])->assertOk();
            $p = collect($this->getJson('/api/catalog')->assertOk()->json('products'))->firstWhere('name', $draft['name']);
            $this->assertNotNull($p);
            foreach (['sku','vendor_id','approved_payable','purchase_cost','cost_price','margin'] as $key) {
                $this->assertArrayNotHasKey($key, $p);
            }
            $variant = collect($p['variants'])->firstWhere('type', 'Stitched');
            $this->assertTrue($variant['available']);
            DB::table('store_settings')->where('id', 1)->update(['cod_enabled' => true]);
            $checkout = $this->postJson('/api/orders', [
                'name' => 'Test buyer', 'email' => 'buyer@example.test', 'phone' => '03001234567',
                'address' => 'Test shipping address', 'city' => 'Lahore', 'country' => 'Pakistan',
                'postal_code' => '54000', 'payment_method' => 'cod', 'checkout_token' => (string) Str::uuid(),
                'items' => [['id' => $p['id'], 'variant_id' => $variant['id'], 'selected_size' => 'XXL', 'quantity' => 1]],
            ])->assertCreated()->assertJsonPath('shipping', 400)->assertJsonPath('total', 14400);
            $checkout->assertJsonPath('items.0.sku_snapshot', null);
            $this->assertDatabaseHas('vendor_order_payables', ['listing_id' => $id, 'unit_payable' => 9900, 'quantity' => 1]);
        } finally {
            foreach ($images as $path) @unlink(storage_path('app/public/products/'.basename($path)));
        }
    }
}
