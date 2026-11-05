<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  Schema::table('products',function(Blueprint $t){$t->string('publication_status',20)->default('published');$t->json('size_options')->nullable();$t->json('size_chart')->nullable();$t->string('sizing_notes',1000)->nullable();$t->string('video_url',500)->nullable();$t->unsignedInteger('low_stock_threshold')->default(5);$t->boolean('inventory_tracking')->default(true);$t->decimal('weight_kg',8,3)->nullable();$t->string('delivery_restrictions',1000)->nullable();});
  DB::table('products')->where('active',false)->update(['publication_status'=>'archived']);
  Schema::table('categories',fn(Blueprint $t)=>$t->string('image',500)->nullable());
  Schema::table('orders',function(Blueprint $t){$t->string('country',100)->default('Pakistan');$t->string('delivery_method',20)->default('courier');$t->timestamp('dispatched_at')->nullable();$t->timestamp('delivered_at')->nullable();$t->string('delivery_notes',1000)->nullable();});
  Schema::table('order_items',function(Blueprint $t){$t->string('selected_size',5)->nullable();$t->json('size_chart_snapshot')->nullable();$t->boolean('stock_reserved')->default(true);});
  Schema::create('media_assets',function(Blueprint $t){$t->id();$t->string('path',500)->unique();$t->string('kind',10);$t->string('mime',60);$t->unsignedInteger('width')->nullable();$t->unsignedInteger('height')->nullable();$t->unsignedBigInteger('bytes');$t->unsignedBigInteger('uploaded_by')->nullable();$t->timestamp('trashed_at')->nullable();$t->timestamp('created_at')->useCurrent();});
  Schema::create('product_media',function(Blueprint $t){$t->id();$t->unsignedInteger('product_id')->index();$t->unsignedBigInteger('asset_id')->index();$t->unsignedTinyInteger('position')->default(0);$t->boolean('is_primary')->default(false);$t->unique(['product_id','asset_id']);});
  Schema::create('order_email_attempts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('outbox_id')->index();$t->unsignedTinyInteger('attempt');$t->string('outcome',20)->default('sending');$t->timestamp('started_at');$t->timestamp('completed_at')->nullable();$t->string('error',150)->nullable();});
 }
 public function down():void {throw new RuntimeException('Retail records must be preserved. Restore a reviewed backup.');}
};
