<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('upsell_bg_color')->default('#FFFFFF')->after('order_bump_button_text_color');
            $table->string('upsell_border_color')->default('#E2E8F0')->after('upsell_bg_color');
            $table->string('upsell_text_color')->default('#1A1A1A')->after('upsell_border_color');
            $table->string('upsell_button_color')->default('#22C55E')->after('upsell_text_color');
            $table->string('upsell_button_text_color')->default('#FFFFFF')->after('upsell_button_color');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'upsell_bg_color',
                'upsell_border_color',
                'upsell_text_color',
                'upsell_button_color',
                'upsell_button_text_color',
            ]);
        });
    }
};
