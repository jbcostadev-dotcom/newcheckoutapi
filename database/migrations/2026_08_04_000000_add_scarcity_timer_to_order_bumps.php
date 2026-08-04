<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_bumps', function (Blueprint $table) {
            $table->boolean('scarcity_timer_enabled')->default(false);
            $table->unsignedInteger('scarcity_timer_minutes')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('order_bumps', function (Blueprint $table) {
            $table->dropColumn(['scarcity_timer_enabled', 'scarcity_timer_minutes']);
        });
    }
};
