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
        Schema::create('abandoned_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            // Dados do cliente
            $table->string('customer_name');
            $table->string('customer_email')->index();
            $table->string('customer_phone')->nullable();
            $table->string('customer_document')->nullable();

            // Itens do carrinho
            $table->json('items');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            // Endereço de entrega
            $table->json('shipping_address')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->string('shipping_method_name')->nullable();
            $table->decimal('shipping_price', 10, 2)->nullable();

            // Rastreamento do funil
            $table->string('step_reached')->default('dados'); // dados, entrega, pagamento, pagamento_tentado
            $table->string('payment_method')->nullable(); // pix, credit_card, boleto
            $table->string('status')->default('open'); // open, recovered, converted, expired
            $table->string('abandoned_reason')->nullable(); // left_dados, left_entrega, left_pagamento, card_refused, pix_expired, boleto_expired

            // Dados do cartão (somente últimos dígitos)
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();

            // Recuperação
            $table->string('recovery_token', 64)->unique()->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            // Rastreamento adicional
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('device_type')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'step_reached']);
            $table->index(['store_id', 'customer_email']);
            $table->index(['store_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abandoned_carts');
    }
};
