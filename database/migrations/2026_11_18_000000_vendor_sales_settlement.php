<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('vendor_sale_terms',function(Blueprint $t){
            $t->id();$t->unsignedInteger('order_item_id')->unique();$t->unsignedInteger('order_id')->index();
            $t->unsignedBigInteger('vendor_id')->index();$t->unsignedBigInteger('listing_id');
            $t->string('code',64);$t->string('product_name',150);$t->string('vendor_name',100);
            $t->unsignedInteger('quantity');
            foreach(['purchase_cost','desired_profit','entitlement','product_retail','stitch_charge','vendor_stitch','shipping_share'] as $column) $t->bigInteger($column)->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('vendor_reconciliations',function(Blueprint $t){
            $t->unsignedBigInteger('sale_id')->primary();
            $t->bigInteger('courier_cost')->nullable();
            $t->bigInteger('collected')->default(0);$t->bigInteger('refunded')->default(0);
            $t->string('collector',10)->default('vendor');$t->text('notes');
            $t->unsignedBigInteger('actor_id');$t->unsignedInteger('revision')->default(1);$t->timestamp('updated_at');
        });
        Schema::create('vendor_settlement_entries',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('sale_id')->index();$t->unsignedBigInteger('vendor_id')->index();
            $t->unsignedInteger('revision');$t->string('kind',20);$t->json('components');
            $t->bigInteger('amount');$t->bigInteger('earnings');
            $t->timestamp('created_at');$t->unique(['sale_id','revision','kind']);
        });
        Schema::create('vendor_reconciliation_audits',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('sale_id')->index();$t->unsignedInteger('revision');
            $t->json('payload');$t->timestamp('created_at');$t->unique(['sale_id','revision']);
        });
        Schema::create('vendor_remittances',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('vendor_id')->index();$t->uuid('token')->unique();
            $t->bigInteger('amount');$t->date('paid_on');$t->string('method',50);$t->string('reference',150);
            $t->string('account',150)->nullable();$t->text('remarks')->nullable();$t->string('receipt')->nullable();
            $t->unsignedBigInteger('actor_id');$t->timestamp('created_at');
        });
        Schema::create('vendor_stitch_progress',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('fulfillment_id')->index();$t->string('status',40);
            $t->unsignedBigInteger('actor_id');$t->timestamp('created_at');
        });
    }

    public function down():void
    {
        throw new RuntimeException('Restore a backup to retain financial records.');
    }
};
