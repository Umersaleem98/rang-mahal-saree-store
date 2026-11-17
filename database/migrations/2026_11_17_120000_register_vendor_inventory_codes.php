<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_inventory_codes',function(Blueprint $table){
            $table->id();
            $table->foreignId('listing_id')->constrained('vendor_listings');
            $table->string('code',64)->nullable()->unique();
            $table->timestamp('created_at');
        });
        DB::table('vendor_listings')->orderBy('id')->chunkById(200,function($rows){
            foreach($rows as $row){
                $code=$row->inventory_code??'HS-SAR-'.str_pad((string)$row->id,4,'0',STR_PAD_LEFT);
                DB::table('vendor_inventory_codes')->insert(['id'=>$row->id,'listing_id'=>$row->id,'code'=>$code,'created_at'=>now()]);
                DB::table('vendor_listings')->where('id',$row->id)->update(['inventory_code'=>$code]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a backup to preserve issued vendor codes.');
    }
};
