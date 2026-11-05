<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\{BusinessSettings,OrderEmails,StoreCatalog};
class OrderFulfillmentController extends Controller {
 public static function deliveryRules():array {return ['delivery_method'=>'sometimes|in:courier,direct,collection','courier_name'=>'nullable|string|max:100','courier_tracking_number'=>'nullable|string|max:150','courier_tracking_url'=>'nullable|url:https|max:500','dispatched_at'=>'nullable|date','estimated_delivery_date'=>'nullable|date','delivery_notes'=>'nullable|string|max:1000'];}
 public static function normalizeDelivery(array $fields,object $order):array {
  if(($fields['delivery_method']??$order->delivery_method)!=='courier')foreach(['courier_name','courier_tracking_number','courier_tracking_url'] as $key)$fields[$key]=null;
  if(!empty($fields['dispatched_at'])){$date=\Carbon\Carbon::parse($fields['dispatched_at']);abort_if($date->greaterThan(now())||$date->lessThan(\Carbon\Carbon::parse($order->created_at)),422,'Dispatch date must fall between order placement and now.');abort_unless(in_array($fields['status']??$order->status,['Shipped','Out for Delivery','Delivered','Returned']),422,'Record dispatch when the order is actually dispatched.');$fields['dispatched_at']=$date;}
  return $fields;
 }
 public function update(Request $r){
  $states=['Pending','Confirmed','Processing','Packed','Shipped','Out for Delivery','Delivered'];$v=$r->validate(['id'=>'required|integer|exists:orders,id','status'=>['required',Rule::in([...$states,'Cancelled','Returned'])],...self::deliveryRules()]);
  return DB::transaction(function()use($v,$states){
   $o=DB::table('orders')->where('id',$v['id'])->lockForUpdate()->first();if($o->status===$v['status']){unset($o->checkout_token,$o->request_hash);return response()->json(['ok'=>true,'order'=>$o]);}
   $return=$o->status==='Delivered'&&$v['status']==='Returned';abort_if(!$return&&in_array($o->status,['Delivered','Cancelled','Returned']),409,'This order is final.');
   $changes=collect($v)->except(['id','status'])->all();$method=$changes['delivery_method']??$o->delivery_method;
   if($v['status']==='Cancelled'){
    abort_if(in_array($o->status,['Shipped','Out for Delivery']),409,'Dispatched orders cannot be cancelled here.');
    foreach(DB::table('order_items')->where('order_id',$o->id)->orderBy('product_id')->get() as $i){if($i->stock_reserved&&($i->stock_variant_id??$i->variant_id))DB::table('product_variants')->where('id',$i->stock_variant_id??$i->variant_id)->increment('stock',$i->quantity);StoreCatalog::syncStock($i->product_id);}
   }elseif(!$return)abort_unless(array_search($v['status'],$states)===array_search($o->status,$states)+1,409,'Move to the next delivery stage in sequence.');
   if($v['status']==='Shipped'){
    if($method==='courier'){abort_if(empty($changes['courier_name']??$o->courier_name),422,'Enter the courier name, or choose direct delivery / collection.');if(BusinessSettings::get()['courier_tracking_required'])abort_if(empty($changes['courier_tracking_number']??$o->courier_tracking_number),422,'A courier tracking number is required for dispatch.');}
    $changes['dispatched_at']=$changes['dispatched_at']??now();
   }
   $changes=self::normalizeDelivery([...$changes,'status'=>$v['status']],$o);
   if($v['status']==='Delivered')$changes['delivered_at']=now();
   $history=json_decode($o->status_history??'[]',true);$history[]=['status'=>$v['status'],'at'=>now()->toIso8601String()];$changes['status']=$v['status'];$changes['status_history']=json_encode($history);DB::table('orders')->where('id',$o->id)->update($changes);OrderEmails::record($o->id,'status');
   $updated=DB::table('orders')->find($o->id);unset($updated->checkout_token,$updated->request_hash);return response()->json(['ok'=>true,'order'=>$updated]);
  },3);
 }
 public function notifications(Request $r){$r->validate(['id'=>'required|integer|exists:orders,id']);$rows=DB::table('order_email_outbox')->where('order_id',$r->id)->orderByDesc('id')->get();return response()->json(['notifications'=>$rows->map(function($n){$p=json_decode($n->payload,true);return ['id'=>$n->id,'event'=>$p['event'],'status'=>$p['order']['status'],'created_at'=>$n->created_at,'accepted_at'=>$n->sent_at,'attempts'=>$n->attempts,'last_error'=>$n->last_error,'next_attempt'=>$n->sent_at?null:$n->available_at,'outcomes'=>DB::table('order_email_attempts')->where('outbox_id',$n->id)->orderBy('id')->get(['attempt','outcome','started_at','completed_at','error'])];})]);}
}
