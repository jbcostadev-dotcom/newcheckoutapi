<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Credenciais do app Shopify criado pelo próprio lojista (modelo self-service).
            $table->string('shopify_client_id')->nullable()->after('shopify_access_token');
            $table->string('shopify_client_secret')->nullable()->after('shopify_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['shopify_client_id', 'shopify_client_secret']);
        });
    }
};