<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot dos atributos da variante (mesmo formato de products.attributes).
            // Permite manter a informação de variante no histórico de pedidos mesmo
            // quando o nome do produto passa a ser só o título pai.
            $table->json('attributes')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('attributes');
        });
    }
};