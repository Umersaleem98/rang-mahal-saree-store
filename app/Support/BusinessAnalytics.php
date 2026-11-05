<?php
namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessAnalytics
{
    public static function validate(Request $r): array
    {
        $v = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'city' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100', 'brand' => 'nullable|string|max:100', 'product' => 'nullable|string|max:150', 'customer' => 'nullable|string|max:190', 'customer_category' => 'nullable|string|max:50', 'customer_status' => 'nullable|string|max:20', 'order_status' => 'nullable|string|max:32', 'payment_method' => 'nullable|string|max:20', 'payment_status' => 'nullable|string|max:32', 'delivery_status' => 'nullable|string|max:32', 'variant' => 'nullable|in:Stitched,Unstitched', 'q' => 'nullable|string|max:190', 'min_spending' => 'nullable|numeric|min:0', 'min_orders' => 'nullable|integer|min:0', 'registered_from' => 'nullable|date_format:Y-m-d', 'registered_to' => 'nullable|date_format:Y-m-d', 'last_order_from' => 'nullable|date_format:Y-m-d', 'last_order_to' => 'nullable|date_format:Y-m-d', 'stock_status' => 'nullable|in:In stock,Low stock,Out of stock', 'page' => 'nullable|integer|min:1', 'email' => 'nullable|email']);
        abort_if(!empty($v['from']) && !empty($v['to']) && $v['to'] < $v['from'], 422, 'The end date must be on or after the start date.');
        return $v;
    }
    public static function orders(array $f)
    {
        $q = DB::table('orders');
        if (! empty($f['from'])) $q->where('created_at', '>=', $f['from'].' 00:00:00');
        if (! empty($f['to'])) $q->where('created_at', '<=', $f['to'].' 23:59:59');
        foreach (['city', 'payment_method', 'payment_status'] as $k) if (! empty($f[$k])) $q->where($k, $f[$k]);
        foreach (['order_status', 'delivery_status'] as $k) if (! empty($f[$k])) $q->where('status', $f[$k]);
        if (! empty($f['q'])) $q->where(fn ($x) => $x->where('reference', 'like', '%'.$f['q'].'%')->orWhere('name', 'like', '%'.$f['q'].'%')->orWhere('email', 'like', '%'.$f['q'].'%')->orWhere('phone', 'like', '%'.$f['q'].'%'));
        if (! empty($f['customer'])) $q->where(fn ($x) => $x->where('name', 'like', '%'.$f['customer'].'%')->orWhere('email', 'like', '%'.$f['customer'].'%')->orWhere('phone', 'like', '%'.$f['customer'].'%'));
        if (! empty($f['customer_category']) || ! empty($f['customer_status'])) {
            $profiles = DB::table('customer_profiles');
            if (! empty($f['customer_category'])) $profiles->where('category', $f['customer_category']);
            if (! empty($f['customer_status'])) $profiles->where('status', $f['customer_status']);
            $emails = $profiles->pluck('email');
            $standard = ($f['customer_category'] ?? 'Standard') === 'Standard' && ($f['customer_status'] ?? 'Active') === 'Active';
            $q->where(function ($x) use ($emails, $standard) { $x->whereIn('email', $emails); if ($standard) $x->orWhereNotIn('email', DB::table('customer_profiles')->select('email')); });
        }
        if (array_filter(collect($f)->only(['category', 'brand', 'product', 'variant'])->all())) {
            $items = DB::table('order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->select('i.order_id');
            foreach (['category', 'brand'] as $k) if (! empty($f[$k])) $items->whereRaw("COALESCE(i.{$k}_snapshot,p.$k) = ?", [$f[$k]]);
            if (! empty($f['product'])) $items->where(fn ($x) => $x->where('i.name', 'like', '%'.$f['product'].'%')->orWhere('p.sku', 'like', '%'.$f['product'].'%'));
            if (! empty($f['variant'])) $items->where('i.variant', $f['variant']);
            $q->whereIn('id', $items);
        }
        return $q->orderBy('created_at')->get();
    }
    public static function revenue($o): float { return round((float) ($o->amount_paid ?? 0) - (float) $o->refund_amount, 2); }
    private static function groups($rows, callable $key, callable $value): array
    {
        return $rows->groupBy($key)->map(fn ($g, $k) => ['label' => (string) $k, 'value' => round($g->sum($value), 2), 'orders' => $g->count()])->sortByDesc('value')->values()->all();
    }
    public static function dashboard(array $f, bool $costAccess): array
    {
        $orders = self::orders($f); $ids = $orders->pluck('id'); $byId = $orders->keyBy('id');
        $items = DB::table('order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->whereIn('i.order_id', $ids)->select('i.*', 'p.sku', 'p.category', 'p.brand')->get();
        foreach (['category', 'brand'] as $field) if (! empty($f[$field])) $items = $items->filter(fn ($i) => ($i->{$field.'_snapshot'} ?? $i->$field) === $f[$field]);
        if (! empty($f['variant'])) $items = $items->where('variant', $f['variant']);
        if (! empty($f['product'])) $items = $items->filter(fn ($i) => str_contains(strtolower($i->name.' '.$i->sku), strtolower($f['product'])));
        // Financial attribution uses stored payment amounts; unknown or partial payments are never assumed to be full revenue.
        $itemRevenue = fn ($i) => (float) $i->price * $i->quantity * ($byId[$i->order_id]->total > 0 ? self::revenue($byId[$i->order_id]) / $byId[$i->order_id]->total : 0);
        $paidItems = $items->filter(fn ($i) => ($byId[$i->order_id]->amount_paid ?? 0) > 0);
        $costKnown = $paidItems->isNotEmpty() && $paidItems->every(fn ($i) => $i->unit_cost !== null && (float) $byId[$i->order_id]->amount_paid === (float) $byId[$i->order_id]->total && (float) $byId[$i->order_id]->refund_amount === 0.0 && $byId[$i->order_id]->status !== 'Returned');
        $netSales = round($paidItems->sum($itemRevenue), 2); $cost = $costKnown ? round($paidItems->sum(fn ($i) => $i->unit_cost * $i->quantity), 2) : null;
        $profit = $costAccess && $costKnown ? round($netSales - $cost, 2) : null;
        $products = StoreCatalog::products(false, $costAccess)->filter(fn ($p) => (empty($f['category']) || $p->category === $f['category']) && (empty($f['brand']) || $p->brand === $f['brand']) && (empty($f['product']) || str_contains(strtolower($p->name.' '.$p->sku), strtolower($f['product']))));
        $productRows = $products->map(function ($p) use ($items, $paidItems, $itemRevenue, $costAccess, $byId) {
            $lines = $items->where('product_id', $p->id); $paid = $paidItems->where('product_id', $p->id); $revenue = round($paid->sum($itemRevenue), 2);
            $sold = $lines->filter(fn ($i) => $byId[$i->order_id]->status === 'Delivered')->sum('quantity');
            return ['name' => $p->name, 'sku' => $p->sku, 'category' => $p->category, 'brand' => $p->brand, 'units' => $sold, 'revenue' => $revenue, 'orders' => $lines->pluck('order_id')->unique()->count(), 'average_price' => $sold ? round($lines->filter(fn ($i) => $byId[$i->order_id]->status === 'Delivered')->sum(fn ($i) => $i->price * $i->quantity) / $sold, 2) : null, 'stock' => $p->stock, 'low_stock' => $p->variants->contains(fn ($v) => $v->enabled && $v->stock!==null && $v->stock <= $p->low_stock_threshold)];
        })->sortByDesc('revenue')->values();
        $unitRanks = $productRows->pluck('units')->filter(fn ($n) => $n > 0)->unique()->sortDesc()->values();
        $productRows = $productRows->map(fn ($p) => [...$p, 'rank' => $p['units'] > 0 ? $unitRanks->search($p['units']) + 1 : null]);
        $inventoryRows = $productRows->filter(fn ($p) => empty($f['stock_status']) || match ($f['stock_status']) { 'In stock' => $p['stock'] > 0, 'Low stock' => $p['low_stock'] && $p['stock'] > 0, 'Out of stock' => $p['stock'] === 0 });
        $cities = $orders->groupBy('city')->map(function ($g, $city) use ($items) { $cityItems = $items->whereIn('order_id', $g->where('status', 'Delivered')->pluck('id')); $best = $cityItems->groupBy('name')->sortByDesc(fn ($x) => $x->sum('quantity'))->keys()->first(); return ['label' => $city, 'value' => round($g->sum(fn ($o) => self::revenue($o)), 2), 'orders' => $g->count(), 'aov' => $g->count() ? round($g->sum('total') / $g->count(), 2) : 0, 'customers' => $g->pluck('email')->unique()->count(), 'best_product' => $best]; })->sortByDesc('orders')->values();
        $customers = self::customerRows($f);
        $time = [];
        foreach (['daily' => 'Y-m-d', 'weekly' => 'o-\\WW', 'monthly' => 'Y-m', 'yearly' => 'Y'] as $name => $format) $time[$name] = collect(self::groups($orders, fn ($o) => Carbon::parse($o->created_at)->format($format), fn ($o) => self::revenue($o)))->sortBy('label')->values();
        $previous = null;
        if (! empty($f['from']) && ! empty($f['to'])) { $days = Carbon::parse($f['from'])->diffInDays(Carbon::parse($f['to'])) + 1; $prev = self::orders([...$f, 'from' => Carbon::parse($f['from'])->subDays($days)->toDateString(), 'to' => Carbon::parse($f['from'])->subDay()->toDateString()]); if ($prev->isNotEmpty()) $previous = ['revenue' => round($prev->sum(fn ($o) => self::revenue($o)), 2), 'orders' => $prev->count(), 'aov' => round($prev->sum('total') / $prev->count(), 2)]; }
        $payments = collect(self::groups($orders, fn ($o) => $o->payment_method, fn ($o) => self::revenue($o)))->map(fn ($row) => [...$row, 'order_percent' => $orders->count() ? round($row['orders'] / $orders->count() * 100, 1) : 0, 'revenue_percent' => $orders->sum(fn ($o) => self::revenue($o)) > 0 ? round($row['value'] / $orders->sum(fn ($o) => self::revenue($o)) * 100, 1) : 0]);
        $profitGroups = function (callable $key) use ($paidItems, $itemRevenue, $byId, $costAccess) { return $paidItems->groupBy($key)->map(function ($g, $label) use ($itemRevenue, $byId, $costAccess) { $known = $costAccess && $g->every(fn ($i) => $i->unit_cost !== null && (float) $byId[$i->order_id]->amount_paid === (float) $byId[$i->order_id]->total && (float) $byId[$i->order_id]->refund_amount === 0.0 && $byId[$i->order_id]->status !== 'Returned'); $sales = $g->sum($itemRevenue); $cost = $known ? $g->sum(fn ($i) => $i->unit_cost * $i->quantity) : null; return ['label' => $label, 'sales' => round($sales, 2), 'cost' => $cost, 'profit' => $known ? round($sales - $cost, 2) : null]; })->values(); };
        return ['filter_options' => ['cities' => DB::table('orders')->distinct()->orderBy('city')->pluck('city')], 'kpis' => ['revenue' => round($orders->sum(fn ($o) => self::revenue($o)), 2), 'orders' => $orders->count(), 'aov' => $orders->count() ? round($orders->sum('total') / $orders->count(), 2) : null, 'customers' => $customers->count(), 'new_customers' => $customers->where('order_count', '<=', 1)->count(), 'returning_customers' => $customers->where('order_count', '>', 1)->count(), 'products' => $products->count(), 'low_stock' => $productRows->where('low_stock', true)->count(), 'best_product' => $productRows->sortByDesc('units')->firstWhere('units', '>', 0)['name'] ?? null, 'best_city' => $cities->first()['label'] ?? null, 'unrecorded_payments' => $orders->where('payment_status', 'Unrecorded')->count()],
            'previous' => $previous, 'trends' => $time, 'cities' => $cities, 'products' => $productRows,
            'statuses' => self::groups($orders, fn ($o) => $o->status, fn () => 1), 'payments' => $payments->values(), 'payment_statuses' => self::groups($orders, fn ($o) => $o->payment_status, fn () => 1),
            'categories' => self::groups($paidItems, fn ($i) => $i->category_snapshot ?? $i->category, $itemRevenue), 'variants' => self::groups($paidItems, fn ($i) => $i->variant ?? 'Unrecorded', $itemRevenue),
            'inventory' => ['units' => $inventoryRows->sum('stock'), 'out_of_stock' => $inventoryRows->whereStrict('stock', 0)->count(), 'low_stock' => $inventoryRows->where('low_stock', true)->count(), 'slow_moving' => $inventoryRows->where('units', 0)->values()],
            'profitability' => ['available' => $profit !== null, 'reason' => ! $costAccess ? 'Cost access restricted' : ($paidItems->isEmpty() ? 'No recorded paid orders' : (! $costKnown ? 'Cost Data Missing or refund/partial-payment accounting incomplete' : null)), 'net_sales' => $netSales, 'cost' => $costAccess ? $cost : null, 'gross_profit' => $profit, 'margin' => $profit !== null && $netSales > 0 ? round($profit / $netSales * 100, 2) : null, 'by_product' => $costAccess ? $profitGroups(fn ($i) => $i->name) : [], 'by_category' => $costAccess ? $profitGroups(fn ($i) => $i->category_snapshot ?? $i->category) : [], 'by_city' => $costAccess ? $profitGroups(fn ($i) => $byId[$i->order_id]->city) : []],
        ];
    }

    public static function customerRows(array $f)
    {
        $profiles = DB::table('customer_profiles')->get()->keyBy('email'); $registered = DB::table('users')->where('role', 'customer')->get()->keyBy('email');
        $orderFilters = $f; unset($orderFilters['q']);
        $allOrders = DB::table('orders')->orderBy('created_at')->get(); $matchedIds = self::orders($orderFilters)->pluck('id');
        $grouped = $allOrders->groupBy(fn ($o) => strtolower($o->email)); foreach ($registered as $email => $u) if (! $grouped->has($email)) $grouped[$email] = collect();
        $rows = $grouped->map(function ($g, $email) use ($profiles, $registered, $matchedIds, $f) {
            if ($g->isEmpty() && array_filter(collect($f)->only(['category','brand','product','variant','order_status','payment_status','payment_method','delivery_status'])->all())) return null;
            $last = $g->last(); $profile = $profiles[$email] ?? null; $user = $registered[$email] ?? null;
            if ($g->isNotEmpty() && ! $g->contains(fn ($o) => $matchedIds->contains($o->id))) return null;
            $r = ['name' => $last?->name ?? $user?->name, 'email' => $email, 'phone' => $last?->phone ?? '', 'city' => $last?->city ?? '', 'order_count' => $g->count(), 'spending' => round($g->sum(fn ($o) => self::revenue($o)), 2), 'order_value' => round($g->sum('total'), 2), 'last_order' => $last?->created_at, 'created_at' => $user?->created_at ?? $g->first()?->created_at, 'category' => $profile?->category ?? 'Standard', 'status' => $profile?->status ?? 'Active', 'notes' => $profile?->notes ?? '', 'completed' => $g->where('status', 'Delivered')->count(), 'pending' => $g->whereNotIn('status', ['Delivered', 'Cancelled', 'Returned'])->count()];
            if ($g->isEmpty() && ((! empty($f['from']) && (! $r['created_at'] || substr($r['created_at'],0,10) < $f['from'])) || (! empty($f['to']) && (! $r['created_at'] || substr($r['created_at'],0,10) > $f['to'])))) return null;
            if (! empty($f['q']) && ! str_contains(strtolower($r['name'].' '.$email), strtolower(trim($f['q']))) && (! preg_match('/\d/', $f['q']) || ! str_contains(preg_replace('/\D/', '', $r['phone']), preg_replace('/\D/', '', $f['q'])))) return null;
            foreach (['city', 'customer_category' => 'category', 'customer_status' => 'status'] as $key => $field) { if (is_int($key)) $key = $field; if (! empty($f[$key]) && $r[$field] !== $f[$key]) return null; }
            if ($r['spending'] < ($f['min_spending'] ?? 0) || $r['order_count'] < ($f['min_orders'] ?? 0)) return null;
            foreach (['registered' => 'created_at', 'last_order' => 'last_order'] as $prefix => $field) { if (! empty($f[$prefix.'_from']) && (! $r[$field] || substr($r[$field], 0, 10) < $f[$prefix.'_from'])) return null; if (! empty($f[$prefix.'_to']) && (! $r[$field] || substr($r[$field], 0, 10) > $f[$prefix.'_to'])) return null; }
            return $r;
        })->filter()->values();
        $phones = $rows->filter(fn ($r) => $r['phone'])->groupBy(fn ($r) => preg_replace('/^(?:0092|92)/', '0', preg_replace('/\D/', '', $r['phone'])));
        return $rows->map(fn ($r) => [...$r, 'possible_duplicate' => $r['phone'] && ($phones[preg_replace('/^(?:0092|92)/', '0', preg_replace('/\D/', '', $r['phone']))] ?? collect())->count() > 1]);
    }
}
