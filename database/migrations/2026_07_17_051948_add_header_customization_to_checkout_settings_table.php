<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('header_logo_alignment')->nullable();
            $table->string('header_bg_color')->nullable();
            $table->string('header_icon_color')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn(['header_logo_alignment', 'header_bg_color', 'header_icon_color']);
        });
    }
};
