<?php

namespace App\Http\Controllers;

use App\Support\StoreCatalog;
use App\Support\BusinessSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\OrderEmails;
use Illuminate\Support\Facades\RateLimiter;

class GuestOrderController extends Controller
{
    public function place(Request $r, array $payments)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $v = $r->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email|max:190',
            'phone' => ['required', 'regex:/^\+?[\d\s-]{10,20}$/'], 'address' => 'required|string|max:500',
            'city' => 'required|string|max:100', 'postal_code' => ['required', 'regex:/^[A-Za-z0-9 -]{3,15}$/'],
            'payment_method' => 'required|in:cod,bank', 'checkout_token' => 'required|uuid',
            'items' => 'required|array|min:1|max:50', 'items.*.id' => 'required|integer|min:1',
            'items.*.measurements' => 'nullable|array:unit,values,instructions,fit',
            'items.*.variant_id' => 'required|integer|min:1', 'items.*.quantity' => 'required|integer|min:1|max:20',
        ]);
        abort_unless($payments[$v['payment_method'].'_enabled'], 400, 'This payment option is currently disabled.');
        $lines = [];
        foreach ($v['items'] as $i) {
            $key = $i['variant_id'];
            if (isset($lines[$key]) && $lines[$key]['id'] !== $i['id']) abort(422, 'Invalid variation.');
            if (isset($lines[$key]) && ($lines[$key]['measurements'] ?? null) !== ($i['measurements'] ?? null)) abort(422, 'Use one measurement profile per product option; contact us for different fits.');
            $lines[$key] = ['measurements' => $i['measurements'] ?? null, 'id' => $i['id'], 'quantity' => ($lines[$key]['quantity'] ?? 0) + $i['quantity']];
            abort_if($lines[$key]['quantity'] > 20, 422, 'Maximum 20 pieces per variation.');
        }
        ksort($lines);
        $hashLines = array_map(fn ($line) => ['id' => $line['id'], 'quantity' => $line['quantity'], ...($line['measurements'] !== null ? ['measurements' => $line['measurements']] : [])], $lines);
        $hash = hash('sha256', json_encode([collect($v)->except(['items', 'checkout_token'])->all(), $hashLines]));
        $result = DB::transaction(function () use ($v, $lines, $hash) {
            // Lock product rows in a consistent order to serialize stock checks and retries.
            $products = DB::table('products')->whereIn('id', collect($lines)->pluck('id')->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $old = DB::table('orders')->where('checkout_token', $v['checkout_token'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Checkout details changed. Please start a new checkout.');
                return ['reference' => $old->reference, 'total' => (float) $old->total, 'payment_method' => $old->payment_method, 'shipping' => (float) $old->shipping, 'new' => false, 'items' => DB::table('order_items')->where('order_id', $old->id)->get(['name', 'variant', 'quantity', 'price', 'stitching_charge', 'measurement_data'])];
            }
            $items = []; $cents = 0; $reserved = []; $settings = BusinessSettings::get();
            foreach ($lines as $variantId => $line) {
                $p = $products[$line['id']] ?? null;
                $rows = DB::table('product_variants')->where('product_id', $line['id'])->orderBy('id')->lockForUpdate()->get();
                $variant = $p ? StoreCatalog::options($p, $rows, $settings)->firstWhere('id', $variantId) : null;
                abort_unless($p && $p->active && $variant && $variant->enabled, 409, 'This product option is unavailable.');
                $pool = $variant->stock_variant_id;
                $reserved[$pool] = ($reserved[$pool] ?? 0) + $line['quantity'];
                abort_if($variant->stock < $reserved[$pool], 409, 'Not enough stock. Please update your shopping bag.');
                $measurements = $variant->type === 'Stitched' ? \App\Support\StitchingMeasurements::normalize($line['measurements'], $p->stitching_service, $settings, $p->name) : null;
                abort_if($variant->type !== 'Stitched' && !empty($line['measurements']),422,'Measurements apply only to stitched products.');
                $price = $variant->price;
                $knownCost = $p->cost_price !== null && $p->additional_cost !== null && ($variant->type !== 'Stitched' || $p->stitching_cost !== null);
                $unitCost = $knownCost ? (float) $p->cost_price + (float) $p->additional_cost + ($variant->type === 'Stitched' ? (float) $p->stitching_cost : 0) : null;
                $cents += (int) round($price * 100) * $line['quantity'];
                $items[] = ['measurement_data' => $measurements ? json_encode($measurements) : null, 'product_id' => $p->id, 'variant_id' => $variant->id, 'stock_variant_id' => $pool, 'stitching_charge' => $variant->stitching_charge, 'unit_cost' => $unitCost, 'category_snapshot' => $p->category, 'brand_snapshot' => $p->brand, 'sku_snapshot' => $p->sku, 'variant' => $variant->type, 'name' => $p->name, 'price' => $price, 'quantity' => $line['quantity']];
            }
            $shipping = BusinessSettings::shipping($cents / 100, $settings);
            $reference = BusinessSettings::reference();
            $id = DB::table('orders')->insertGetId([...collect($v)->except('items')->all(), 'user_id' => null, 'created_at' => now(), 'payment_status' => 'Pending', 'status_history' => json_encode([['status' => 'Pending', 'at' => now()->toIso8601String()]]), 'request_hash' => $hash, 'reference' => $reference, 'total' => $cents / 100 + $shipping, 'shipping' => $shipping]);
            foreach ($items as $item) {
                DB::table('order_items')->insert([...$item, 'order_id' => $id]);
                DB::table('product_variants')->where('id', $item['stock_variant_id'])->decrement('stock', $item['quantity']);
            }
            foreach ($products as $p) StoreCatalog::syncStock($p->id);
            OrderEmails::record($id, 'placed');
            return ['reference' => $reference, 'total' => $cents / 100 + $shipping, 'payment_method' => $v['payment_method'], 'shipping' => $shipping, 'new' => true, 'items' => collect($items)->map(fn ($i) => collect($i)->only(['name', 'variant', 'quantity', 'price', 'stitching_charge', 'measurement_data'])->all())->values()];
        }, 3);
        unset($result['new']);
        return response()->json($result, 201);
    }

    public function track(Request $r)
    {
        $key = 'tracking:'.$r->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 20), 429, 'Too many tracking requests. Please try again shortly.');
        RateLimiter::hit($key, 60);
        $r->validate(['reference' => 'required|string|max:50']);
        $query = DB::table('orders')->where('reference', strtoupper(trim($r->reference)));
        $r->validate(['email' => 'required|email']);
        $query->where('email', strtolower(trim($r->email)));
        $o = $query->first(['id', 'reference', 'status', 'total', 'shipping', 'city', 'created_at', 'payment_method', 'payment_status', 'courier_name', 'courier_tracking_number', 'courier_tracking_url', 'estimated_delivery_date', 'status_history']);
        abort_unless($o, 404, 'No order matches that tracking ID.');
        $o->items = DB::table('order_items')->where('order_id', $o->id)->get(['name', 'variant', 'quantity', 'price', 'stitching_charge']);
        $o->status_history = json_decode($o->status_history ?? '[]', true);
        unset($o->id);
        return response()->json(['order' => $o]);
    }
}
