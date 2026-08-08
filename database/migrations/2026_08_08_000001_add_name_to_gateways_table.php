<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->string('name')->nullable()->after('store_id');
        });

        DB::table('gateways')
            ->whereNull('name')
            ->orderBy('id')
            ->each(function (object $gateway): void {
                DB::table('gateways')
                    ->where('id', $gateway->id)
                    ->update([
                        'name' => ucfirst((string) $gateway->provider) . ' #' . $gateway->id,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
