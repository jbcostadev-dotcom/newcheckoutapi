<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Título do produto "pai" no Shopify (sem o sufixo da variante).
            // Usado para agrupar variantes do mesmo produto e para exibir no checkout.
            $table->string('parent_title')->nullable()->after('name');

            // Atributos estruturados da variante, ex.:
            // [{"name":"Tamanho","value":"P"},{"name":"Cor","value":"Rosa"}]
            // Shopify suporta até 3 options por produto.
            $table->json('attributes')->nullable()->after('parent_title');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['parent_title', 'attributes']);
        });
    }
};