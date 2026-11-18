<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('vendor_reconciliations',fn(Blueprint $t)=>$t->string('courier_payer',10)->default('vendor'));
    }
    public function down():void
    {
        throw new RuntimeException('Restore a backup to retain expense attribution.');
    }
};
