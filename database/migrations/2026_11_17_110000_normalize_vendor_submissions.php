<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_submission_images',function(Blueprint $t){
            $t->id();$t->foreignId('listing_id')->constrained('vendor_listings');
            $t->string('path',200);$t->unsignedTinyInteger('position');
            $t->unique(['listing_id','position']);
        });
        Schema::create('vendor_submission_finances',function(Blueprint $t){
            $t->foreignId('listing_id')->primary()->constrained('vendor_listings');
            $t->decimal('purchase_cost',12,2)->nullable();$t->decimal('margin',12,2);
            $t->decimal('payable',12,2);$t->decimal('proposed_price',12,2)->nullable();
        });
        foreach(DB::table('vendor_listings')->orderBy('id')->cursor() as $row){
            $details=json_decode($row->details,true,flags:JSON_THROW_ON_ERROR);
            foreach($details['images']??[] as $position=>$path) DB::table('vendor_submission_images')->insert(['listing_id'=>$row->id,'path'=>$path,'position'=>$position]);
            $margin=$details['margin']??0;
            if(($details['margin_type']??null)==='percent') $margin=round(($details['purchase_cost']??0)*$margin/100,2);
            DB::table('vendor_submission_finances')->insert(['listing_id'=>$row->id,'purchase_cost'=>$details['purchase_cost']??null,'margin'=>$margin,'payable'=>$details['payable']??0,'proposed_price'=>$details['proposed_price']??null]);
        }
    }
    public function down(): void
    {
        throw new RuntimeException('Restore a backup to preserve vendor records.');
    }
};
