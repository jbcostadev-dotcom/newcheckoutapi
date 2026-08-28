<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Runs only against an explicitly selected disposable local MySQL database. */
class DownsellDesignMySqlTest extends TestCase
{
    private bool $ownsTestTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('DOWNSELL_MYSQL_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Requires DOWNSELL_MYSQL_TEST_DATABASE and a disposable local MySQL server.');
        }
        if (! preg_match('/^checkout_downsell_test_[a-zA-Z0-9_]+$/', $database)) {
            throw new \RuntimeException('The test database must use the checkout_downsell_test_ prefix.');
        }

        config([
            'database.default' => 'downsell_mysql_test',
            'database.connections.downsell_mysql_test' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => getenv('DOWNSELL_MYSQL_TEST_PORT') ?: '3306',
                'database' => $database,
                'username' => getenv('DOWNSELL_MYSQL_TEST_USER') ?: 'root',
                'password' => getenv('DOWNSELL_MYSQL_TEST_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
        ]);
        // Never replace a table that was not created by this test.
        if (Schema::hasTable('checkout_settings')) {
            throw new \RuntimeException('Use an empty disposable test database.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownsTestTable) {
            Schema::dropIfExists('checkout_settings');
        }
        parent::tearDown();
    }

    public function test_partial_deploy_reproduces_error_1118_then_migrates_without_losing_data(): void
    {
        Schema::create('checkout_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('upsell_button_text_color', 20)->default('#FFFFFF');
            // 61 wide fields + 3 existing Downsell fields leave almost no row budget.
            for ($index = 0; $index < 61; $index++) {
                $table->string('existing_setting_'.$index, 255)->nullable();
            }
            $table->string('downsell_bg_color', 255)->default('#123456')->comment('Keep this comment');
            $table->string('downsell_border_color', 255)->nullable()->default('#ABCDEF');
            $table->string('downsell_text_color', 255)->default('#1A1A1A');
        });
        $this->ownsTestTable = true;

        $legacyColor = 'var(--legacy-color-name-longer-than-twenty-characters)';
        $id = DB::table('checkout_settings')->insertGetId([
            'existing_setting_0' => 'preserve unrelated setting',
            'downsell_bg_color' => '#654321',
            'downsell_border_color' => null,
            'downsell_text_color' => $legacyColor,
        ]);

        try {
            Schema::table('checkout_settings', function (Blueprint $table) {
                $table->string('downsell_button_color', 255)->default('#22C55E');
            });
            $this->fail('The old VARCHAR(255) migration should exceed the MySQL row budget.');
        } catch (QueryException $exception) {
            $this->assertSame(1118, $exception->errorInfo[1]);
        }

        $migration = require database_path('migrations/2026_08_26_000002_add_downsell_design_to_checkout_settings.php');
        $migration->up();
        $migration->up();

        $row = DB::table('checkout_settings')->find($id);
        $this->assertSame('#654321', $row->downsell_bg_color);
        $this->assertNull($row->downsell_border_color);
        $this->assertSame($legacyColor, $row->downsell_text_color);
        $this->assertSame('#22C55E', $row->downsell_button_color);
        $this->assertSame('#FFFFFF', $row->downsell_button_text_color);
        $this->assertSame('preserve unrelated setting', $row->existing_setting_0);

        $columns = collect(Schema::getColumns('checkout_settings'))->keyBy('name');
        foreach (['downsell_bg_color', 'downsell_border_color', 'downsell_button_color', 'downsell_button_text_color'] as $column) {
            $this->assertSame('varchar(20)', $columns[$column]['type']);
        }
        $this->assertSame('varchar('.mb_strlen($legacyColor).')', $columns['downsell_text_color']['type']);
        $this->assertSame('#123456', $columns['downsell_bg_color']['default']);
        $this->assertSame('Keep this comment', $columns['downsell_bg_color']['comment']);
        $this->assertTrue($columns['downsell_border_color']['nullable']);
        $this->assertSame('#ABCDEF', $columns['downsell_border_color']['default']);
        $this->assertSame('utf8mb4_unicode_ci', $columns['downsell_bg_color']['collation']);
    }
}
