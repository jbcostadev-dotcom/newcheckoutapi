<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Identifica o tema em que o snippet do checkout foi injetado + quando.
            // Permite ao lojista saber se precisa reinjetar ao trocar de tema.
            $table->unsignedBigInteger('shopify_injected_theme_id')->nullable()->after('shopify_client_secret');
            $table->timestamp('shopify_injected_at')->nullable()->after('shopify_injected_theme_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['shopify_injected_theme_id', 'shopify_injected_at']);
        });
    }
};
