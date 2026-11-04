<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

class BusinessSettings
{
    public static function defaults(): array
    {
        return [
            'measurement_services' => StitchingMeasurements::services(),
            'instagram_url' => 'https://www.instagram.com/rangmahal.site?vrfl=MWl3MzFzc3I0a3k4dg==', 'facebook_url' => 'https://www.facebook.com/share/1EzwsDoDMD/', 'whatsapp_url' => '',
            'support_email' => '', 'brand_name' => 'Rang Mahal', 'urdu_brand_name' => 'رنگ محل', 'tagline' => 'Rooted in Tradition, Worn with Love.',
            'standard_delivery_charge' => 350, 'free_delivery_threshold' => 10000, 'default_stitching_charge' => 5000,
            'stitching_enabled' => true, 'order_prefix' => 'RM', 'order_category_code' => 'SR',
            'shipping_policy' => 'Delivery is available across Pakistan. Charges are calculated once at checkout. Dispatch and delivery estimates depend on your city and will be confirmed with your order.',
            'return_address' => 'Rang Mahal, NUST, Islamabad, Pakistan', 'return_city' => 'Islamabad', 'return_contact' => '',
            'return_eligibility_period' => '', 'product_condition_requirements' => '', 'return_shipping_responsibility' => '',
            'exchange_conditions' => 'Replacement is subject to inspection, availability and applicable conditions.',
            'refund_conditions' => 'Refund requests are assessed under applicable consumer rights and the circumstances of the order; no unconditional refund is promised.',
            'damaged_product_procedure' => 'Contact customer support with your order reference and details of the damaged or incorrect item before dispatching a return.',
            'return_policy' => 'If you wish to return an eligible product, you may send it back to us through any courier service at our designated return address.\n\nOnce we receive and inspect your returned product, we will arrange a replacement, subject to product availability and the applicable return and exchange conditions.\n\nPlease contact our customer support team before dispatching your return to ensure that your request is processed smoothly.',
        ];
    }
    public static function get(): array
    {
        return array_replace(self::defaults(), json_decode(DB::table('business_settings')->where('id', 1)->value('values') ?? '{}', true));
    }
    public static function shipping(float $subtotal, ?array $settings = null): float
    {
        $s = $settings ?? self::get();
        return round($subtotal, 2) > (float) $s['free_delivery_threshold'] ? 0 : (float) $s['standard_delivery_charge'];
    }
    public static function reference(): string
    {
        $s = self::get(); $date = now()->timezone(config('app.timezone'))->format('Ymd');
        $key = $s['order_prefix'].'-'.$s['order_category_code'].'-'.$date;
        DB::table('order_sequences')->insertOrIgnore(['key' => $key, 'value' => 0]);
        $row = DB::table('order_sequences')->where('key', $key)->lockForUpdate()->first();
        $next = $row->value + 1;
        DB::table('order_sequences')->where('key', $key)->update(['value' => $next]);
        return $key.'-'.str_pad($next, 6, '0', STR_PAD_LEFT);
    }
}
