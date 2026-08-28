<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $previousColumn = 'upsell_button_text_color';

        foreach ([
            'downsell_bg_color' => '#FFFFFF',
            'downsell_border_color' => '#E2E8F0',
            'downsell_text_color' => '#1A1A1A',
            'downsell_button_color' => '#22C55E',
            'downsell_button_text_color' => '#FFFFFF',
        ] as $column => $default) {
            // A previous deployment may have created only some columns before failing.
            // Keep existing definitions and saved colors; add only the missing fields.
            if (! Schema::hasColumn('checkout_settings', $column)) {
                Schema::table('checkout_settings', function (Blueprint $table) use ($column, $default, $previousColumn) {
                    $table->string($column)->default($default)->after($previousColumn);
                });
            }

            $previousColumn = $column;
        }
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
