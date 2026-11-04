<?php

namespace App\Http\Controllers;

use App\Models\ShopUser;
use App\Support\StoreAccess;
use App\Support\StoreCatalog;
use App\Support\BusinessSettings;
use App\Support\BusinessAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class StoreApiController extends Controller
{
    private function payments(): array
    {
        $s = DB::table('store_settings')->find(1);
        return ['cod_enabled' => (bool) ($s->cod_enabled ?? true), 'bank_enabled' => (bool) ($s->bank_enabled ?? false), 'card_enabled' => false, 'card_ready' => false];
    }

    private function identity(): ?array
    {
        $u = Auth::user();
        return $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'permissions' => StoreAccess::permissions($u)] : null;
    }

    public function handle(Request $r, string $path)
    {
        $method = $r->method();
        if (Auth::check() && (! Auth::user()->active || $r->session()->get('auth_version') !== Auth::user()->auth_version)) {
            Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        }
        if ($path === 'catalog' && $method === 'GET') return response()->json(['products' => StoreCatalog::products(), 'categories' => DB::table('categories')->pluck('name'), 'brands' => DB::table('brands')->pluck('name'), 'stitching_fields' => \App\Support\StitchingMeasurements::fields(), 'demo' => false, 'payments' => $this->payments(), 'business' => BusinessSettings::get()]);
        if ($path === 'auth' && $method === 'GET') return response()->json(['user' => $this->identity(), 'google_enabled' => false]);
        if ($path === 'settings' && $method === 'GET') return response()->json(['payments' => $this->payments(), 'business' => BusinessSettings::get(), 'google_enabled' => false]);
        if ($path === 'auth/register') abort(404);
        if ($path === 'auth/login' && $method === 'POST') {
            $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
            $r->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:128']);
            $key = 'login:'.hash('sha256', $r->email.'|'.$r->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Too many attempts. Try again later.');
            RateLimiter::hit($key, 900);
            $u = ShopUser::where('email', $r->email)->first();
            if (! $u || ! $u->active || ! in_array($u->role, StoreAccess::ROLES) || ! Hash::check($r->password, $u->password_hash ?? '')) return response()->json(['error' => 'Email or password is incorrect.'], 401);
            DB::table('users')->where('id', $u->id)->update(['last_login_at' => now()]);
            Auth::login($u); $r->session()->regenerate(); $r->session()->put('auth_version', $u->auth_version); RateLimiter::clear($key);
            return response()->json(['user' => $this->identity(), 'csrf_token' => csrf_token()]);
        }
        if ($path === 'auth/logout' && $method === 'POST') {
            Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
            return response()->json(['ok' => true, 'csrf_token' => csrf_token()]);
        }
        if ($path === 'orders' && $method === 'POST') return app(GuestOrderController::class)->place($r, $this->payments());
        if ($path === 'orders' && $method === 'GET') {
            abort_unless(Auth::check(), 401);
            return response()->json(['orders' => DB::table('orders')->where('user_id', Auth::id())->orderByDesc('id')->get()]);
        }
        if ($path === 'track' && $method === 'POST') return app(GuestOrderController::class)->track($r);
        if (! str_starts_with($path, 'admin/')) abort(404);
        abort_unless(Auth::check() && Auth::user()->active && in_array(Auth::user()->role, StoreAccess::ROLES), 403, 'Staff access required.');
        $can = fn ($p) => StoreAccess::allows(Auth::user(), $p);
        if ($path === 'admin/data' && $method === 'GET') {
            return response()->json([
                'products' => $can('products.view') ? StoreCatalog::products(false, $can('costs.manage')) : [],
                'orders' => $can('orders.view') ? DB::table('orders')->orderByDesc('id')->limit(500)->get()->map(fn ($o) => collect($o)->except(['checkout_token', 'request_hash'])->all()) : [],
                'categories' => ($can('products.view') || $can('taxonomy.manage')) ? DB::table('categories')->get() : [],
                'brands' => ($can('products.view') || $can('taxonomy.manage')) ? DB::table('brands')->get() : [],
                'customers' => $can('customers.manage') ? DB::table('orders')->selectRaw('email, MAX(name) name, MAX(phone) phone, COUNT(*) order_count, MIN(created_at) created_at')->groupBy('email')->get() : [],
                'stats' => $can('orders.view') ? DB::table('orders')->selectRaw("COUNT(*) total_orders, COALESCE(SUM(status='Pending'),0) pending_orders, COALESCE(SUM(COALESCE(amount_paid,0)-refund_amount),0) revenue")->first() : null,
                'filter_options' => ['cities' => ($can('analytics.view') || $can('customers.manage')) ? DB::table('orders')->distinct()->orderBy('city')->pluck('city') : []],
                'payments' => $this->payments(), 'permissions' => StoreAccess::permissions(Auth::user()), 'google_enabled' => false,
            ]);
        }
        if ($path === 'admin/order-search' && $method === 'GET') {
            abort_unless($can('orders.view'), 403); $f = BusinessAnalytics::validate($r); $rows = BusinessAnalytics::orders($f)->sortByDesc('created_at')->values(); $page = (int) ($f['page'] ?? 1);
            return response()->json(['orders' => $rows->slice(($page-1)*20,20)->map(fn ($o) => collect($o)->except(['checkout_token','request_hash'])->all())->values(), 'total' => $rows->count(), 'page' => $page, 'pages' => max(1,(int)ceil($rows->count()/20))]);
        }
        if ($path === 'admin/business-settings') { abort_unless($can('settings.manage'), 403); return app(BusinessController::class)->settings($r); }
        if ($path === 'admin/analytics' && $method === 'GET') { abort_unless($can('analytics.view'), 403); return response()->json(BusinessAnalytics::dashboard(BusinessAnalytics::validate($r), $can('costs.manage'))); }
        if ($path === 'admin/customers') { abort_unless($can('customers.manage'), 403); return app(CustomerInsightsController::class)->handle($r); }
        if ($path === 'admin/shipment' && $method === 'PUT') { abort_unless($can('orders.update'), 403); return app(BusinessController::class)->shipment($r); }
        if ($path === 'admin/users') return app(StaffUserController::class)->handle($r);
        if ($path === 'admin/media' && $method === 'POST') {
            abort_unless($can('products.create') || $can('products.edit'), 403);
            return app(ProductMediaController::class)->upload($r);
        }
        if ($path === 'admin/settings' && $method === 'PUT') {
            abort_unless($can('settings.manage'), 403);
            $v = $r->validate(['cod_enabled' => 'required|boolean', 'bank_enabled' => 'required|boolean', 'card_enabled' => 'required|boolean']);
            abort_if($v['card_enabled'], 422, 'Card payments require an integrated gateway.');
            abort_if(! $v['cod_enabled'] && ! $v['bank_enabled'], 422, 'Keep at least one payment method enabled.');
            DB::table('store_settings')->where('id', 1)->update($v);
            return response()->json(['payments' => $this->payments()]);
        }
        if ($path === 'admin/products' && in_array($method, ['POST', 'PUT'])) return $this->saveProduct($r, $can);
        if ($path === 'admin/products' && $method === 'DELETE') {
            abort_unless($can('products.delete'), 403);
            $r->validate(['id' => 'required|integer|exists:products,id']);
            DB::table('products')->where('id', $r->id)->update(['active' => DB::raw('NOT active')]);
            return response()->json(['ok' => true]);
        }
        if (in_array($path, ['admin/categories', 'admin/brands'])) {
            abort_unless($can('taxonomy.manage'), 403);
            $table = explode('/', $path)[1];
            if ($method === 'POST') {
                $v = $r->validate(['name' => "required|string|max:100|unique:$table,name"]); DB::table($table)->insert($v);
                return response()->json(['ok' => true]);
            }
            if ($method === 'PUT') {
                $v = $r->validate(['id' => "required|integer|exists:$table,id", 'name' => ['required', 'string', 'max:100', Rule::unique($table)->ignore($r->id)]]);
                return DB::transaction(function () use ($v, $table) { $old = DB::table($table)->where('id', $v['id'])->lockForUpdate()->first(); DB::table($table)->where('id', $old->id)->update(['name' => $v['name']]); DB::table('products')->where($table === 'brands' ? 'brand' : 'category', $old->name)->update([$table === 'brands' ? 'brand' : 'category' => $v['name']]); return response()->json(['ok' => true]); });
            }
            if ($method === 'DELETE') {
                $r->validate(['id' => "required|integer|exists:$table,id"]);
                $name = DB::table($table)->where('id', $r->id)->value('name');
                abort_if(DB::table('products')->where($table === 'brands' ? 'brand' : 'category', $name)->exists(), 409, 'Move associated products first.');
                DB::table($table)->where('id', $r->id)->delete(); return response()->json(['ok' => true]);
            }
        }
        if ($path === 'admin/order-items' && $method === 'GET') {
            abort_unless($can('orders.view'), 403); $r->validate(['id' => 'required|integer|exists:orders,id']);
            return response()->json(['items' => DB::table('order_items')->where('order_id', $r->id)->get()->map(function ($i) use ($can) { if (! $can('costs.manage')) unset($i->unit_cost); return $i; })]);
        }
        if ($path === 'admin/orders' && $method === 'PUT') {
            abort_unless($can('orders.update'), 403);
            $states = ['Pending', 'Confirmed', 'Processing', 'Packed', 'Shipped', 'Out for Delivery', 'Delivered'];
            $v = $r->validate(['id' => 'required|integer|exists:orders,id', 'status' => ['required', Rule::in([...$states, 'Cancelled', 'Returned'])]]);
            return DB::transaction(function () use ($v, $states) {
                $o = DB::table('orders')->where('id', $v['id'])->lockForUpdate()->first();
                if ($o->status === $v['status']) return response()->json(['ok' => true]);
                if ($o->status === 'Delivered' && $v['status'] === 'Returned') { $history = json_decode($o->status_history ?? '[]', true); $history[] = ['status' => 'Returned', 'at' => now()->toIso8601String()]; DB::table('orders')->where('id', $o->id)->update(['status' => 'Returned', 'status_history' => json_encode($history)]); \App\Support\OrderEmails::record($o->id, 'status'); return response()->json(['ok' => true]); }
                abort_if(in_array($o->status, ['Delivered', 'Cancelled', 'Returned']), 409, 'This order is final.');
                if ($v['status'] === 'Cancelled') {
                    abort_if(in_array($o->status, ['Shipped', 'Out for Delivery']), 409, 'Dispatched orders cannot be cancelled here.');
                    foreach (DB::table('order_items')->where('order_id', $o->id)->orderBy('product_id')->get() as $i) {
                        if ($i->stock_variant_id ?? $i->variant_id) DB::table('product_variants')->where('id', $i->stock_variant_id ?? $i->variant_id)->increment('stock', $i->quantity);
                        StoreCatalog::syncStock($i->product_id);
                    }
                } else abort_unless(array_search($v['status'], $states) === array_search($o->status, $states) + 1, 409, 'Move to the next delivery status in sequence.');
                $history = json_decode($o->status_history ?? '[]', true); $history[] = ['status' => $v['status'], 'at' => now()->toIso8601String()];
                DB::table('orders')->where('id', $o->id)->update(['status' => $v['status'], 'status_history' => json_encode($history)]);
                \App\Support\OrderEmails::record($o->id, 'status');
                return response()->json(['ok' => true]);
            }, 3);
        }
        abort(404);
    }

    private function saveProduct(Request $r, $can)
    {
        $creating = $r->isMethod('POST');
        abort_unless($can($creating ? 'products.create' : 'products.edit'), 403);
        $imageRule = ['string', 'max:500', 'regex:~^(?:/images/[a-zA-Z0-9_.-]+|/media/products/[a-f0-9]{32}\.webp|https://[^\s]+)$~'];
        $v = $r->validate([
            'id' => $creating ? 'prohibited' : 'required|integer|exists:products,id', 'name' => 'required|string|max:150',
            'category' => 'required|string|exists:categories,name', 'brand' => 'required|string|exists:brands,name',
            'price' => 'required|numeric|decimal:0,2|min:1|max:10000000', 'compare_price' => 'nullable|numeric|decimal:0,2|min:0|max:10000000', 'stock' => 'required|integer|min:0|max:1000000',
            'color' => 'nullable|string|max:50', 'fabric' => 'nullable|string|max:100', 'badge' => 'nullable|string|max:50', 'description' => 'nullable|string|max:10000',
            'image' => ['required', ...$imageRule], 'images' => 'sometimes|array|min:1|max:4', 'images.*' => $imageRule, 'featured' => 'sometimes|boolean',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('products')->ignore($r->id)], 'stitching_enabled' => 'sometimes|boolean', 'stitching_service' => 'sometimes|in:blouse,saree,blouse_saree', 'stitching_stock_mode' => 'sometimes|in:shared,separate', 'stitching_charge' => 'nullable|numeric|decimal:0,2|min:0|max:10000000',
            'cost_price' => 'nullable|numeric|decimal:0,2|min:0|max:10000000', 'stitching_cost' => 'nullable|numeric|decimal:0,2|min:0|max:10000000', 'additional_cost' => 'nullable|numeric|decimal:0,2|min:0|max:10000000',
            'shipping_info' => 'nullable|string|max:1000', 'seo_title' => 'nullable|string|max:160', 'seo_description' => 'nullable|string|max:320',
            'variants' => 'sometimes|array|min:1|max:2', 'variants.*.type' => 'required|distinct|in:Stitched,Unstitched',
            'variants.*.regular_price' => 'required|numeric|decimal:0,2|min:1|max:10000000', 'variants.*.sale_price' => 'nullable|numeric|decimal:0,2|min:1|max:10000000',
            'variants.*.sale_enabled' => 'required|boolean', 'variants.*.stock' => 'required|integer|min:0|max:1000000', 'variants.*.enabled' => 'required|boolean',
        ]);
        return DB::transaction(function () use ($v, $creating, $can) {
            foreach (['cost_price', 'stitching_cost', 'additional_cost'] as $field) abort_if(array_key_exists($field, $v) && ! $can('costs.manage'), 403, 'Cost permission required.');
            $old = $creating ? null : DB::table('products')->where('id', $v['id'])->lockForUpdate()->first();
            foreach (['stitching_charge', 'stitching_enabled', 'stitching_stock_mode', 'stitching_service'] as $field) {
                $before = $old?->$field ?? match ($field) { 'stitching_enabled' => true, 'stitching_stock_mode' => 'shared', 'stitching_service' => 'blouse', default => null };
                $changed = array_key_exists($field, $v) && ($field === 'stitching_enabled' ? (bool) $v[$field] !== (bool) $before : $v[$field] != $before);
                abort_if($changed && ! $can('sales.manage'), 403, 'Stitching configuration permission required.');
            }
            $variants = $v['variants'] ?? null;
            if ($creating && ! $variants) {
                $sale = ($v['compare_price'] ?? 0) > $v['price'];
                $variants = [['type' => 'Unstitched', 'regular_price' => $sale ? $v['compare_price'] : $v['price'], 'sale_price' => $sale ? $v['price'] : null, 'sale_enabled' => $sale, 'stock' => $v['stock'], 'enabled' => true], ['type' => 'Stitched', 'regular_price' => $v['price'], 'sale_price' => null, 'sale_enabled' => false, 'stock' => 0, 'enabled' => false]];
            }
            if (! $creating && ! $variants) abort(422, 'Save variation prices and stock with this product.');
            // Catalog objects also contain computed fields and IDs; never write those back.
            $variants = array_map(fn ($row) => [...collect($row)->only(['type', 'regular_price', 'sale_enabled', 'stock', 'enabled'])->all(), 'sale_price' => $row['sale_price'] ?? null], $variants);
            abort_unless(collect($variants)->contains(fn ($v) => $v['enabled']), 422, 'Enable at least one variation.');
            foreach ($variants as $variant) {
                abort_if($variant['sale_enabled'] && (! $variant['sale_price'] || $variant['sale_price'] >= $variant['regular_price']), 422, 'Sale price must be below the regular price.');
                abort_if($creating && ! $can('stock.manage') && $variant['stock'] > 0, 403, 'Stock permission required.');
                abort_if($creating && ! $can('sales.manage') && $variant['sale_enabled'], 403, 'Sale permission required.');
                if (! $creating) {
                    $before = DB::table('product_variants')->where('product_id', $old->id)->where('type', $variant['type'])->first();
                    abort_if(! $can('stock.manage') && (! $before || (int) $before->stock !== (int) $variant['stock'] || (bool) $before->enabled !== (bool) $variant['enabled']), 403, 'Stock permission required.');
                    abort_if(! $can('sales.manage') && (! $before || (float) $before->regular_price !== (float) $variant['regular_price'] || (float) $before->sale_price !== (float) $variant['sale_price'] || (bool) $before->sale_enabled !== (bool) $variant['sale_enabled']), 403, 'Sale pricing permission required.');
                }
            }
            abort_if(! $can('sales.manage') && (bool) ($v['featured'] ?? false) !== (bool) ($old->featured ?? false), 403, 'Merchandising permission required.');
            if (! $creating) {
                $types = DB::table('product_variants')->where('product_id', $old->id)->pluck('type')->sort()->values()->all();
                abort_unless(collect($variants)->pluck('type')->sort()->values()->all() === $types, 422, 'Keep both variation rows; disable an option instead of removing it.');
            }
            $fields = collect($v)->except(['id', 'variants', 'images'])->all();
            if (isset($v['images'])) { $fields['images'] = json_encode(array_values($v['images'])); $fields['image'] = $v['images'][0]; }
            if ($creating) $id = DB::table('products')->insertGetId($fields);
            else { $id = $old->id; DB::table('products')->where('id', $id)->update($fields); }
            foreach ($variants as $variant) DB::table('product_variants')->updateOrInsert(['product_id' => $id, 'type' => $variant['type']], $variant);
            if ($creating && empty($fields['sku'])) DB::table('products')->where('id', $id)->update(['sku' => 'RM-SR-'.str_pad($id, 6, '0', STR_PAD_LEFT)]);
            StoreCatalog::syncStock($id);
            return response()->json(['ok' => true, 'id' => $id]);
        });
    }
}
