<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            // product_id nullable: um item snapshot mantém dados mesmo se o
            // produto for desativado/removido do catálogo futuramente.
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');             // snapshot do nome na compra
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 10, 2); // snapshot do preço na compra
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};