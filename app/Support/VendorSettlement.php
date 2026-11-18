<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class VendorSettlement
{
    public static function cents(mixed $amount):int
    {
        return (int) round((float)$amount*100);
    }

    public static function snapshot(int $orderId):void
    {
        $order=DB::table('orders')->find($orderId);
        $items=DB::table('order_items')->where('order_id',$orderId)->orderBy('id')->get();
        $total=$items->sum(fn($item)=>self::cents($item->price)*$item->quantity);
        $distributed=0;
        foreach($items as $index=>$item){
            $share=$index===$items->count()-1?self::cents($order->shipping)-$distributed:
                (int) floor(self::cents($order->shipping)*self::cents($item->price)*$item->quantity/max(1,$total));
            $distributed+=$share;
            $source=DB::table('vendor_order_payables')->where('order_item_id',$item->id)->first();
            if (!$source) continue;
            $finance=DB::table('vendor_submission_finances')->where('listing_id',$source->listing_id)->first();
            $listing=DB::table('vendor_listings')->find($source->listing_id);
            $unitEntitlement=self::cents($listing->approved_payable);
            $cost=$finance?->purchase_cost===null?null:self::cents($finance->purchase_cost);
            $profit=$finance?->margin===null?null:self::cents($finance->margin);
            // Older approved terms must be reviewed when they differ from the two supplied components.
            $entitlement=$cost!==null&&$profit!==null&&$cost+$profit===$unitEntitlement?$unitEntitlement*$item->quantity:null;
            $stitch=self::cents($item->stitching_charge)*$item->quantity;
            DB::table('vendor_sale_terms')->insertOrIgnore([
                'order_item_id'=>$item->id,'order_id'=>$orderId,'vendor_id'=>$source->vendor_id,'listing_id'=>$source->listing_id,
                'code'=>$source->inventory_code,'product_name'=>$item->name,'vendor_name'=>$source->vendor_name,'quantity'=>$item->quantity,
                'purchase_cost'=>$cost===null?null:$cost*$item->quantity,'desired_profit'=>$profit===null?null:$profit*$item->quantity,
                'entitlement'=>$entitlement,'product_retail'=>self::cents($item->price)*$item->quantity-$stitch,
                'stitch_charge'=>$stitch,'vendor_stitch'=>$stitch>0?250000*$item->quantity:0,'shipping_share'=>$share,'created_at'=>now(),
            ]);
        }
    }

    public static function syncOrder(int $orderId):void
    {
        foreach(DB::table('vendor_sale_terms')->where('order_id',$orderId)->orderBy('id')->get() as $sale) self::sync($sale->id);
    }

    public static function sync(int $saleId):void
    {
        $initial=DB::table('vendor_sale_terms')->find($saleId);
        DB::table('users')->where('id',$initial->vendor_id)->lockForUpdate()->first();
        $sale=DB::table('vendor_sale_terms')->where('id',$saleId)->lockForUpdate()->first();
        $order=DB::table('orders')->find($sale->order_id);
        $fulfillment=DB::table('vendor_fulfillments')->where('order_item_id',$sale->order_item_id)->first();
        $reconciliation=DB::table('vendor_reconciliations')->where('sale_id',$saleId)->first();
        $previous=DB::table('vendor_settlement_entries')->where('sale_id',$saleId)->latest('id')->first();
        $terminal=in_array($order->status,['Cancelled','Returned'],true)||in_array($fulfillment?->status,['Returned','Cancelled'],true);
        $eligible=$fulfillment?->status==='Delivered'&&!$terminal&&$reconciliation&&$reconciliation->courier_cost!==null
            &&$sale->entitlement!==null&&$sale->stitch_charge>=$sale->vendor_stitch
            &&$reconciliation->collected===$sale->product_retail+$sale->stitch_charge+$sale->shipping_share;
        // A changed master refund requires its allocation across individual items to be reviewed.
        $refunds=DB::table('vendor_reconciliations as r')->join('vendor_sale_terms as s','s.id','=','r.sale_id')
            ->where('s.order_id',$order->id)->sum('r.refunded');
        $eligible=$eligible&&self::cents($order->refund_amount)===(int)$refunds;
        $components=[
            'retail'=>$sale->product_retail,'cost'=>$sale->purchase_cost,'profit'=>$sale->desired_profit,'entitlement'=>$sale->entitlement,
            'courier'=>$reconciliation?->courier_cost??0,'shipping'=>$sale->shipping_share,'stitching'=>$sale->stitch_charge,
            'vendor_stitch'=>$sale->vendor_stitch,'stitch_revenue'=>$sale->stitch_charge-$sale->vendor_stitch,'refund'=>$reconciliation?->refunded??0,
        ];
        $components['product_net']=$components['retail']-$components['entitlement']-$components['courier'];
        $earnings=$components['product_net']+$components['shipping']+$components['stitch_revenue']-$components['refund'];
        $courierPaidByVendor=($reconciliation?->courier_payer??'vendor')==='vendor';
        $due=$reconciliation?->collector==='vendor'
            ?$earnings+($courierPaidByVendor?0:$components['courier'])
            :-($sale->entitlement+$sale->vendor_stitch+($courierPaidByVendor?$components['courier']:0));
        $kind='Sale';
        $returnExpense=$terminal && $reconciliation && $reconciliation->courier_cost!==null;
        if($returnExpense){
            $kind='Return expense';
            $components=array_fill_keys(array_keys($components),0);
            $components['courier']=$reconciliation->courier_cost;
            $earnings=-$reconciliation->courier_cost;
            $due=$courierPaidByVendor?-$reconciliation->courier_cost:0;
        }
        $active=$eligible||$returnExpense;
        $previousActive=$previous && in_array($previous->kind,['Sale','Return expense'],true);
        if($previousActive && $active && $previous->kind===$kind && $previous->amount===$due && $previous->earnings===$earnings && json_decode($previous->components,true)==$components) return;
        if($previousActive){
            DB::table('vendor_settlement_entries')->insertOrIgnore([
                'sale_id'=>$saleId,'vendor_id'=>$sale->vendor_id,'revision'=>$previous->revision,'kind'=>'Reversal',
                'components'=>$previous->components,'amount'=>-$previous->amount,'earnings'=>-$previous->earnings,'created_at'=>now(),
            ]);
        }
        if(!$active) return;
        $revision=(int)DB::table('vendor_settlement_entries')->where('sale_id',$saleId)->max('revision')+1;
        DB::table('vendor_settlement_entries')->insert([
            'sale_id'=>$saleId,'vendor_id'=>$sale->vendor_id,'revision'=>$revision,'kind'=>$kind,
            'components'=>json_encode($components,JSON_THROW_ON_ERROR),'amount'=>$due,'earnings'=>$earnings,'created_at'=>now(),
        ]);
    }
}
