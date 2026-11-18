<?php

namespace App\Http\Controllers;

use App\Support\VendorSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\Rule;

class VendorSettlementController extends Controller
{
    private function owner(Request $r):bool
    {
        return $r->user()?->role==='super_admin';
    }

    public function handle(Request $r,string $action)
    {
        abort_unless($this->owner($r)||$r->user()?->role==='vendor',403);
        if ($r->isMethod('GET')) {
            if ($action==='receipt') {
                $payment=DB::table('vendor_remittances')->where('id',$r->integer('id'))->when(!$this->owner($r),fn($q)=>$q->where('vendor_id',$r->user()->id))->first();
                abort_unless($payment?->receipt,404);
                return Storage::disk('local')->download($payment->receipt,null,['Cache-Control'=>'private, no-store']);
            }
            if ($action==='dispatch') return $this->dispatch($r);
            abort_unless(in_array($action,['data','statement']),404);
            $data=$this->data($r);
            if ($action==='statement') return app(VendorStatementController::class)->download($r,$data);
            return response()->json($data);
        }
        if ($action==='stitching'&&$r->isMethod('POST')) return $this->stitching($r);
        abort_unless($this->owner($r),403);
        if ($action==='reconcile'&&$r->isMethod('POST')) return $this->reconcile($r);
        if ($action==='payment'&&$r->isMethod('POST')) return $this->payment($r);
        abort(404);
    }

    private function filters(Request $r):array
    {
        return $r->validate(['vendor_id'=>'nullable|integer','from'=>'nullable|date_format:Y-m-d','to'=>['nullable','date_format:Y-m-d',...($r->filled('from')?['after_or_equal:from']:[])],'product'=>'nullable|string|max:150','order'=>'nullable|string|max:100']);
    }

    private function scoped(Request $r)
    {
        return DB::table('vendor_sale_terms as s')->join('orders as o','o.id','=','s.order_id')
            ->when(!$this->owner($r),fn($q)=>$q->where('s.vendor_id',$r->user()->id));
    }

    private function data(Request $r):array
    {
        $f=$this->filters($r);
        $query=$this->scoped($r)->when($this->owner($r)&&!empty($f['vendor_id']),fn($q)=>$q->where('s.vendor_id',$f['vendor_id']));
        $all=$query->orderByDesc('s.id')->get(['s.*','o.reference','o.created_at as ordered_at','o.status as order_status']);
        $rows=$all->filter(fn($s)=>(empty($f['from'])||substr($s->ordered_at,0,10)>=$f['from'])&&(empty($f['to'])||substr($s->ordered_at,0,10)<=$f['to'])
            &&(empty($f['product'])||str_contains(strtolower($s->product_name.' '.$s->code),strtolower($f['product'])))
            &&(empty($f['order'])||str_contains(strtolower($s->reference),strtolower($f['order']))))
            ->map(function($s){
                $s->reconciliation=DB::table('vendor_reconciliations')->where('sale_id',$s->id)->first();
                $s->fulfillment=DB::table('vendor_fulfillments')->where('order_item_id',$s->order_item_id)->value('status');
                $s->journal=DB::table('vendor_settlement_entries')->where('sale_id',$s->id)->orderBy('id')->get()->map(function($entry){$entry->components=json_decode($entry->components,true);return $entry;});
                $last=$s->journal->last();
                $s->due=$s->journal->sum('amount');$s->earnings=$s->journal->sum('earnings');
                $s->settlement_state=$last?->kind==='Sale'?'Reconciled':($last?'Adjusted':'Pending reconciliation');
                return $s;
            })->values();
        $payments=DB::table('vendor_remittances')->when(!$this->owner($r),fn($q)=>$q->where('vendor_id',$r->user()->id))
            ->when($this->owner($r)&&!empty($f['vendor_id']),fn($q)=>$q->where('vendor_id',$f['vendor_id']))->orderByDesc('id')
            ->get(['id','vendor_id','amount','paid_on','method','reference','account','remarks','receipt']);
        $vendorIds=$all->pluck('vendor_id')->merge($payments->pluck('vendor_id'))->unique();
        $balances=$vendorIds->map(function($id){
            $due=(int)DB::table('vendor_settlement_entries')->where('vendor_id',$id)->sum('amount');
            $received=(int)DB::table('vendor_remittances')->where('vendor_id',$id)->sum('amount');
            return ['vendor_id'=>$id,'name'=>DB::table('users')->where('id',$id)->value('name'),'due'=>$due,'received'=>$received,'outstanding'=>$due-$received];
        })->values();
        $payments=$payments->filter(fn($p)=>(empty($f['from'])||$p->paid_on>=$f['from'])&&(empty($f['to'])||$p->paid_on<=$f['to']))->map(function($p){$p->has_receipt=boolval($p->receipt);unset($p->receipt);return $p;})->values();
        return ['sales'=>$rows,'payments'=>$payments,'balances'=>$balances,'vendors'=>$this->owner($r)?DB::table('users')->where('role','vendor')->get(['id','name']):[]];
    }

