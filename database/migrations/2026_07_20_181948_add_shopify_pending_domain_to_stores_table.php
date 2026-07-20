<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Domínio Shopify informado pelo lojista antes do OAuth.
            // É movido para shopify_domain somente quando o callback confirma a conexão.
            $table->string('shopify_pending_domain')->nullable()->after('shopify_domain');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('shopify_pending_domain');
        });
    }
};