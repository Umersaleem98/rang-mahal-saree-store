<?php

namespace App\Http\Controllers;

use App\Support\{BusinessSettings,OrderEmails};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\Rule;

class VendorFulfillmentController extends Controller
{
    private const NEXT = [
        'Awaiting Acceptance'=>['Accepted','Rejected'],
        'Accepted'=>['Processing','On Hold','Delayed'],
        'Processing'=>['Ready for Dispatch','On Hold','Delayed'],
        'Ready for Dispatch'=>['Shipped','On Hold','Delayed'],
        'Shipped'=>['Out for Delivery','Delayed','Delivery Failed','Returned'],
        'Out for Delivery'=>['Delivered','Delayed','Delivery Failed','Returned'],
        'Delivery Failed'=>['Out for Delivery','Returned'],
        'Delivered'=>['Returned'],
    ];

    private const NOTICE = ['Processing','Ready for Dispatch','Shipped','Out for Delivery','Delivered','Delayed','Delivery Failed','Returned','Cancelled'];
    private const COURIERS = ['TCS','Leopards Courier','M&P','PostEx','Trax','Call Courier','Pakistan Post','Other'];

    public function handle(Request $request,string $action)
    {
        $user=$request->user();
        $owner=$user?->role==='super_admin';
        abort_unless($owner || $user?->role==='vendor',403);
        if (!$owner) {
            abort_unless(DB::table('vendor_profiles')->where('user_id',$user->id)->value('fulfillment_enabled') ?? true,403);
        }
        if ($request->isMethod('GET')) {
            if ($action==='receipt') return $this->receipt($request,$owner);
            abort_unless($action==='list',404);
            return response()->json($this->listing($request,$owner));
        }
        if ($action==='settings' && $request->isMethod('PUT')) {
            abort_unless($owner,403);
            $data=$request->validate([
                'couriers'=>'required|array|min:1|max:25','couriers.*'=>'required|string|max:100|distinct',
                'accept_hours'=>'required|integer|min:1|max:168','vendor_delivery'=>'required|boolean',
            ]);
            DB::transaction(function() use ($data) {
                DB::table('business_settings')->insertOrIgnore(['id'=>1,'values'=>'{}']);
                $row=DB::table('business_settings')->where('id',1)->lockForUpdate()->first();
                $settings=json_decode($row->values,true,flags:JSON_THROW_ON_ERROR);
                $settings['vendor_fulfillment']=$data;
                DB::table('business_settings')->where('id',1)->update(['values'=>json_encode($settings,JSON_THROW_ON_ERROR)]);
            });
            return response()->json(['ok'=>true]);
        }
        if ($action==='assign' && $request->isMethod('POST')) {
            abort_unless($owner,403);
            return $this->assign($request);
        }
        if ($action==='update' && $request->isMethod('POST')) return $this->update($request,$owner);
        if ($action==='resend' && $request->isMethod('POST')) {
            abort_unless($owner,403);
            $data=$request->validate(['id'=>'required|integer','notification_id'=>'required|integer','notes'=>'required|string|max:1000']);
            return DB::transaction(function() use($request,$data) {
                $row=DB::table('vendor_fulfillments')->where('id',$data['id'])->lockForUpdate()->first();
                abort_unless($row,404);
                $email=DB::table('order_email_outbox')->where('order_id',$row->order_id)->where('id',$data['notification_id'])->first();
                abort_unless($email,404);
                $payload=json_decode($email->payload,true,flags:JSON_THROW_ON_ERROR);
                abort_unless(($payload['fulfillment_id']??null)===$row->id,404);
                $event=$this->event($row,$request->user()->id,'Email resend',$data['notes']);
                $payload['resent']=true;
                OrderEmails::queuePayload($row->order_id,'manual-resend-'.$event,$payload);
                return response()->json(['ok'=>true]);
            });
        }
        abort(404);
    }

    public static function settings():array
    {
        return array_replace(['couriers'=>self::COURIERS,'accept_hours'=>24,'vendor_delivery'=>false], BusinessSettings::get()['vendor_fulfillment']??[]);
    }

