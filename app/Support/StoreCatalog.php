<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;

class StoreCatalog
{
    public static function price($v): float { return (float) ($v->sale_enabled && $v->sale_price !== null ? $v->sale_price : $v->regular_price); }
    public static function options($p, $rows, array $settings)
    {
        $base = $rows->firstWhere('type', 'Unstitched');
        return $rows->map(function ($original) use ($p, $base, $settings) {
            $v = clone $original; $v->stitching_charge = 0; $v->stock_variant_id = $v->id;
            $v->price = self::price($v);
            if ($v->type === 'Stitched' && $base) {
                $charge = (float) ($p->stitching_charge ?? $settings['default_stitching_charge']);
                $v->stitching_charge = $charge;
                $v->price = round(self::price($base) + $charge, 2);
                $v->regular_price = round((float) $base->regular_price + $charge, 2);
                $v->sale_price = $base->sale_enabled ? $v->price : null;
                $v->sale_enabled = $base->sale_enabled;
                $v->enabled = (bool) $settings['stitching_enabled'] && (bool) $p->stitching_enabled;
                if ($p->stitching_stock_mode === 'shared') {
                    $v->stock = $base->enabled ? $base->stock : 0; $v->stock_variant_id = $base->id;
                } else $v->enabled = $v->enabled && (bool) $original->enabled;
            }
            $v->available=(bool)$v->enabled&&(!$p->inventory_tracking||$v->stock>0);
            if(!$p->inventory_tracking)$v->stock=null;
            $v->discount = $v->price < $v->regular_price ? (int) round((1 - $v->price / $v->regular_price) * 100) : 0;
            return $v;
        });
    }
    public static function products(bool $public = true, bool $costs = false)
    {
        $settings = BusinessSettings::get(); $variants = DB::table('product_variants')->orderBy('id')->get()->groupBy('product_id');
        return DB::table('products')->when($public, fn ($q) => $q->where('active', 1)->where('publication_status','published'))->orderBy('id')->get()->map(function ($p) use ($variants, $settings, $costs, $public) {
            $all = self::options($p, $variants[$p->id] ?? collect(), $settings); $enabled = $all->where('enabled', true); $lowest = $enabled->sortBy('price')->first();
            if (! $public) $p->raw_variants = ($variants[$p->id] ?? collect())->values();
            $p->variants = $all->values(); $p->price = $lowest?->price ?? (float) $p->price;
            $p->compare_price = $lowest && $lowest->discount ? (float) $lowest->regular_price : 0; $p->discount = $lowest?->discount ?? 0;
            $p->stock = $p->inventory_tracking ? $enabled->unique('stock_variant_id')->sum('stock') : null;
            $p->inventory_tracking=(bool)$p->inventory_tracking;
            $p->size_options=json_decode($p->size_options??'null',true)??SizeGuide::SIZES;
            $p->size_chart=$p->size_chart?json_decode($p->size_chart,true):null;
            $p->effective_size_chart=$p->size_chart??$settings['size_chart'];
            $p->media_metadata=DB::table('product_media as pm')->join('media_assets as ma','ma.id','=','pm.asset_id')->where('pm.product_id',$p->id)->orderBy('pm.position')->get(['ma.path','ma.kind','ma.width','ma.height','ma.bytes','pm.position','pm.is_primary']);
            $p->effective_stitching_charge = (float) ($p->stitching_charge ?? $settings['default_stitching_charge']);
            $p->images = json_decode($p->images ?? 'null', true) ?: array_values(array_filter([$p->image]));
            if (! $costs) unset($p->cost_price, $p->stitching_cost, $p->additional_cost);
            return $p;
        });
    }
    public static function syncStock(int $id): void
    {
        $p = DB::table('products')->find($id); $rows = DB::table('product_variants')->where('product_id', $id)->get();
        $stock = $p->stitching_stock_mode === 'shared' ? $rows->where('type', 'Unstitched')->where('enabled', true)->sum('stock') : $rows->where('enabled', true)->sum('stock');
        DB::table('products')->where('id', $id)->update(['stock' => $stock]);
    }
}
