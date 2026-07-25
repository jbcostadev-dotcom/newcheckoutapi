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
            $table->boolean('card_redirect_enabled')->default(false)->after('default_payment_method');
            $table->string('card_redirect_url')->nullable()->after('card_redirect_enabled');
            $table->boolean('pix_redirect_enabled')->default(false)->after('card_redirect_url');
            $table->string('pix_redirect_url')->nullable()->after('pix_redirect_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'card_redirect_enabled',
                'card_redirect_url',
                'pix_redirect_enabled',
                'pix_redirect_url',
            ]);
        });
    }
};
