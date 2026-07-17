<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->boolean('pix_enabled')->default(true)->after('social_proofs_enabled');
            $table->unsignedBigInteger('pix_gateway_id')->nullable()->after('pix_enabled');
            $table->boolean('card_enabled')->default(true)->after('pix_gateway_id');
            $table->unsignedBigInteger('card_gateway_id')->nullable()->after('card_enabled');
            $table->boolean('boleto_enabled')->default(false)->after('card_gateway_id');
            $table->unsignedBigInteger('boleto_gateway_id')->nullable()->after('boleto_enabled');

            $table->foreign('pix_gateway_id')->references('id')->on('gateways')->nullOnDelete();
            $table->foreign('card_gateway_id')->references('id')->on('gateways')->nullOnDelete();
            $table->foreign('boleto_gateway_id')->references('id')->on('gateways')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropForeign(['pix_gateway_id']);
            $table->dropForeign(['card_gateway_id']);
            $table->dropForeign(['boleto_gateway_id']);
            $table->dropColumn([
                'pix_enabled',
                'pix_gateway_id',
                'card_enabled',
                'card_gateway_id',
                'boleto_enabled',
                'boleto_gateway_id',
            ]);
        });
    }
};
