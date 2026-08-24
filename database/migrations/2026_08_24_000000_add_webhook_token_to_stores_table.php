<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('webhook_token')->nullable()->after('status');
        });

        DB::table('stores')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($stores) {
                foreach ($stores as $store) {
                    $existingToken = DB::table('webhooks')
                        ->where('store_id', $store->id)
                        ->oldest('id')
                        ->value('token');

                    $encryptedToken = $existingToken ?: Crypt::encryptString(Str::random(48));

                    DB::table('stores')
                        ->where('id', $store->id)
                        ->update(['webhook_token' => $encryptedToken]);

                    DB::table('webhooks')
                        ->where('store_id', $store->id)
                        ->update(['token' => $encryptedToken]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('webhook_token');
        });
    }
};