    private function accessible(Request $request,bool $owner)
    {
        return DB::table('vendor_fulfillments')->when(!$owner,fn($q)=>$q->where('vendor_id',$request->user()->id));
    }

    private function listing(Request $request,bool $owner):array
    {
        $query=$this->accessible($request,$owner);
        $orderId=$request->integer('order_id');
        if ($owner && $orderId) $query->where('order_id',$orderId);
        $settings=self::settings();
        $rows=$query->orderByDesc('id')->get()->map(function($row) use ($owner,$settings) {
            $order=DB::table('orders')->find($row->order_id);
            $shipment=DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->first();
            $history=DB::table('vendor_fulfillment_events')->where('fulfillment_id',$row->id);
            if (!$owner) $history->where('vendor_id',$row->vendor_id)->where('created_at','>=',$row->assigned_at)->whereIn('action',['Assignment','Status']);
            $snapshot=json_decode($row->snapshot,true,flags:JSON_THROW_ON_ERROR);
            if (!$owner) $snapshot=collect($snapshot)->only(['name','image','description','code','quantity','variant','size','category'])->all();
            $result=[
                'id'=>$row->id,'item_id'=>$row->order_item_id,'reference'=>$order->reference,
                'ordered_at'=>$order->created_at,'assigned_at'=>$row->assigned_at,'status'=>$row->status,
                'seen'=>boolval($row->vendor_seen_at),'item'=>$snapshot,
                'delivery'=>collect($order)->only(['name','address','city','postal_code','phone'])->all(),
                'shipment'=>$this->shipment($shipment,true),'history'=>$history->orderBy('id')->get($owner?['*']:['action','status','created_at']),
                'next'=>$this->next($row,$settings,$owner),
            ];
            if ($owner) {
                $result['vendor_id']=$row->vendor_id;
                $result['vendor_name']=DB::table('users')->where('id',$row->vendor_id)->value('name');
                $result['overdue']=($row->status==='Awaiting Acceptance' && now()->gt(Carbon::parse($row->accept_by)))
                    || (!$shipment && !in_array($row->status,['Rejected','Cancelled','Returned'],true) && now()->gt(Carbon::parse($row->dispatch_by)))
                    || ($shipment && !in_array($row->status,['Delivered','Cancelled','Returned'],true) && today()->gt(Carbon::parse($shipment->expected_on)));
                if (request()->integer('order_id')) {
                    $result['notifications']=DB::table('order_email_outbox')->where('order_id',$row->order_id)->orderByDesc('id')->get(['id','payload','sent_at','attempts','last_error'])
                        ->filter(fn($mail)=>(json_decode($mail->payload,true)['fulfillment_id']??null)===$row->id)
                        ->map(fn($mail)=>collect($mail)->except('payload')->all())->values();
                }
            }
            return $result;
        });
        $result=['rows'=>$rows,'settings'=>$settings];
        if ($owner) {
            $result['vendors']=DB::table('users as u')->leftJoin('vendor_profiles as p','p.user_id','=','u.id')->where('u.role','vendor')->where('u.active',true)->get(['u.id','u.name','u.email','p.phone','p.email as contact_email','p.fulfillment_enabled']);
            $result['total_orders']=DB::table('orders')->count();
            $result['new_orders']=DB::table('orders')->where('status','Pending')->count();
            $result['completed_counts']=DB::table('orders')->whereIn('status',['Shipped','Out for Delivery','Delivered','Cancelled'])
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total','status');
            $result['rejected_count']=DB::table('vendor_fulfillments')->where('status','Rejected')->count();
            $result['awaiting_assignment']=DB::table('orders as o')->join('order_items as i','i.order_id','=','o.id')
                ->whereNotIn('o.status',['Cancelled','Returned','Delivered'])->whereNotExists(fn($q)=>$q->selectRaw('1')->from('vendor_fulfillments as f')->whereColumn('f.order_item_id','i.id'))->distinct()->count('o.id');
        } else {
            $profile=DB::table('vendor_profiles')->where('user_id',$request->user()->id)->first(['contact_person','email','phone','address','logo','vendor_name']);
            $result['profile']=array_replace(['login_email'=>$request->user()->email,'active'=>true],(array)$profile);
        }
        return $result;
    }

