<?php
namespace App\Support;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
class StitchingMeasurements {
 public static function fields(): array {
  return [
   'shoulder'=>['label'=>'Shoulder','hint'=>'Across the back, shoulder tip to shoulder tip.','min'=>10,'max'=>80,'group'=>'Blouse'],
   'bust'=>['label'=>'Bust / Chest','hint'=>'Around the fullest part of your bust.','min'=>40,'max'=>220,'group'=>'Blouse'],
   'under_bust'=>['label'=>'Under Bust','hint'=>'Around the ribcage just below the bust.','min'=>30,'max'=>200,'group'=>'Blouse'],
   'waist'=>['label'=>'Waist','hint'=>'Around your natural waist, without pulling tight.','min'=>30,'max'=>220,'group'=>'Blouse'],
   'blouse_length'=>['label'=>'Blouse Length','hint'=>'From shoulder to your preferred blouse hem.','min'=>10,'max'=>100,'group'=>'Blouse'],
   'armhole'=>['label'=>'Armhole','hint'=>'Around the shoulder joint where the sleeve joins.','min'=>15,'max'=>100,'group'=>'Blouse'],
   'sleeve_length'=>['label'=>'Sleeve Length','hint'=>'From shoulder tip to the desired sleeve end; zero for sleeveless.','min'=>0,'max'=>100,'group'=>'Blouse'],
   'sleeve_round'=>['label'=>'Sleeve Round','hint'=>'Around the arm at the sleeve opening.','min'=>10,'max'=>100,'group'=>'Blouse'],
   'front_neck_depth'=>['label'=>'Front Neck Depth','hint'=>'From shoulder at the neck to the preferred front neckline.','min'=>0,'max'=>50,'group'=>'Blouse'],
   'back_neck_depth'=>['label'=>'Back Neck Depth','hint'=>'From shoulder at the neck to the preferred back neckline.','min'=>0,'max'=>50,'group'=>'Blouse'],
   'upper_arm'=>['label'=>'Upper Arm','hint'=>'Around the fullest part of the upper arm.','min'=>10,'max'=>100,'group'=>'Blouse'],
   'pallu_length'=>['label'=>'Pallu Length','hint'=>'Your preferred draped pallu length, if applicable.','min'=>30,'max'=>400,'group'=>'Saree'],
   'saree_length'=>['label'=>'Saree Length','hint'=>'The full saree length requested for this service.','min'=>100,'max'=>1000,'group'=>'Saree'],
  ];
 }
 public static function services(): array {
  $blouse=array_keys(array_filter(self::fields(),fn($f)=>$f['group']==='Blouse'));
  return ['blouse'=>['label'=>'Blouse tailoring','fields'=>$blouse,'required'=>['shoulder','bust','under_bust','waist','blouse_length','armhole','sleeve_length','sleeve_round','front_neck_depth','back_neck_depth','upper_arm']],
   'saree'=>['label'=>'Saree tailoring','fields'=>['waist','pallu_length','saree_length'],'required'=>['waist','saree_length']],
   'blouse_saree'=>['label'=>'Blouse & saree tailoring','fields'=>array_keys(self::fields()),'required'=>array_merge($blouse,['saree_length'])]];
 }
 public static function normalize(?array $data,string $service,array $settings,string $name): array {
  if(!$data)throw ValidationException::withMessages(['measurements'=>"Please provide stitching measurements for {$name}."]);
  $v=Validator::make($data,['unit'=>'required|in:cm,in','values'=>'present|array|max:13','instructions'=>'nullable|string|max:1000','fit'=>'nullable|in:Regular,Relaxed,Close'])->validate();
  $config=$settings['measurement_services'][$service]??null;
  if(!$config)throw ValidationException::withMessages(['measurements'=>'This tailoring service is not configured. Please contact the store.']);
  $factor=$v['unit']==='in'?2.54:1; $normalized=[];$submitted=[];
  foreach(self::fields() as $key=>$field){
   $value=$v['values'][$key]??null;
   if(!in_array($key,$config['fields']))continue;
   if($value===null||$value===''){if(in_array($key,$config['required']))throw ValidationException::withMessages(['measurements'=>"{$field['label']} is required for {$name}."]);continue;}
   if(!is_numeric($value)||!is_finite((float)$value))throw ValidationException::withMessages(['measurements'=>"Enter a valid {$field['label']} for {$name}."]);
   $cm=round((float)$value*$factor,2);
   if($cm<$field['min']||$cm>$field['max'])throw ValidationException::withMessages(['measurements'=>"{$field['label']} for {$name} must be between {$field['min']} and {$field['max']} cm (or equivalent inches)."]);
   $normalized[$key]=$cm;$submitted[$key]=round((float)$value,4);
  }
  return ['service'=>$service,'service_label'=>$config['label'],'unit'=>$v['unit'],'submitted'=>$submitted,'values_cm'=>$normalized,'instructions'=>$v['instructions']??'','fit'=>$v['fit']??'Regular'];
 }
}
