<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UtmifyService
{
    /**
     * Envia (ou reenvia) um pedido à Utmify com base no status atual.
     * Best-effort: não lança exceções para cima — apenas loga falhas.
     */
    public function dispatchForOrder(Order $order): void
    {
        try {
            $order->loadMissing(['store.utmifySetting', 'items']);

            $store = $order->store;
            if (! $store || ! $store->isUtmifyActive()) {
                return;
            }

            $token = $store->utmifySetting->api_token;
            $status = $this->mapStatus($order->status);
            if ($status === null) {
                return; // Sem status equivalente na Utmify — nada a enviar.
            }

            $payload = $this->buildPayload($order, $status);

            $response = Http::withHeaders([
                'x-api-token' => $token,
            ])
                ->timeout(15)
                ->post($this->endpoint(), $payload);

            if (! $response->successful()) {
                Log::warning('Utmify: requisição não bem-sucedida', [
                    'order_id' => $order->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Utmify dispatch falhou', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Endpoint de envio de pedidos.
     */
    private function endpoint(): string
    {
        return rtrim(config('services.utmify.api_url', 'https://api.utmify.com.br'), '/').'/api-credentials/orders';
    }

    /**
     * Mapeia o status interno do pedido para o status aceito pela Utmify.
     * Retorna null quando não há equivalente (não deve ser enviado).
     */
    private function mapStatus(string $status): ?string
    {
        return match ($status) {
            Order::STATUS_WAITING_PAYMENT => 'waiting_payment',
            Order::STATUS_PROCESSING, Order::STATUS_IN_ANALYSIS => 'waiting_payment',
            Order::STATUS_PAID, Order::STATUS_AUTHORIZED => 'paid',
            Order::STATUS_FAILED, Order::STATUS_REFUSED, Order::STATUS_CANCELED => 'refused',
            Order::STATUS_REFUNDED => 'refunded',
            Order::STATUS_CHARGEDBACK => 'chargedback',
            Order::STATUS_IN_PROTEST => 'paid',
            default => null, // pending — nada a enviar.
        };
    }

    /**
     * Constrói o payload conforme a documentação da Utmify.
     */
    private function buildPayload(Order $order, string $utmifyStatus): array
    {
        $store = $order->store;
        $platform = config('services.utmify.platform', 'Bersenker');

        $totalInCents = (int) round(((float) $order->amount) * 100);

        $approvedDate = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_AUTHORIZED, Order::STATUS_REFUNDED, Order::STATUS_CHARGEDBACK, Order::STATUS_IN_PROTEST], true)
            ? $order->updated_at?->format('Y-m-d H:i:s')
            : null;

        $refundedAt = in_array($order->status, [Order::STATUS_REFUNDED, Order::STATUS_CHARGEDBACK], true)
            ? $order->updated_at?->format('Y-m-d H:i:s')
            : null;

        $paymentMethod = $order->payment_method;
        if ($paymentMethod === 'credit_card') {
            $paymentMethod = 'credit_card';
        }

        return [
            'orderId' => (string) $order->id,
            'platform' => $platform,
            'paymentMethod' => $paymentMethod,
            'status' => $utmifyStatus,
            'createdAt' => $order->created_at?->format('Y-m-d H:i:s'),
            'approvedDate' => $approvedDate,
            'refundedAt' => $refundedAt,
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'document' => $order->customer_document,
                'country' => 'BR',
            ],
            'products' => $order->items->map(fn ($item) => [
                'id' => (string) $item->product_id,
                'name' => $item->name,
                'planId' => null,
                'planName' => null,
                'quantity' => (int) $item->qty,
                'priceInCents' => (int) round(((float) $item->unit_price) * 100),
            ])->values()->all(),
            'trackingParameters' => $this->buildTrackingParameters($order),
            'commission' => [
                'totalPriceInCents' => $totalInCents,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => $totalInCents,
            ],
            'isTest' => false,
        ];
    }

    /**
     * Monta os trackingParameters persistidos no pedido (UTMs/src/sck).
     */
    private function buildTrackingParameters(Order $order): array
    {
        $tp = is_array($order->tracking_parameters) ? $order->tracking_parameters : [];

        return [
            'src' => $tp['src'] ?? null,
            'sck' => $tp['sck'] ?? null,
            'utm_source' => $tp['utm_source'] ?? null,
            'utm_campaign' => $tp['utm_campaign'] ?? null,
            'utm_medium' => $tp['utm_medium'] ?? null,
            'utm_content' => $tp['utm_content'] ?? null,
            'utm_term' => $tp['utm_term'] ?? null,
        ];
    }
}