    private function assign(Request $request)
    {
        $data=$request->validate(['item_id'=>'required|integer|exists:order_items,id','vendor_id'=>'required|integer','notes'=>'nullable|string|max:1000']);
        return DB::transaction(function () use($request,$data) {
            $item=DB::table('order_items')->find($data['item_id']);
            $order=DB::table('orders')->where('id',$item->order_id)->lockForUpdate()->first();
            abort_if(in_array($order->status,['Shipped','Out for Delivery','Delivered','Cancelled','Returned'],true),409,'This order has already dispatched or closed.');
            $vendor=DB::table('users')->where('role','vendor')->where('active',true)->find($data['vendor_id']);
            abort_unless($vendor && (DB::table('vendor_profiles')->where('user_id',$vendor->id)->value('fulfillment_enabled')??true),422,'Select an active vendor with fulfillment access.');
            $old=DB::table('vendor_fulfillments')->where('order_item_id',$item->id)->lockForUpdate()->first();
            abort_if($old && (DB::table('vendor_shipments')->where('fulfillment_id',$old->id)->exists() || in_array($old->status,['Delivered','Returned','Cancelled'],true)),409,'This item cannot be reassigned.');
            if ($old && (int)$old->vendor_id===(int)$vendor->id && $old->status!=='Rejected') return response()->json(['ok'=>true]);
            $source=DB::table('vendor_order_payables')->where('order_item_id',$item->id)->first();
            $product=DB::table('products')->find($item->product_id);
            $snapshot=$old?json_decode($old->snapshot,true,flags:JSON_THROW_ON_ERROR):[
                'name'=>$item->name,'image'=>$product?->image,'description'=>$product?->description,
                'code'=>$source?->inventory_code,'original_vendor_id'=>$source?->vendor_id,'listing_id'=>$source?->listing_id,
                'approved_price'=>$source?->unit_payable,'storefront_id'=>$item->product_id,
                'retail_price'=>$item->price,'quantity'=>$item->quantity,'variant'=>$item->variant,
                'size'=>$item->selected_size,'category'=>$item->category_snapshot??$product?->category,
            ];
            $values=[
                'vendor_id'=>$vendor->id,'order_id'=>$item->order_id,'order_item_id'=>$item->id,
                'assigned_by'=>$request->user()->id,'status'=>'Awaiting Acceptance','snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),
                'assigned_at'=>now(),'accept_by'=>now()->addHours(self::settings()['accept_hours']),
                'dispatch_by'=>now()->addWeekdays($item->variant==='Stitched'?7:3),'vendor_seen_at'=>null,'updated_at'=>now(),
            ];
            if ($old) {
                abort_unless(filled($data['notes']??null),422,'Enter a reason for reassignment.');
                DB::table('vendor_fulfillments')->where('id',$old->id)->update($values);
                $id=$old->id;
            } else $id=DB::table('vendor_fulfillments')->insertGetId($values+['created_at'=>now()]);
            $this->event((object) ($values+['id'=>$id]),$request->user()->id,'Assignment',$data['notes']??null,$old?->vendor_id);
            return response()->json(['ok'=>true,'id'=>$id]);
        },3);
    }

    private function next(object $row,array $settings,bool $owner):array
    {
        $next=self::NEXT[$row->status]??[];
        if (in_array($row->status,['On Hold','Delayed'],true)) {
            $previous=DB::table('vendor_fulfillment_events')->where('fulfillment_id',$row->id)->where('vendor_id',$row->vendor_id)
                ->whereNotIn('status',['On Hold','Delayed'])->latest('id')->value('status');
            $next=$previous?[$previous]:[];
        }
        if (!$owner && !$settings['vendor_delivery']) $next=array_values(array_diff($next,['Delivered']));
        return $next;
    }

