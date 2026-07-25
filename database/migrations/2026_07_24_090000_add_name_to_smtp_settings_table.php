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
        Schema::table('smtp_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('smtp_settings', 'name')) {
                $table->string('name')->nullable()->after('store_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smtp_settings', function (Blueprint $table) {
            if (Schema::hasColumn('smtp_settings', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};
