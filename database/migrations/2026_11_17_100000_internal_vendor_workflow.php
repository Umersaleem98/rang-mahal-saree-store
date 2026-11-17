<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_listings',function(Blueprint $t){
            $t->string('inventory_code',64)->nullable()->unique();
            $t->text('review_reason')->nullable();
        });
        Schema::create('vendor_profiles',function(Blueprint $t){
            $t->unsignedBigInteger('user_id')->primary();
            $t->string('contact_person',100)->nullable();
            $t->string('email',190)->nullable();
            $t->string('phone',40)->nullable();
            $t->text('address')->nullable();
        });
        Schema::create('vendor_reviews',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('listing_id')->index(); $t->unsignedBigInteger('reviewer_id');
            $t->string('status',30); $t->decimal('approved_price',12,2)->nullable();
            $t->text('reason')->nullable(); $t->timestamp('created_at');
        });
        Schema::create('vendor_purchases',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('listing_id')->index(); $t->unsignedInteger('quantity');
            $t->decimal('unit_price',12,2); $t->timestamp('received_at');
        });
        Schema::create('vendor_payments',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('purchase_id')->index();
            $t->decimal('amount',14,2); $t->timestamp('paid_at');
        });
        Schema::table('vendor_order_payables',function(Blueprint $t){
            $t->unsignedBigInteger('order_item_id')->nullable()->unique();
            $t->unsignedBigInteger('vendor_id')->nullable();
            $t->string('vendor_name',100)->nullable();
            $t->string('inventory_code',64)->nullable();
        });
        DB::table('vendor_listings')->where('status','Published')->update(['status'=>'Approved']);
        DB::table('vendor_listings')->where('status','Unpublished')->update(['status'=>'Approved']);
    }
    public function down(): void
    {
        throw new RuntimeException('Restore a backup to preserve the vendor ledger.');
    }
};
