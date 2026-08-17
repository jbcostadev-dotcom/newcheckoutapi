<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('gift_bg_color')->default('#F7FFFA')->after('upsell_button_text_color');
            $table->string('gift_border_color')->default('#A4DFC1')->after('gift_bg_color');
            $table->string('gift_badge_bg_color')->default('#FFFFFF')->after('gift_border_color');
            $table->string('gift_badge_border_color')->default('#6EE7B7')->after('gift_badge_bg_color');
            $table->string('gift_badge_text_color')->default('#10B981')->after('gift_badge_border_color');
            $table->string('gift_progress_color')->default('#10B981')->after('gift_badge_text_color');
            $table->string('gift_progress_bg_color')->default('#E5E7EB')->after('gift_progress_color');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'gift_bg_color',
                'gift_border_color',
                'gift_badge_bg_color',
                'gift_badge_border_color',
                'gift_badge_text_color',
                'gift_progress_color',
                'gift_progress_bg_color',
            ]);
        });
    }
};
