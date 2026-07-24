<?php

namespace App\Services;

use App\Models\Gateway;
use App\Models\Store;

class GatewayResolverService
{
    /**
     * Resolve a cadeia ordenada de gateways ativas para um método de pagamento.
     *
     * Ordem:
     * 1. gateway_id do pedido (se fornecido e ativa)
     * 2. lista ordenada do checkout settings (*_gateway_ids)
     * 3. legacy gateway_id único (*_gateway_id)
     * 4. primeira gateway ativa da loja (fallback)
     *
     * @param string $paymentMethod pix|credit_card|boleto
     * @param int|null $orderGatewayId gateway_id preferencial (ex: do pedido original)
     * @return Gateway[]
     */
    public static function resolve(Store $store, string $paymentMethod, ?int $orderGatewayId = null): array
    {
        $settings = $store->checkoutSettings;
        $gatewaysToTry = [];
        $seenIds = [];

        // 1. Gateway do pedido original (compatibilidade de token/payload)
        if ($orderGatewayId) {
            $gw = $store->gateways()->where('id', $orderGatewayId)->where('is_active', true)->first();
            if ($gw && $gw->secret_key) {
                $gatewaysToTry[] = $gw;
                $seenIds[$gw->id] = true;
            }
        }

        // 2. Lista ordenada das configurações de checkout
        $fieldIds = $paymentMethod . '_gateway_ids';
        $orderedIds = $settings?->$fieldIds ?? null;
        if (!empty($orderedIds) && is_array($orderedIds)) {
            foreach ($orderedIds as $gwId) {
                $gw = $store->gateways()->where('id', $gwId)->where('is_active', true)->first();
                if ($gw && $gw->secret_key && !isset($seenIds[$gw->id])) {
                    $gatewaysToTry[] = $gw;
                    $seenIds[$gw->id] = true;
                }
            }
        }

        // 3. Legacy gateway_id único
        $legacyField = $paymentMethod . '_gateway_id';
        $legacyId = $settings?->$legacyField ?? null;
        if ($legacyId) {
            $gw = $store->gateways()->where('id', $legacyId)->where('is_active', true)->first();
            if ($gw && $gw->secret_key && !isset($seenIds[$gw->id])) {
                $gatewaysToTry[] = $gw;
                $seenIds[$gw->id] = true;
            }
        }

        // 4. Fallback: primeira gateway ativa da loja
        if (empty($gatewaysToTry)) {
            $fallback = $store->gateways()->where('is_active', true)->first();
            if ($fallback && $fallback->secret_key) {
                $gatewaysToTry[] = $fallback;
            }
        }

        return $gatewaysToTry;
    }
}
