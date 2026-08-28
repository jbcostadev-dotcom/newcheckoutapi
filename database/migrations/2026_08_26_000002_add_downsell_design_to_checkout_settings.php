<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLORS = [
        'downsell_bg_color' => '#FFFFFF',
        'downsell_border_color' => '#E2E8F0',
        'downsell_text_color' => '#1A1A1A',
        'downsell_button_color' => '#22C55E',
        'downsell_button_text_color' => '#FFFFFF',
    ];

    public function up(): void
    {
        // Free row space before adding anything, including after a partially applied migration.
        $this->compactExistingColors();
        $previousColumn = 'upsell_button_text_color';

        foreach (self::COLORS as $column => $default) {
            // A previous deployment may have created only some columns before failing.
            // Keep saved colors; add only the missing fields.
            if (! Schema::hasColumn('checkout_settings', $column)) {
                Schema::table('checkout_settings', function (Blueprint $table) use ($column, $default, $previousColumn) {
                    $table->string($column, 20)->default($default)->after($previousColumn);
                });
            }

            $previousColumn = $column;
        }
    }

    private function compactExistingColors(): void
    {
        // SQLite has no VARCHAR row budget and reports defaults differently from MySQL.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (Schema::getColumns('checkout_settings') as $definition) {
            $column = $definition['name'];
            if (! array_key_exists($column, self::COLORS)
                || ! preg_match('/^varchar\((\d+)\)$/i', $definition['type'], $matches)
                || $definition['generation'] !== null) {
                continue;
            }

            $currentLength = (int) $matches[1];
            if ($currentLength <= 20) {
                continue;
            }

            $wrapped = DB::connection()->getQueryGrammar()->wrap($column);
            $storedLength = (int) DB::table('checkout_settings')->max(DB::raw("CHAR_LENGTH({$wrapped})"));
            // Preserve legacy values and defaults longer than the API's current 20-character limit.
            $length = max(20, $storedLength, mb_strlen((string) $definition['default']));
            if ($length >= $currentLength) {
                continue;
            }

            Schema::table('checkout_settings', function (Blueprint $table) use ($column, $length, $definition) {
                $table->string($column, $length)
                    ->nullable($definition['nullable'])
                    ->default($definition['default'])
                    ->collation($definition['collation'])
                    ->comment($definition['comment'] ?? '')
                    ->change();
            });
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
