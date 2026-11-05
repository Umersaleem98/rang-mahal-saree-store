<?php
namespace App\Support;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
class SizeGuide {
 public const SIZES=['XS','S','M','L','XL'];
 public static function fields():array {return ['bust'=>'Blouse bust / chest','waist'=>'Blouse waist','shoulder'=>'Blouse shoulder','sleeve_length'=>'Blouse sleeve length','sleeve_opening'=>'Blouse sleeve opening','blouse_length'=>'Blouse length','armhole'=>'Blouse armhole','neck_depth'=>'Blouse neck depth','saree_length'=>'Saree length','saree_width'=>'Saree width','pallu_length'=>'Pallu length','pallu_width'=>'Pallu width'];}
 public static function defaults():array {return ['unit'=>'in','notes'=>'Finished garment dimensions must be confirmed by Rang Mahal. Blank values are not yet provided; contact the store for fit advice.','rows'=>array_fill_keys(self::SIZES,array_fill_keys(array_keys(self::fields()),null))];}
 public static function reference():array {return ['source'=>'Suta published blouse size guide','url'=>'https://suta.in/products/code-white','basis'=>'Body measurements only — not Rang Mahal finished garment dimensions and not a universal sizing standard.','unit'=>'in','rows'=>['XS'=>['bust'=>33,'waist'=>27,'shoulder'=>13.5],'S'=>['bust'=>35,'waist'=>29,'shoulder'=>14],'M'=>['bust'=>37,'waist'=>31,'shoulder'=>14.5],'L'=>['bust'=>39,'waist'=>33,'shoulder'=>15],'XL'=>['bust'=>41,'waist'=>35,'shoulder'=>15.5]]];}
 public static function validate(array $chart):array {
  $rules=['unit'=>'required|in:in,cm','notes'=>'nullable|string|max:1000','rows'=>'required|array:XS,S,M,L,XL'];
  foreach(self::SIZES as $size){$rules["rows.$size"]='required|array:'.implode(',',array_keys(self::fields()));foreach(self::fields() as $key=>$label)$rules["rows.$size.$key"]='nullable|numeric|min:0|max:1500';}
  $v=Validator::make($chart,$rules)->validate();return ['unit'=>$v['unit'],'notes'=>$v['notes']??'','rows'=>collect(self::SIZES)->mapWithKeys(fn($s)=>[$s=>array_replace(array_fill_keys(array_keys(self::fields()),null),$v['rows'][$s])])->all()];
 }
 public static function effective($p,array $settings):array {return $p->size_chart?json_decode($p->size_chart,true):$settings['size_chart'];}
}
