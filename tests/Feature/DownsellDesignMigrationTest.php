<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DownsellDesignMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_26_000002_add_downsell_design_to_checkout_settings';

    private const DEFAULTS = [
        'downsell_bg_color' => '#FFFFFF',
        'downsell_border_color' => '#E2E8F0',
        'downsell_text_color' => '#1A1A1A',
        'downsell_button_color' => '#22C55E',
        'downsell_button_text_color' => '#FFFFFF',
    ];

    public static function existingColumnCombinations(): iterable
    {
        $columns = array_keys(self::DEFAULTS);
        // Cover every subset, including no existing columns and all five existing.
        for ($mask = 0; $mask < (1 << count($columns)); $mask++) {
            $existing = [];
            foreach ($columns as $index => $column) {
                if ($mask & (1 << $index)) {
                    $existing[] = $column;
                }
            }
            yield 'existing-columns-'.$mask => [$existing];
        }
    }

    #[DataProvider('existingColumnCombinations')]
    public function test_pending_migration_completes_without_overwriting_existing_colors(array $existing): void
    {
        $path = 'database/migrations/'.self::MIGRATION.'.php';
        $migration = require base_path($path);
        $migration->down();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        if ($existing !== []) {
            Schema::table('checkout_settings', function (Blueprint $table) use ($existing) {
                foreach ($existing as $column) {
                    $table->string($column)->default('#123456');
                }
            });
        }

        $store = User::factory()->create()->stores()->create([
            'name' => 'Loja de teste da migração',
            'subdomain' => 'migration-test',
        ]);
        $settings = $store->checkoutSettings()->create([
            'primary_color' => '#ABCDEF',
            ...array_fill_keys($existing, '#654321'),
        ]);

        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        // Also tolerate an explicit retry without relying on the migration ledger.
        $migration->up();

        $settings->refresh();
        foreach (self::DEFAULTS as $column => $default) {
            $this->assertTrue(Schema::hasColumn('checkout_settings', $column));
            $this->assertSame(in_array($column, $existing, true) ? '#654321' : $default, $settings->{$column});
        }
        $this->assertSame('#ABCDEF', $settings->primary_color);
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
    }
}
