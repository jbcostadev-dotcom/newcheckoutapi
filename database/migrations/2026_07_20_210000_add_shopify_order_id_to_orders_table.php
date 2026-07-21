<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona a coluna `shopify_order_id` na tabela `orders` para guardar
     * o ID do pedido criado (e posteriormente marcado como pago) na Shopify.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shopify_order_id', 50)->nullable()->after('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shopify_order_id');
        });
    }
};
