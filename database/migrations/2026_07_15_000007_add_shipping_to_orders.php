<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_cep')->nullable()->after('customer_document');
            $table->string('shipping_logradouro')->nullable()->after('shipping_cep');
            $table->string('shipping_numero')->nullable()->after('shipping_logradouro');
            $table->string('shipping_complemento')->nullable()->after('shipping_numero');
            $table->string('shipping_bairro')->nullable()->after('shipping_complemento');
            $table->string('shipping_cidade')->nullable()->after('shipping_bairro');
            $table->string('shipping_uf', 2)->nullable()->after('shipping_cidade');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_cep',
                'shipping_logradouro',
                'shipping_numero',
                'shipping_complemento',
                'shipping_bairro',
                'shipping_cidade',
                'shipping_uf',
            ]);
        });
    }
};