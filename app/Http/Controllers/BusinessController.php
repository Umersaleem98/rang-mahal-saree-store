<?php
namespace App\Http\Controllers;

use App\Support\BusinessSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    public function settings(Request $r)
    {
        if ($r->isMethod('GET')) return response()->json(['business' => BusinessSettings::get()]);
        $rules = [];
        foreach (BusinessSettings::defaults() as $key => $value) {
            $rules[$key] = is_bool($value) ? 'sometimes|boolean' : (is_int($value) ? 'sometimes|numeric|decimal:0,2|min:0|max:10000000' : 'sometimes|nullable|string|max:10000');
        }
        $rules['order_prefix'] = ['sometimes', 'required', 'regex:/^[A-Z]{2,6}$/'];
        $rules['order_category_code'] = ['sometimes', 'required', 'regex:/^[A-Z]{2,6}$/'];
        $rules['support_email'] = 'sometimes|nullable|email|max:190';
        $rules['brand_name'] = 'sometimes|required|string|max:100';
        $rules['tagline'] = 'sometimes|required|string|max:200';
        foreach (['instagram_url','facebook_url','whatsapp_url'] as $field) $rules[$field] = ['sometimes','nullable','url:https','max:500'];
        $rules['measurement_services'] = 'sometimes|array:blouse,saree,blouse_saree';
        foreach (\App\Support\StitchingMeasurements::services() as $service=>$definition) {
            $rules["measurement_services.$service"] = 'required_with:measurement_services|array:label,fields,required';
            $rules["measurement_services.$service.label"] = 'required_with:measurement_services|string|max:80';
            $rules["measurement_services.$service.fields"] = 'required_with:measurement_services|array|min:1';
            $rules["measurement_services.$service.fields.*"] = ['string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\StitchingMeasurements::fields()))];
            $rules["measurement_services.$service.required"] = 'present_with:measurement_services|array';
            $rules["measurement_services.$service.required.*"] = ['string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\StitchingMeasurements::fields()))];
        }
        $rules['size_chart']='sometimes|array';
        $rules['max_product_images']='sometimes|integer|min:1|max:10';
        $rules['max_product_video_mb']='sometimes|integer|min:1|max:50';
        $v = $r->validate($rules);
        if(isset($v['size_chart']))$v['size_chart']=\App\Support\SizeGuide::validate($v['size_chart']);
        foreach ($v['measurement_services']??[] as $service) abort_if(count(array_diff($service['required'],$service['fields']))>0,422,'Required measurements must be enabled fields.');
        return DB::transaction(function () use ($v) {
            $row = DB::table('business_settings')->where('id', 1)->lockForUpdate()->first();
            $merged = array_replace(BusinessSettings::defaults(), json_decode($row->values, true), $v);
            DB::table('business_settings')->where('id', 1)->update(['values' => json_encode($merged)]);
            return response()->json(['business' => $merged]);
        });
    }

    public function shipment(Request $r)
    {
        $v = $r->validate([
            ...OrderFulfillmentController::deliveryRules(),
            'id' => 'required|integer|exists:orders,id', 'courier_name' => 'nullable|string|max:100', 'courier_tracking_number' => 'nullable|string|max:150',
            'courier_tracking_url' => ['nullable', 'url:https', 'max:500'], 'estimated_delivery_date' => 'nullable|date',
            'payment_status' => 'required|in:Unrecorded,Pending,Paid,Partially Paid,Partially Refunded,Refunded',
            'amount_paid' => 'nullable|numeric|decimal:0,2|min:0|max:100000000', 'refund_amount' => 'required|numeric|decimal:0,2|min:0|max:100000000',
        ]);
        return DB::transaction(function () use ($v) {
            $o = DB::table('orders')->where('id', $v['id'])->lockForUpdate()->first();
            $paid = $v['amount_paid'] ?? null; $refund = $v['refund_amount'];
            abort_if($paid !== null && $paid > $o->total, 422, 'Collected payment cannot exceed the order total.');
            abort_if($refund > ($paid ?? 0), 422, 'Refund cannot exceed the recorded payment.');
            abort_if($v['payment_status'] === 'Paid' && ((float) $paid !== (float) $o->total || $refund > 0), 422, 'Paid requires the full order payment and no refund.');
            abort_if($v['payment_status'] === 'Partially Paid' && (! $paid || $paid >= $o->total || $refund > 0), 422, 'Enter a partial payment below the total.');
            abort_if($v['payment_status'] === 'Refunded' && (! $paid || (float) $refund !== (float) $paid), 422, 'Enter the full recorded payment as refunded.');
            abort_if($v['payment_status'] === 'Partially Refunded' && (! $paid || ! $refund || $refund >= $paid), 422, 'Enter a refund below the recorded payment.');
            abort_if(in_array($v['payment_status'], ['Unrecorded', 'Pending']) && (($paid ?? 0) > 0 || $refund > 0), 422, 'Record the corresponding paid/refunded status for collected money.');
              $fields = OrderFulfillmentController::normalizeDelivery(collect($v)->except('id')->all(), $o);
            $fields['paid_at'] = $paid > 0 ? ($o->paid_at ?? now()) : null;
            $before=\App\Support\OrderEmails::snapshot($o);
            DB::table('orders')->where('id', $o->id)->update($fields);
            if ($before!==\App\Support\OrderEmails::snapshot(DB::table('orders')->find($o->id))) \App\Support\OrderEmails::record($o->id, 'details');
            return response()->json(['ok' => true]);
        });
    }
}
