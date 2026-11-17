<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;

return new class extends Migration {
    public function up():void
    {
        DB::transaction(function(){
            $vendor=DB::table('users')->where('role','vendor')->where('name','Harveer Singh')->first();
            if ($vendor) return;
            $id=DB::table('users')->insertGetId([
                'name'=>'Harveer Singh','email'=>'harveer.vendor@example.invalid',
                'password_hash'=>Hash::make(Str::random(48)),'role'=>'vendor',
                'active'=>false,'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('vendor_profiles')->insert(['user_id'=>$id,'vendor_name'=>'Harveer Singh']);
        });
    }

    public function down():void
    {
        throw new RuntimeException('Restore a backup to preserve the account.');
    }
};
