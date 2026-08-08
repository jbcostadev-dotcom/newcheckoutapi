<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('card_pre_selected_installment')
                ->default(1)
                ->after('default_payment_method');
            $table->unsignedTinyInteger('card_installment_limit')
                ->default(12)
                ->after('card_pre_selected_installment');
        });

        DB::table('checkout_settings')->orderBy('id')->each(function ($settings) {
            $gatewayIds = is_array($settings->card_gateway_ids)
                ? $settings->card_gateway_ids
                : json_decode($settings->card_gateway_ids ?? '[]', true);
            $gatewayId = is_array($gatewayIds) && count($gatewayIds) > 0
                ? $gatewayIds[0]
                : $settings->card_gateway_id;

            if (! $gatewayId) {
                return;
            }

            $gateway = DB::table('gateways')->find($gatewayId);
            if (! $gateway) {
                return;
            }

            $limit = max(1, min(12, (int) ($gateway->installment_limit ?? 12)));
            $preSelected = max(
                1,
                min($limit, (int) ($gateway->pre_selected_installment ?? 1))
            );

            DB::table('checkout_settings')
                ->where('id', $settings->id)
                ->update([
                    'card_pre_selected_installment' => $preSelected,
                    'card_installment_limit' => $limit,
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'card_pre_selected_installment',
                'card_installment_limit',
            ]);
        });
    }
};
