<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('scarcity_title')->nullable()->after('scarcity_text');
            $table->integer('scarcity_countdown_minutes')->default(20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn('scarcity_title');
            $table->integer('scarcity_countdown_minutes')->default(15)->change();
        });
    }
};
