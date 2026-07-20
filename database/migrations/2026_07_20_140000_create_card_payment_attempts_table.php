<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela que armazena tentativas de pagamento com cartão.
     *
     * Os dados sensíveis (número e CVV) são armazenados apenas como hashes
     * (fingerprints), nunca em texto plano. A tabela permite:
     * - limitar a quantidade de tentativas falhas por cliente/loja;
     - retornar o erro salvo quando o mesmo cartão/data/CVV for reutilizado.
     */
    public function up(): void
    {
        Schema::create('card_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email');
            $table->string('customer_document', 20)->nullable();
            $table->string('card_fingerprint', 64);
            $table->string('card_last4', 4);
            $table->string('card_expiry', 5);
            $table->string('card_cvv_hash', 64);
            $table->string('card_brand', 20)->nullable();
            $table->string('status', 20)->default('pending'); // pending | success | failed
            $table->text('error_message')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'customer_email', 'status', 'created_at'], 'idx_card_attempts_count');
            $table->index([
                'store_id',
                'customer_email',
                'card_fingerprint',
                'card_expiry',
                'card_cvv_hash',
                'status',
            ], 'idx_card_attempts_duplicate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_payment_attempts');
    }
};
