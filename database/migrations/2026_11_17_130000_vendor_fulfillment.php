<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('vendor_profiles','fulfillment_enabled')) Schema::table('vendor_profiles',function(Blueprint $t){
            $t->boolean('fulfillment_enabled')->default(true);
            $t->string('logo',200)->nullable();
            $t->string('vendor_name',100)->nullable();
        });
        Schema::create('vendor_fulfillments', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('order_item_id')->unique();
            $t->unsignedInteger('order_id')->index();
            $t->unsignedBigInteger('vendor_id')->index();
            $t->unsignedBigInteger('assigned_by');
            $t->string('status',32);
            $t->json('snapshot');
            $t->dateTime('assigned_at');
            $t->dateTime('accept_by');
            $t->dateTime('dispatch_by');
            $t->dateTime('vendor_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fulfillment_id')->unique()->constrained('vendor_fulfillments');
            $t->string('courier',100);
            $t->string('service',100)->nullable();
            $t->string('tracking',100);
            $t->string('tracking_url',500)->nullable();
            $t->date('dispatched_on');
            $t->date('expected_on');
            $t->dateTime('delivered_at')->nullable();
            $t->text('notes')->nullable();
            $t->string('receipt')->nullable();
        });
        Schema::create('vendor_fulfillment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fulfillment_id')->constrained('vendor_fulfillments');
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('vendor_id');
            $t->unsignedBigInteger('previous_vendor_id')->nullable();
            $t->string('action',32);
            $t->string('status',32);
            $t->text('notes')->nullable();
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a backup to preserve fulfillment history.');
    }
};
