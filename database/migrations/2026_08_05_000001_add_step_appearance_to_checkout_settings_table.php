<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('step_number_color')->default('#000000');
            $table->string('input_border_radius')->default('medium');
            $table->string('step_button_color')->default('#1b7a2b');
            $table->string('finalize_button_color')->default('#1a3a5c');
            $table->string('step_card_background_color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'step_number_color',
                'input_border_radius',
                'step_button_color',
                'finalize_button_color',
                'step_card_background_color',
            ]);
        });
    }
};
