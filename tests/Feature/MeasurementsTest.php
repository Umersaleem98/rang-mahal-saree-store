<?php
namespace Tests\Feature;
use App\Models\ShopUser;
use App\Support\BusinessSettings;
use App\Support\StitchingMeasurements;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class MeasurementsTest extends TestCase {
 use DatabaseTransactions;
 private function product($service='blouse'): array {
  $id=DB::table('products')->insertGetId(['name'=>'QA Fit '.Str::uuid(),'category'=>DB::table('categories')->value('name'),'brand'=>DB::table('brands')->value('name'),'price'=>1000,'stock'=>8,'image'=>'/images/gold.jpg','stitching_service'=>$service]);
  $a=DB::table('product_variants')->insertGetId(['product_id'=>$id,'type'=>'Unstitched','regular_price'=>1000,'stock'=>8,'enabled'=>1]);
  $b=DB::table('product_variants')->insertGetId(['product_id'=>$id,'type'=>'Stitched','regular_price'=>1,'stock'=>0,'enabled'=>0]);return [$id,$a,$b];
 }
 private function measurements($unit='cm'): array {return ['unit'=>$unit,'values'=>['shoulder'=>38.1,'bust'=>91.44,'under_bust'=>76.2,'waist'=>71.12,'blouse_length'=>38.1,'armhole'=>40.64,'sleeve_length'=>20.32,'sleeve_round'=>30.48,'front_neck_depth'=>15.24,'back_neck_depth'=>12.7,'upper_arm'=>30.48],'fit'=>'Relaxed','instructions'=>'Boat neckline, please.'];}
 private function payload($items): array {return ['name'=>'QA Fit Buyer','email'=>'fit@example.test','phone'=>'03001234567','address'=>'QA only','city'=>'Lahore','postal_code'=>'54000','payment_method'=>'cod','checkout_token'=>(string)Str::uuid(),'items'=>$items];}
 private function owner($role='super_admin'): void { $id=DB::table('users')->insertGetId(['name'=>'QA Staff','email'=>Str::uuid().'@example.test','password_hash'=>bcrypt('Strong test password!'),'role'=>$role]);$u=ShopUser::find($id);$this->withSession(['auth_version'=>$u->auth_version]);$this->actingAs($u); }
 public function test_mixed_lines_save_correct_profiles_and_units_and_preserve_idempotency(): void {
  [$p,$a,$b]=$this->product();[$q,$c,$d]=$this->product('saree');$m=$this->measurements('in');$m['values']=array_map(fn($v)=>$v/2.54,$m['values']);$body=$this->payload([['id'=>$p,'variant_id'=>$b,'quantity'=>2,'measurements'=>$m],['id'=>$q,'variant_id'=>$d,'quantity'=>1,'measurements'=>['unit'=>'cm','values'=>['waist'=>80,'saree_length'=>550],'fit'=>'Regular']],['id'=>$p,'variant_id'=>$a,'quantity'=>1]]);
  $r=$this->postJson('/api/orders',$body)->assertCreated();$id=DB::table('orders')->where('reference',$r->json('reference'))->value('id');$lines=DB::table('order_items')->where('order_id',$id)->get()->keyBy('variant_id');$profile=json_decode($lines[$b]->measurement_data,true);
  $this->assertEquals('in',$profile['unit']);$this->assertEquals(91.44,$profile['values_cm']['bust']);$this->assertEquals(36,$profile['submitted']['bust']);$this->assertEquals('Relaxed',$profile['fit']);$this->assertEquals(2,$lines[$b]->quantity);$this->assertNull($lines[$a]->measurement_data);$this->assertEquals(550,json_decode($lines[$d]->measurement_data,true)['values_cm']['saree_length']);
  $this->postJson('/api/orders',$body)->assertCreated()->assertJsonPath('reference',$r->json('reference'));$this->assertEquals(5,DB::table('product_variants')->find($a)->stock);$body['items'][0]['measurements']['values']['bust']=37;$this->postJson('/api/orders',$body)->assertStatus(409);
  $this->postJson('/api/track',['reference'=>$r->json('reference'),'email'=>'fit@example.test'])->assertOk()->assertJsonMissingPath('order.items.0.measurement_data');
  $this->getJson('/api/admin/order-items?id='.$id)->assertForbidden();$this->owner('order_manager');$this->getJson('/api/admin/order-items?id='.$id)->assertOk()->assertJsonPath('items.0.measurement_data',$lines->first()->measurement_data);
 }
 public function test_invalid_or_missing_measurements_do_not_reserve_stock(): void {
  [$p,$a,$b]=$this->product();$line=['id'=>$p,'variant_id'=>$b,'quantity'=>1];$orders=DB::table('orders')->count();$this->postJson('/api/orders',$this->payload([$line]))->assertUnprocessable();
  foreach([['unit'=>'cm','values'=>['bust'=>-1]],['unit'=>'yards','values'=>[]],['unit'=>'cm','values'=>['shoulder'=>99999]],['unit'=>'cm','values'=>['shoulder'=>[38]]]] as $m)$this->postJson('/api/orders',$this->payload([[...$line,'measurements'=>$m]]))->assertUnprocessable();
  $this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$a,'quantity'=>1,'measurements'=>$this->measurements()]]))->assertUnprocessable();$this->assertEquals($orders,DB::table('orders')->count());$this->assertEquals(8,DB::table('product_variants')->find($a)->stock);
  $this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$a,'quantity'=>1]]))->assertCreated();
 }
 public function test_configurable_services_social_urls_and_permissions(): void {
  $services=StitchingMeasurements::services();$services['blouse']['fields']=['waist','bust'];$services['blouse']['required']=['waist'];$this->owner('staff');$this->putJson('/api/admin/business-settings',['measurement_services'=>$services])->assertForbidden();$this->owner();$this->putJson('/api/admin/business-settings',['measurement_services'=>$services,'whatsapp_url'=>'javascript:alert(1)'])->assertUnprocessable();$this->putJson('/api/admin/business-settings',['measurement_services'=>$services,'whatsapp_url'=>''])->assertOk();
  [$p,$a,$b]=$this->product();$this->postJson('/api/orders',$this->payload([['id'=>$p,'variant_id'=>$b,'quantity'=>1,'measurements'=>['unit'=>'in','values'=>['waist'=>28]]]]))->assertCreated();$services['blouse']['required']=['shoulder'];$this->putJson('/api/admin/business-settings',['measurement_services'=>$services])->assertUnprocessable();
  $d=$this->getJson('/api/catalog')->assertOk();$this->assertArrayHasKey('bust',$d->json('stitching_fields'));$this->assertStringContainsString('instagram.com',$d->json('business.instagram_url'));$d->assertJsonPath('business.whatsapp_url',null);
 }
}
