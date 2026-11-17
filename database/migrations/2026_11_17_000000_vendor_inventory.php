<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedInteger('product_id')->nullable()->unique();
            $table->json('details');
            $table->string('status', 20)->default('Pending');
            $table->boolean('show_code')->default(false);
            $table->decimal('approved_payable', 12, 2)->nullable();
            $table->decimal('retail_price', 12, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_order_payables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id')->index();
            $table->unsignedInteger('order_id')->index();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_payable', 12, 2);
            $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_order_payables');
        Schema::dropIfExists('vendor_listings');
    }
};