    private function update(Request $request,bool $owner)
    {
        $data=$request->validate([
            'id'=>'required|integer','status'=>'required|string|max:32','notes'=>'nullable|string|max:2000',
            'override'=>'sometimes|boolean','courier'=>'nullable|string|max:100','service'=>'nullable|string|max:100',
            'tracking'=>'nullable|string|max:100','tracking_url'=>'nullable|url:https|max:500',
            'dispatched_on'=>'nullable|date_format:Y-m-d|before_or_equal:today',
            'expected_on'=>'nullable|date_format:Y-m-d|after_or_equal:dispatched_on',
            'shipment_notes'=>'nullable|string|max:1000','receipt'=>'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);
        $stored=null;
        try {
            return DB::transaction(function() use ($request,$owner,$data,&$stored) {
                $initial=$this->accessible($request,$owner)->find($data['id']);
                abort_unless($initial,404);
                $order=DB::table('orders')->where('id',$initial->order_id)->lockForUpdate()->first();
                $row=$this->accessible($request,$owner)->lockForUpdate()->find($data['id']);
                abort_unless($row,404);
                abort_if($order->status==='Cancelled',409,'The customer order was cancelled.');
                $status=$data['status'];
                if ($status==='Seen') {
                    abort_if($owner,403);
                    DB::table('vendor_fulfillments')->where('id',$row->id)->whereNull('vendor_seen_at')->update(['vendor_seen_at'=>now()]);
                    return response()->json(['ok'=>true]);
                }
                if ($status==='Note') {
                    abort_unless($owner && filled($data['notes']??null),422);
                    $this->event($row,$request->user()->id,'Internal note',$data['notes']);
                    return response()->json(['ok'=>true]);
                }
                if ($row->status===$status) return response()->json(['ok'=>true]);
                $override=$owner && ($data['override']??false);
                $settings=self::settings();
                abort_unless(in_array($status,$this->next($row,$settings,$owner),true) || ($override && in_array($status,array_unique([...array_keys(self::NEXT),...self::NOTICE,'Rejected','Accepted','On Hold']),true)),422,'Invalid fulfillment transition.');
                abort_if(($override || in_array($status,['Rejected','Delayed','Delivery Failed'],true)) && blank($data['notes']??null),422,'Enter a reason.');
                $shipment=DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->first();
                if ($status==='Shipped' && !$shipment) {
                    foreach (['courier','tracking','dispatched_on','expected_on'] as $field) abort_if(blank($data[$field]??null),422,'Enter the courier, tracking ID, dispatch date and expected delivery date.');
                    abort_unless(in_array($data['courier'],$settings['couriers'],true),422,'Choose a configured courier.');
                    abort_if($data['dispatched_on']<substr($row->assigned_at,0,10),422,'Dispatch cannot precede assignment.');
                    $fields=collect($data)->only(['courier','service','tracking','tracking_url','dispatched_on','expected_on'])->all();
                    $fields['notes']=$data['shipment_notes']??null;
                    if ($request->hasFile('receipt')) {
                        $stored=$request->file('receipt')->store('vendor-receipts','local');
                        abort_unless($stored,500,'Could not store receipt.');
                        $fields['receipt']=$stored;
                    }
                    DB::table('vendor_shipments')->insert($fields+['fulfillment_id'=>$row->id]);
                    $shipment=DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->first();
                }
                abort_if(in_array($status,['Out for Delivery','Delivered','Delivery Failed','Returned'],true) && !$shipment,422,'Record the shipment before changing its delivery status.');
                if ($status==='Delivered') DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->update(['delivered_at'=>now()]);
                DB::table('vendor_fulfillments')->where('id',$row->id)->update(['status'=>$status,'updated_at'=>now(),'vendor_seen_at'=>$row->vendor_seen_at??now()]);
                $row->status=$status;
                $this->event($row,$request->user()->id,$override?'Admin override':'Status',$data['notes']??null);
                $this->syncMaster($order);
                if (in_array($status,self::NOTICE,true)) OrderEmails::fulfillment($row,$shipment);
                return response()->json(['ok'=>true]);
            },$request->hasFile('receipt')?1:3);
        } catch (\Throwable $e) {
            if ($stored) Storage::disk('local')->delete($stored);
            throw $e;
        }
    }

    private function event(object $row,int $actor,string $action,?string $notes=null,?int $previous=null):int
    {
        return DB::table('vendor_fulfillment_events')->insertGetId([
            'fulfillment_id'=>$row->id,'actor_id'=>$actor,'vendor_id'=>$row->vendor_id,
            'previous_vendor_id'=>$previous,'action'=>$action,'status'=>$row->status,'notes'=>$notes,'created_at'=>now(),
        ]);
    }

    private function syncMaster(object $order):void
    {
        $states=DB::table('vendor_fulfillments')->where('order_id',$order->id)->pluck('status');
        $complete=$states->count()===DB::table('order_items')->where('order_id',$order->id)->count();
        $status=null;
        if ($complete && $states->every(fn($s)=>$s==='Delivered')) $status='Delivered';
        elseif ($complete && $states->every(fn($s)=>$s==='Returned')) $status='Returned';
        elseif ($complete && $states->every(fn($s)=>in_array($s,['Out for Delivery','Delivered'],true))) $status='Out for Delivery';
        elseif ($complete && $states->every(fn($s)=>in_array($s,['Shipped','Out for Delivery','Delivered'],true))) $status='Shipped';
        elseif (in_array($order->status,['Pending','Confirmed','Processing','Packed'],true) && $states->intersect(['Processing','Ready for Dispatch','Shipped','Out for Delivery','Delivered'])->isNotEmpty()) $status='Processing';
        if (!$status || $status===$order->status) return;
        $history=json_decode($order->status_history??'[]',true)?:[];
        $history[]=['status'=>$status,'at'=>now()->toIso8601String()];
        $fields=['status'=>$status,'status_history'=>json_encode($history,JSON_THROW_ON_ERROR)];
        if ($status==='Delivered') $fields['delivered_at']=now();
        DB::table('orders')->where('id',$order->id)->update($fields);
    }

    private function receipt(Request $request,bool $owner)
    {
        $row=$this->accessible($request,$owner)->find($request->integer('id'));
        abort_unless($row,404);
        $receipt=DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->value('receipt');
        abort_unless($receipt && Storage::disk('local')->exists($receipt),404);
        return Storage::disk('local')->download($receipt,null,['Cache-Control'=>'private, no-store']);
    }

    public static function cancelAssignments(int $orderId,int $actor):void
    {
        $rows=DB::table('vendor_fulfillments')->where('order_id',$orderId)->lockForUpdate()->get();
        foreach($rows as $row){
            abort_if(DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->exists(),409,'A shipment has dispatched. Resolve it individually.');
            DB::table('vendor_fulfillments')->where('id',$row->id)->update(['status'=>'Cancelled','updated_at'=>now()]);
            $row->status='Cancelled';
            (new self)->event($row,$actor,'Master cancellation');
        }
    }

    private function shipment(?object $shipment,bool $private=false):?array
    {
        if (!$shipment) return null;
        $data=collect($shipment)->only(['courier','service','tracking','tracking_url','dispatched_on','expected_on','delivered_at'])->all();
        if ($private) $data+=['notes'=>$shipment->notes,'has_receipt'=>boolval($shipment->receipt)];
        return $data;
    }

    public static function publicUpdates(int $id):array
    {
        return DB::table('vendor_fulfillments')->where('order_id',$id)->orderBy('id')->get()->map(function($row){
            $item=json_decode($row->snapshot,true,flags:JSON_THROW_ON_ERROR);
            $shipment=DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->first();
            $label=match($row->status){'Awaiting Acceptance','Accepted','Rejected'=>'Order received','On Hold'=>'Processing', default=>$row->status};
            return ['name'=>$item['name'],'quantity'=>$item['quantity'],'variant'=>$item['variant'],'status'=>$label,
                'shipment'=>(new self)->shipment($shipment)];
        })->all();
    }
}
