<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderUpdate;
class OrderEmails {
 public static function snapshot($order):array {
  $data=collect($order)->only(['name','email','phone','address','city','postal_code','country','reference','created_at','status','payment_status','payment_method','shipping','total','courier_name','courier_tracking_number','courier_tracking_url','estimated_delivery_date','dispatched_at','delivered_at','delivery_notes','delivery_method'])->all();
  if(!in_array($order->status,['Shipped','Out for Delivery','Delivered','Returned']))foreach(['courier_name','courier_tracking_number','courier_tracking_url','estimated_delivery_date','dispatched_at','delivery_notes'] as $key)$data[$key]=null;
  if(($order->delivery_method??'courier')!=='courier')foreach(['courier_name','courier_tracking_number','courier_tracking_url'] as $key)$data[$key]=null;
  return $data;
 }
 public static function record(int $id,string $event): void {
  $order=DB::table('orders')->find($id); if(!$order)return;
  $items=DB::table('order_items')->where('order_id',$id)->get(['name','variant','quantity','price','stitching_charge','selected_size','sku_snapshot']);
  $snapshot=self::snapshot($order);
  $key=hash('sha256',$id.'|'.$event.'|'.json_encode($snapshot));
  DB::table('order_email_outbox')->insertOrIgnore(['order_id'=>$id,'event_key'=>$key,'payload'=>json_encode(['event'=>$event,'order'=>$snapshot,'items'=>$items,'business'=>BusinessSettings::get()]),'available_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
 }
 public static function deliver(int $limit=50): int {
  if(in_array(config('mail.default'),['log','array']))return 0;
  $sent=0;
  for($n=0;$n<$limit;$n++){
   $row=DB::transaction(function(){ $r=DB::table('order_email_outbox')->whereNull('sent_at')->where('attempts','<',8)->where('available_at','<=',now())->orderBy('id')->lockForUpdate()->first();if(!$r)return null;DB::table('order_email_outbox')->where('id',$r->id)->update(['attempts'=>$r->attempts+1,'available_at'=>now()->addMinutes(10),'updated_at'=>now()]);$r->attempt_id=DB::table('order_email_attempts')->insertGetId(['outbox_id'=>$r->id,'attempt'=>$r->attempts+1,'started_at'=>now(),'outcome'=>'sending']);return $r; });
   if(!$row)break;
   try { $p=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);Mail::to($p['order']['email'])->send(new OrderUpdate($p));DB::table('order_email_outbox')->where('id',$row->id)->update(['sent_at'=>now(),'last_error'=>null,'updated_at'=>now()]);DB::table('order_email_attempts')->where('id',$row->attempt_id)->update(['outcome'=>'accepted','completed_at'=>now()]);$sent++; }
   catch(\Throwable $e){report($e);DB::table('order_email_attempts')->where('id',$row->attempt_id)->update(['outcome'=>'failed','completed_at'=>now(),'error'=>'Mail transport rejected or could not accept this message.']);DB::table('order_email_outbox')->where('id',$row->id)->update(['last_error'=>'Delivery failed; inspect private application log.','available_at'=>now()->addMinutes(min(360,2**($row->attempts+1))),'updated_at'=>now()]);}
  }
  return $sent;
 }
}
