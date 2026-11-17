<?php

namespace App\Http\Controllers;

use App\Support\{BusinessSettings, MediaLibrary, SizeGuide, StoreCatalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rule;

class VendorInventoryController extends Controller
{
    public function handle(Request $request, string $path)
    {
        $user = $request->user();
        $owner = $user?->role === 'super_admin';
        abort_unless($owner || $user?->role === 'vendor', 403);
        $method = $request->method();

        if ($path === 'accounts') {
            abort_unless($owner && in_array($method, ['POST', 'PUT']), 403);
            $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
            $fields = $request->validate([
                'id' => $method === 'PUT' ? 'required|integer' : 'prohibited',
                'name' => 'required|string|max:100',
                'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($request->input('id'))],
                'password' => ($method === 'POST' ? 'required' : 'nullable').'|string|min:12|max:128',
                'active' => 'required|boolean',
            ]);
            $account = ['name' => $fields['name'], 'email' => strtolower($fields['email']), 'active' => $fields['active']];
            if (!empty($fields['password'])) $account['password_hash'] = Hash::make($fields['password']);
            if ($method === 'POST') {
                DB::table('users')->insert($account + ['role' => 'vendor', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('users')->where('id', $fields['id'])->where('role', 'vendor')
                    ->update($account + ['auth_version' => DB::raw('auth_version + 1'), 'updated_at' => now()]);
            }

            return response()->json(['ok' => true]);
        }

        if ($path === 'media' && $method === 'POST') {
            abort_if($request->hasFile('video'), 422);
            return app(ProductMediaController::class)->upload($request);
        }

        if ($path === 'data' && $method === 'GET') {
            $listings = DB::table('vendor_listings as listing')
                ->leftJoin('products as product', 'product.id', '=', 'listing.product_id')
                ->when(!$owner, fn ($q) => $q->where('listing.vendor_id', $user->id))
                ->orderByDesc('listing.id')->get(['listing.*', 'product.stock as live_stock', 'product.active as live_active', 'product.price as live_price'])
                ->map(function ($listing) {
                    $listing->code = $this->code($listing->id);
                    $listing->details = json_decode($listing->details, true, flags: JSON_THROW_ON_ERROR);
                    return $listing;
                });
            $result = ['listings' => $listings, 'categories' => DB::table('categories')->pluck('name')];
            if ($owner) {
                $result['vendors'] = DB::table('users')->where('role', 'vendor')->get(['id', 'name', 'email', 'active']);
                $result['payables'] = DB::table('vendor_order_payables as p')->join('orders as o', 'o.id', '=', 'p.order_id')
                    ->where('o.status', 'Delivered')->get(['p.id', 'p.listing_id', 'p.quantity', 'p.unit_payable', 'p.paid_at']);
            }

            return response()->json($result);
        }

        if ($path === 'settle' && $method === 'PUT') {
            abort_unless($owner, 403);
            $data = $request->validate(['id' => 'required|integer']);
            DB::table('vendor_order_payables')->where('id', $data['id'])->whereNull('paid_at')
                ->whereIn('order_id', DB::table('orders')->where('status', 'Delivered')->select('id'))->update(['paid_at' => now()]);

            return response()->json(['ok' => true]);
        }

        if ($path === 'review' && $method === 'PUT') {
            abort_unless($owner, 403);
            return $this->review($request);
        }

        if ($path === 'listings' && in_array($method, ['POST', 'PUT'])) {
            return $this->saveListing($request, $owner);
        }

        abort(404);
    }

    private function code(int $id): string
    {
        return 'HS-SAR-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function saveListing(Request $r, bool $owner)
    {
        $data = $r->validate([
            'id' => $r->isMethod('PUT') ? 'required|integer' : 'prohibited',
            'vendor_id' => $owner ? 'nullable|integer' : 'prohibited',
            'name' => 'required|string|max:150', 'category' => 'required|exists:categories,name',
            'type' => 'required|in:Stitched,Unstitched', 'description' => 'required|string|max:10000',
            'fabric' => 'required|string|max:100', 'color' => 'required|string|max:50',
            'sizes' => 'required_if:type,Stitched|array|max:6',
            'sizes.*' => ['distinct', Rule::in(SizeGuide::SIZES)],
            'stock' => 'required|integer|min:0|max:1000000',
            'expected_stock' => 'nullable|integer|min:0|max:1000000',
            'images' => 'required|array|min:1|max:'.BusinessSettings::get()['max_product_images'],
            'images.*' => ['distinct', 'regex:~^/media/products/[a-f0-9]{32}\.webp$~D'],
            'purchase_cost' => 'required|numeric|decimal:0,2|min:0|max:10000000',
            'margin' => 'required|numeric|decimal:0,2|min:0|max:10000000',
            'margin_type' => 'required|in:amount,percent',
            'payable' => 'required|numeric|decimal:0,2|min:0|max:10000000',
            'retail_price' => 'prohibited', 'code' => 'prohibited',
        ]);

        return DB::transaction(function () use ($r, $owner, $data) {
            $listing = isset($data['id']) ? DB::table('vendor_listings')->where('id', $data['id'])
                ->when(!$owner, fn ($query) => $query->where('vendor_id', $r->user()->id))->lockForUpdate()->first() : null;
            abort_if(isset($data['id']) && !$listing, 404);
            $vendor = $listing->vendor_id ?? ($owner ? ($data['vendor_id'] ?? null) : $r->user()->id);
            abort_unless(DB::table('users')->where('id', $vendor)->where('role', 'vendor')->exists(), 422, 'Select a vendor.');
            $previousImages = $listing ? json_decode($listing->details, true, flags: JSON_THROW_ON_ERROR)['images'] : [];
            $newImages = array_diff($data['images'], $previousImages);
            $images = DB::table('media_assets')->whereIn('path', $newImages)->whereNull('trashed_at')
                ->where('kind', 'image')->whereIn('uploaded_by', [$vendor, $r->user()->id])->lockForUpdate()->get(['id'])->count();
            abort_unless($images === count($newImages), 422, 'Choose images uploaded by this account.');
            $details = collect($data)->except(['id', 'vendor_id', 'expected_stock'])->all();
            $values = ['details' => json_encode($details, JSON_THROW_ON_ERROR), 'status' => 'Pending', 'updated_at' => now()];
            if ($listing?->product_id && isset($data['expected_stock']) && (int) $data['stock'] !== (int) $data['expected_stock']) {
                $live = DB::table('products')->where('id', $listing->product_id)->lockForUpdate()->first();
                abort_unless((int) $live->stock === (int) $data['expected_stock'], 409, 'Stock changed. Reopen the listing and try again.');
                $liveType = DB::table('product_variants')->where('product_id', $listing->product_id)->where('enabled', true);
                $liveType->update(['stock' => $data['stock']]);
                StoreCatalog::syncStock($listing->product_id);
            }
            if ($listing) {
                DB::table('vendor_listings')->where('id', $listing->id)->update($values);
                $id = $listing->id;
            } else {
                $id = DB::table('vendor_listings')->insertGetId($values + ['vendor_id' => $vendor, 'created_at' => now()]);
            }

            return response()->json(['id' => $id, 'code' => $this->code($id)]);
        }, 3);
    }

    private function review(Request $r)
    {
        $data = $r->validate([
            'id' => 'required|integer', 'status' => 'required|in:Approved,Published,Rejected,Unpublished',
            'show_code' => 'sometimes|boolean',
            'retail_price' => 'required_if:status,Approved,Published|nullable|numeric|decimal:0,2|min:0.01|max:10000000',
            'approved_payable' => 'required_if:status,Approved,Published|nullable|numeric|decimal:0,2|min:0|max:10000000',
        ]);

        return DB::transaction(function () use ($data) {
            $listing = DB::table('vendor_listings')->where('id', $data['id'])->lockForUpdate()->first();
            abort_unless($listing, 404);
            $status = $data['status'];
            $values = ['status' => $status, 'updated_at' => now()];
            if (array_key_exists('show_code', $data)) $values['show_code'] = $data['show_code'];
            if (in_array($status, ['Approved', 'Published'], true)) {
                $values['approved_payable'] = $data['approved_payable'];
                $values['retail_price'] = $data['retail_price'];
            }
            if ($status === 'Published') {
                abort_unless(in_array($listing->status, ['Approved', 'Published']), 422, 'Approve the listing first.');
                $draft = json_decode($listing->details, true, flags: JSON_THROW_ON_ERROR);
                $product = collect($draft)->only(['name', 'category', 'description', 'fabric', 'color', 'stock'])->all();
                $product += [
                    'brand' => 'Harveer Singh', 'price' => $data['retail_price'], 'compare_price' => 0,
                    'cost_price' => $data['approved_payable'], 'image' => $draft['images'][0],
                    'images' => json_encode($draft['images'], JSON_THROW_ON_ERROR),
                    'size_options' => json_encode(($draft['sizes'] ?? []) ?: SizeGuide::SIZES, JSON_THROW_ON_ERROR),
                    'sku' => $this->code($listing->id), 'active' => true, 'publication_status' => 'published',
                    'stitching_enabled' => $draft['type'] === 'Stitched', 'stitching_charge' => 0,
                    'stitching_stock_mode' => 'separate',
                ];
                if ($listing->product_id) {
                    $live = DB::table('products')->where('id', $listing->product_id)->lockForUpdate()->first();
                    $draft['stock'] = $live->stock;
                    $product['stock'] = $live->stock;
                    DB::table('products')->where('id', $listing->product_id)->update($product);
                    $id = $listing->product_id;
                } else {
                    $id = DB::table('products')->insertGetId($product);
                }
                foreach (['Stitched', 'Unstitched'] as $type) {
                    $enabled = $type === $draft['type'];
                    DB::table('product_variants')->updateOrInsert(['product_id' => $id, 'type' => $type], [
                        'regular_price' => $data['retail_price'], 'sale_price' => null, 'sale_enabled' => false,
                        'stock' => $enabled ? $draft['stock'] : 0, 'enabled' => $enabled,
                    ]);
                }
                MediaLibrary::sync($id, $draft['images'], null);
                StoreCatalog::syncStock($id);
                $values['product_id'] = $id;
            } elseif ($listing->product_id && in_array($status, ['Rejected', 'Unpublished'])) {
                DB::table('products')->where('id', $listing->product_id)->update(['active' => false, 'publication_status' => 'archived']);
            }
            DB::table('vendor_listings')->where('id', $listing->id)->update($values);

            return response()->json(['ok' => true]);
        }, 3);
    }
}
