<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
class MediaLibrary {
 public static function sync(int $product,array $images,?string $video):void {
  DB::table('product_media')->where('product_id',$product)->delete();
  foreach(array_values(array_filter([...$images,$video])) as $position=>$path){$asset=DB::table('media_assets')->where('path',$path)->lockForUpdate()->first();if($asset)abort_if($asset->trashed_at!==null,422,'An uploaded asset has expired. Please upload it again.');if($asset)DB::table('product_media')->insert(['product_id'=>$product,'asset_id'=>$asset->id,'position'=>$position,'is_primary'=>$position===0]);}
 }
 public static function videoRules():array {return ['nullable','string','max:500','regex:~^(?:/media/videos/[a-f0-9]{32}\.(?:mp4|webm)|https://[^\s]+\.(?:mp4|webm)(?:\?[^\s]*)?)$~i'];}
}
