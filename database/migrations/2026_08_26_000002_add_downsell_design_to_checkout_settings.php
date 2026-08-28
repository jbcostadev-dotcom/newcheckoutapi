<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('downsell_bg_color')->default('#FFFFFF')->after('upsell_button_text_color');
            $table->string('downsell_border_color')->default('#E2E8F0')->after('downsell_bg_color');
            $table->string('downsell_text_color')->default('#1A1A1A')->after('downsell_border_color');
            $table->string('downsell_button_color')->default('#22C55E')->after('downsell_text_color');
            $table->string('downsell_button_text_color')->default('#FFFFFF')->after('downsell_button_color');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'downsell_bg_color',
                'downsell_border_color',
                'downsell_text_color',
                'downsell_button_color',
                'downsell_button_text_color',
            ]);
        });
    }
};