    private function reconcile(Request $r)
    {
        $v=$r->validate(['sale_id'=>'required|integer','courier_cost'=>'required|numeric|decimal:0,2|min:0|max:100000000','collected'=>'required|numeric|decimal:0,2|min:0|max:100000000','refunded'=>'required|numeric|decimal:0,2|min:0|max:100000000','courier_payer'=>'sometimes|in:vendor,store','collector'=>'required|in:vendor,store','notes'=>'required|string|max:2000']);
        return DB::transaction(function() use($v,$r){
            $sale=DB::table('vendor_sale_terms')->find($v['sale_id']);abort_unless($sale,404);
            DB::table('orders')->where('id',$sale->order_id)->lockForUpdate()->first();
            DB::table('users')->where('id',$sale->vendor_id)->lockForUpdate()->first();
            $sale=DB::table('vendor_sale_terms')->where('id',$sale->id)->lockForUpdate()->first();
            $collected=VendorSettlement::cents($v['collected']);$refunded=VendorSettlement::cents($v['refunded']);
            abort_if($collected>$sale->product_retail+$sale->stitch_charge+$sale->shipping_share||$refunded>$collected,422,'Collection/refund exceeds this item allocation.');
            $old=DB::table('vendor_reconciliations')->where('sale_id',$sale->id)->first();
            $fields=['courier_cost'=>VendorSettlement::cents($v['courier_cost']),'collected'=>$collected,'refunded'=>$refunded,'collector'=>$v['collector'],'courier_payer'=>$v['courier_payer']??'vendor','notes'=>$v['notes'],'actor_id'=>$r->user()->id,'revision'=>($old->revision??0)+1,'updated_at'=>now()];
            if($old && collect($fields)->except(['revision','updated_at'])->all()===collect($old)->except(['sale_id','revision','updated_at'])->all()) return response()->json(['ok'=>true]);
            DB::table('vendor_reconciliations')->updateOrInsert(['sale_id'=>$sale->id],$fields);
            DB::table('vendor_reconciliation_audits')->insert(['sale_id'=>$sale->id,'revision'=>$fields['revision'],'payload'=>json_encode($fields,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            VendorSettlement::syncOrder($sale->order_id);
            return response()->json(['ok'=>true]);
        },3);
    }

    private function payment(Request $r)
    {
        $v=$r->validate([
            'vendor_id'=>'required|integer','amount'=>'required|numeric|decimal:0,2|min:0.01|max:100000000',
            'paid_on'=>'required|date_format:Y-m-d|before_or_equal:today','method'=>'required|string|max:50',
            'reference'=>'required|string|max:150','account'=>'nullable|string|max:150','remarks'=>'nullable|string|max:2000',
            'token'=>'required|uuid','receipt'=>'nullable|file|mimes:pdf,png,jpg,jpeg|max:5120',
        ]);
        $stored=null;
        try{return DB::transaction(function()use($r,$v,&$stored){
            $vendor=DB::table('users')->where('role','vendor')->where('id',$v['vendor_id'])->lockForUpdate()->first();abort_unless($vendor,404);
            $old=DB::table('vendor_remittances')->where('token',$v['token'])->first();
            if($old){
                abort_unless($old->vendor_id==$v['vendor_id']&&$old->amount===VendorSettlement::cents($v['amount'])&&$old->reference===$v['reference'],409,'Payment token already used.');
                return response()->json(['ok'=>true,'id'=>$old->id]);
            }
            abort_if(DB::table('vendor_remittances')->where('vendor_id',$vendor->id)->where('reference',$v['reference'])->exists(),409,'A payment with this reference is already recorded.');
            $balance=(int)DB::table('vendor_settlement_entries')->where('vendor_id',$vendor->id)->sum('amount')-(int)DB::table('vendor_remittances')->where('vendor_id',$vendor->id)->sum('amount');
            abort_if(VendorSettlement::cents($v['amount'])>$balance,422,'Amount exceeds the verified outstanding vendor balance.');
            if($r->hasFile('receipt')){$stored=$r->file('receipt')->store('settlement-receipts','local');abort_unless($stored,500);}
            $fields=collect($v)->except('receipt')->all();$fields['amount']=VendorSettlement::cents($v['amount']);
            $id=DB::table('vendor_remittances')->insertGetId($fields+['receipt'=>$stored,'actor_id'=>$r->user()->id,'created_at'=>now()]);
            return response()->json(['ok'=>true,'id'=>$id]);
        },1);}catch(\Throwable $e){if($stored)Storage::disk('local')->delete($stored);throw $e;}
    }

    private function stitching(Request $r)
    {
        abort_unless($this->owner($r)||(DB::table('vendor_profiles')->where('user_id',$r->user()->id)->value('fulfillment_enabled')??true),403);
        $v=$r->validate(['id'=>'required|integer','status'=>'required|string']);
        $states=['Stitching Requested','Awaiting Vendor Confirmation','Stitching in Progress','Stitching Completed','Ready for Dispatch'];
        return DB::transaction(function()use($r,$v,$states){
            $initial=DB::table('vendor_fulfillments')->where('id',$v['id'])->when(!$this->owner($r),fn($q)=>$q->where('vendor_id',$r->user()->id))->first();abort_unless($initial,404);
            $order=DB::table('orders')->where('id',$initial->order_id)->lockForUpdate()->first();
            abort_if(in_array($order->status,['Cancelled','Returned'],true),409);
            $row=DB::table('vendor_fulfillments')->where('id',$initial->id)->lockForUpdate()->first();
            abort_unless($this->owner($r)||$row->vendor_id===$r->user()->id,404);
            abort_if(DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->exists(),409,'Stitching is closed after dispatch.');
            abort_unless(DB::table('vendor_sale_terms')->where('order_item_id',$row->order_item_id)->where('stitch_charge','>',0)->exists(),422,'No stitching service on this item.');
            $last=DB::table('vendor_stitch_progress')->where('fulfillment_id',$row->id)->latest('id')->value('status');
            if($last===$v['status'])return response()->json(['ok'=>true]);
            $next=$last?$states[array_search($last,$states)+1]??null:$states[0];
            abort_unless($next===$v['status'],422,'Follow the stitching stages in order.');
            DB::table('vendor_stitch_progress')->insert(['fulfillment_id'=>$row->id,'status'=>$next,'actor_id'=>$r->user()->id,'created_at'=>now()]);
            return response()->json(['ok'=>true]);
        },3);
    }

    private function dispatch(Request $r)
    {
        $f=$this->filters($r);
        $r->validate(['status'=>'nullable|in:Awaiting Acceptance,Accepted,Processing,Ready for Dispatch,Shipped,Delivered']);
        $rows=DB::table('vendor_fulfillments as f')->join('orders as o','o.id','=','f.order_id')
            ->when(!$this->owner($r),fn($q)=>$q->where('f.vendor_id',$r->user()->id))
            ->when($this->owner($r)&&!empty($f['vendor_id']),fn($q)=>$q->where('f.vendor_id',$f['vendor_id']))
            ->when($r->filled('status'),fn($q)=>$q->where('f.status',$r->input('status')))
            ->when(!empty($f['from']),fn($q)=>$q->whereDate('o.created_at','>=',$f['from']))
            ->when(!empty($f['to']),fn($q)=>$q->whereDate('o.created_at','<=',$f['to']))
            ->orderBy('f.id')->get(['f.*','o.reference','o.created_at as ordered_at','o.name','o.phone','o.address','o.city','o.postal_code']);
        $records=[['Order ID','Order date','Product code','Product','Type','Quantity','Customer','Phone','Address','City','Courier','Tracking','Dispatch','Expected delivery','Status']];
        foreach($rows as $row){
            $item=json_decode($row->snapshot,true);$shipment=DB::table('vendor_shipments')->where('fulfillment_id',$row->id)->first();
            $records[]=[$row->reference,$row->ordered_at,$item['code'],$item['name'],$item['variant'],$item['quantity'],$row->name,$row->phone,$row->address.' '.$row->postal_code,$row->city,$shipment?->courier,$shipment?->tracking,$shipment?->dispatched_on,$shipment?->expected_on,$row->status];
        }
        return app(VendorStatementController::class)->csv($records,'dispatch-orders.csv');
    }
}
