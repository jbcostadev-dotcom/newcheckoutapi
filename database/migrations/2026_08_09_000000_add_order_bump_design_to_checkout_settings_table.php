<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store a single visual design that is shared by every Order Bump in a checkout.
     */
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('order_bump_bg_color')->default('#FEFCE8')->after('order_bump_display_mode');
            $table->string('order_bump_border_color')->default('#E2E8F0')->after('order_bump_bg_color');
            $table->string('order_bump_button_color')->default('#13BF8C')->after('order_bump_border_color');
            $table->string('order_bump_button_text_color')->default('#FFFFFF')->after('order_bump_button_color');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'order_bump_bg_color',
                'order_bump_border_color',
                'order_bump_button_color',
                'order_bump_button_text_color',
            ]);
        });
    }
};
