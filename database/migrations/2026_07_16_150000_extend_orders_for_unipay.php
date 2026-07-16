<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estende a tabela `orders` para suportar a integração Unipay (FastSoft Brasil):
     * - novos métodos de pagamento (pix | credit_card | boleto)
     * - novos status do ciclo FastSoft (authorized, in_analysis, processing, etc.)
     * - campos de cartão (brand, last4, token, installments)
     * - campos de boleto (url, barcode, digitable_line)
     * - expiração PIX/Boleto
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('card_brand', 20)->nullable()->after('gateway_transaction_id');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->string('card_token', 200)->nullable()->after('card_last4');
            $table->unsignedTinyInteger('installments')->default(1)->after('card_token');
            $table->string('boleto_url')->nullable()->after('installments');
            $table->string('boleto_barcode')->nullable()->after('boleto_url');
            $table->string('boleto_digitable_line')->nullable()->after('boleto_barcode');
            $table->timestamp('gateway_expires_at')->nullable()->after('boleto_digitable_line');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'card_brand',
                'card_last4',
                'card_token',
                'installments',
                'boleto_url',
                'boleto_barcode',
                'boleto_digitable_line',
                'gateway_expires_at',
            ]);
        });
    }
};