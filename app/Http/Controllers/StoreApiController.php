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
        if ($path === 'catalog' && $method === 'GET') return response()->json(['products' => StoreCatalog::products(), 'categories' => DB::table('categories')->pluck('name'), 'brands' => DB::table('brands')->pluck('name'), 'size_fields'=>\App\Support\SizeGuide::fields(), 'size_reference'=>\App\Support\SizeGuide::reference(), 'demo' => false, 'payments' => $this->payments(), 'business' => BusinessSettings::get()]);
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
                'business'=>BusinessSettings::get(),
                'products' => $can('products.view') ? StoreCatalog::products(false, $can('costs.manage')) : [],
                'orders' => $can('orders.view') ? DB::table('orders')->orderByDesc('id')->limit(500)->get()->map(fn ($o) => collect($o)->except(['checkout_token', 'request_hash'])->all()) : [],
                'categories' => ($can('products.view') || $can('products.create') || $can('products.edit') || $can('taxonomy.manage')) ? DB::table('categories')->get() : [],
                'brands' => ($can('products.view') || $can('products.create') || $can('products.edit') || $can('taxonomy.manage')) ? DB::table('brands')->get() : [],
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
        if($path==='admin/media'&&$method==='GET'){abort_unless($can('products.view'),403);return app(ProductMediaController::class)->index();}
        if ($path === 'admin/media' && $method === 'POST') {
            abort_unless($can('products.create') || $can('products.edit') || $can('taxonomy.manage'), 403);
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
        if ($path === 'admin/products' && in_array($method, ['POST', 'PUT'])) return app(ProductAdminController::class)->save($r);
        if ($path === 'admin/products' && $method === 'DELETE') {
            abort_unless($can('products.delete'), 403);
            $r->validate(['id' => 'required|integer|exists:products,id']);
            $p=DB::table('products')->find($r->id);abort_if($p->publication_status==='draft',422,'Publish a draft through the product editor.');DB::table('products')->where('id',$p->id)->update(['active'=>!$p->active,'publication_status'=>$p->active?'archived':'published']);
            return response()->json(['ok' => true]);
        }
        if (in_array($path, ['admin/categories', 'admin/brands'])) {
            abort_unless($can('taxonomy.manage'), 403);
            $table = explode('/', $path)[1];
            if($r->has('name'))$r->merge(['name'=>trim($r->name)]);
            $imageRules=['nullable','string','max:500','regex:~^(?:/images/[a-zA-Z0-9_.-]+|/media/products/[a-f0-9]{32}\.webp|https://[^\s]+)$~'];
            if ($method === 'POST') {
                $v = $r->validate(['name' => "required|string|max:100|unique:$table,name",'image'=>$table==='categories'?$imageRules:'prohibited']); $id=DB::table($table)->insertGetId($v);
                return response()->json(['ok' => true]);
            }
            if ($method === 'PUT') {
                $v = $r->validate(['id' => "required|integer|exists:$table,id", 'name' => ['required', 'string', 'max:100', Rule::unique($table)->ignore($r->id)],'image'=>$table==='categories'?$imageRules:'prohibited']);
                return DB::transaction(function () use ($v, $table) { $old = DB::table($table)->where('id', $v['id'])->lockForUpdate()->first(); DB::table($table)->where('id', $old->id)->update(collect($v)->except('id')->all()); DB::table('products')->where($table === 'brands' ? 'brand' : 'category', $old->name)->update([$table === 'brands' ? 'brand' : 'category' => $v['name']]); return response()->json(['ok' => true]); });
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
        if($path==='admin/orders'&&$method==='PUT'){abort_unless($can('orders.update'),403);return app(OrderFulfillmentController::class)->update($r);}
        if($path==='admin/order-notifications'&&$method==='GET'){abort_unless($can('orders.view'),403);return app(OrderFulfillmentController::class)->notifications($r);}
        abort(404);
    }

}
