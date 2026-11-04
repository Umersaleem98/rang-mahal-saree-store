<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('products',fn(Blueprint $t)=>$t->string('stitching_service',30)->default('blouse'));
  Schema::table('order_items',fn(Blueprint $t)=>$t->json('measurement_data')->nullable());
 }
 public function down(): void { throw new RuntimeException('Preserve tailoring records; use a reviewed backup.'); }
};
