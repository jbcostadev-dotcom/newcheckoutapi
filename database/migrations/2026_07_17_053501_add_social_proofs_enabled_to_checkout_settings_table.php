<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->boolean('social_proofs_enabled')->default(true)->after('font_size_base');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn('social_proofs_enabled');
        });
    }
};
