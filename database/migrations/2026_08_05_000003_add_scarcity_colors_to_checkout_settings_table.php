<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('scarcity_font_color')->default('#000000');
            $table->string('scarcity_counter_color')->default('#000000');
            $table->string('scarcity_counter_text_color')->default('#ffffff');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'scarcity_font_color',
                'scarcity_counter_color',
                'scarcity_counter_text_color',
            ]);
        });
    }
};
