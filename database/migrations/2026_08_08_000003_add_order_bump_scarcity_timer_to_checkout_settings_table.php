<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->boolean('order_bump_scarcity_timer_enabled')
                ->default(false)
                ->after('enable_order_bump');
            $table->unsignedInteger('order_bump_scarcity_timer_minutes')
                ->default(10)
                ->after('order_bump_scarcity_timer_enabled');
        });

        DB::table('checkout_settings')
            ->whereIn('store_id', function ($query) {
                $query->select('store_id')
                    ->from('order_bumps')
                    ->where('scarcity_timer_enabled', true);
            })
            ->update(['order_bump_scarcity_timer_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'order_bump_scarcity_timer_enabled',
                'order_bump_scarcity_timer_minutes',
            ]);
        });
    }
};
