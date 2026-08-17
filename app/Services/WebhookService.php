<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\AbandonedCart;
use App\Models\Order;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Carbon\CarbonInterface;

class WebhookService
{
    public function dispatchOrderEvent(
        Order|int $order,
        string $eventType,
        ?CarbonInterface $occurredAt = null
    ): int {
        $order = $order instanceof Order ? $order : Order::find($order);
        if (! $order || ! in_array($eventType, Webhook::EVENTS, true)) {
            return 0;
        }

        $order->loadMissing(['store', 'items.product']);
        $cart = AbandonedCart::query()->where('order_id', $order->id)->latest()->first();
        $payload = $this->orderPayload($order, $eventType, $cart);

        return $this->createDeliveries(
            (int) $order->store_id,
            $eventType,
            'order',
            (int) $order->id,
            $payload,
            $occurredAt ?? now(),
        );
    }

    public function dispatchCartEvent(
        AbandonedCart|int $cart,
        string $eventType = Webhook::EVENT_CART_ABANDONED,
        ?CarbonInterface $occurredAt = null
    ): int {
        $cart = $cart instanceof AbandonedCart ? $cart : AbandonedCart::find($cart);
        if (! $cart || $eventType !== Webhook::EVENT_CART_ABANDONED) {
            return 0;
        }

        $cart->loadMissing('store');

        return $this->createDeliveries(
            (int) $cart->store_id,
            $eventType,
            'cart',
            (int) $cart->id,
            $this->cartPayload($cart),
            $occurredAt ?? now(),
        );
    }

    /**
     * Emite eventos cujo instante depende de uma janela de inatividade.
     * A unicidade da tabela de entregas torna a varredura idempotente.
     */
    public function dispatchScheduledEvents(): int
    {
        $count = 0;
        $cartCutoff = now()->subMinutes(15);

        AbandonedCart::query()
            ->where('status', AbandonedCart::STATUS_OPEN)
            ->whereNull('order_id')
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', $cartCutoff)
            ->chunkById(100, function ($carts) use (&$count) {
                foreach ($carts as $cart) {
                    $occurredAt = $cart->last_activity_at->copy()->addMinutes(15);
                    $count += $this->dispatchCartEvent($cart, Webhook::EVENT_CART_ABANDONED, $occurredAt);
                }
            });

        $billetCutoff = now()->subMinutes(15);
        Order::query()
            ->where('payment_method', 'boleto')
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_WAITING_PAYMENT])
            ->where('created_at', '<=', $billetCutoff)
            ->where(function ($query) {
                $query->whereNotNull('boleto_url')
                    ->orWhereNotNull('boleto_barcode')
                    ->orWhereNotNull('boleto_digitable_line');
            })
            ->chunkById(100, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    $occurredAt = $order->created_at->copy()->addMinutes(15);
                    $count += $this->dispatchOrderEvent($order, Webhook::EVENT_BILLET_CREATED, $occurredAt);
                }
            });

        return $count;
    }

    private function createDeliveries(
        int $storeId,
        string $eventType,
        string $resourceType,
        int $resourceId,
        array $payload,
        CarbonInterface $occurredAt
    ): int {
        $created = 0;

        Webhook::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('created_at', '<=', $occurredAt)
            ->where('updated_at', '<=', $occurredAt)
            ->get()
            ->filter(fn (Webhook $webhook) => $webhook->subscribesTo($eventType))
            ->each(function (Webhook $webhook) use (
                &$created,
                $storeId,
                $eventType,
                $resourceType,
                $resourceId,
                $payload
            ) {
                $delivery = WebhookDelivery::firstOrCreate(
                    [
                        'webhook_id' => $webhook->id,
                        'event_type' => $eventType,
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                    ],
                    [
                        'store_id' => $storeId,
                        'payload' => $payload,
                        'status' => WebhookDelivery::STATUS_PENDING,
                    ],
                );

                if ($delivery->wasRecentlyCreated) {
                    DeliverWebhook::dispatch($delivery->id)->afterCommit();
                    $created++;
                }
            });

        return $created;
    }

    private function orderPayload(Order $order, string $eventType, ?AbandonedCart $cart): array
    {
        $eventLabel = $this->eventLabel($eventType);
        $tracking = $order->tracking_parameters ?? [];
        $frontUrl = rtrim(config('services.shopify.frontend_url', 'https://app.bersenker.shop'), '/');
        $totalInCents = (int) round(((float) $order->amount) * 100);

        return [
            'eventType' => $eventType,
            'title' => "jCheckout | Pedido #{$order->id} {$eventLabel}",
            'text' => "jCheckout | Pedido #{$order->id} {$eventLabel}",
            'image' => null,
            'actions' => [[
                'name' => "Pedido #{$order->id}",
                'url' => "{$frontUrl}/dashboard/orders?order={$order->id}",
            ]],
            'orderId' => (string) $order->id,
            'platform' => 'jCheckout',
            'currency' => 'BRL',
            'paymentMethod' => $order->payment_method,
            'status' => $order->status,
            'createdAt' => $order->created_at?->toISOString(),
            'approvedDate' => in_array($order->status, [Order::STATUS_PAID, Order::STATUS_AUTHORIZED], true)
                ? $order->updated_at?->toISOString()
                : null,
            'refundedAt' => $order->status === Order::STATUS_REFUNDED
                ? $order->updated_at?->toISOString()
                : null,
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'document' => $order->customer_document,
                'country' => 'BR',
                'ip' => $cart?->ip_address,
            ],
            'products' => $order->items->map(fn ($item) => [
                'id' => $item->product_id,
                'name' => $item->name,
                'planId' => $item->product_id,
                'planName' => $item->name,
                'quantity' => (int) $item->qty,
                'priceInCents' => (int) round(((float) $item->unit_price) * 100),
                'image' => $item->product?->image_url,
            ])->values()->all(),
            'coupons' => [],
            'trackingParameters' => [
                'src' => $tracking['src'] ?? null,
                'sck' => $tracking['sck'] ?? null,
                'utm_source' => $tracking['utm_source'] ?? null,
                'utm_campaign' => $tracking['utm_campaign'] ?? null,
                'utm_medium' => $tracking['utm_medium'] ?? null,
                'utm_content' => $tracking['utm_content'] ?? null,
                'utm_term' => $tracking['utm_term'] ?? null,
            ],
            'commission' => [
                'totalPriceInCents' => $totalInCents,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => $totalInCents,
            ],
            'address' => [
                'street' => $order->shipping_logradouro,
                'number' => $order->shipping_numero,
                'complement' => $order->shipping_complemento,
                'neighborhood' => $order->shipping_bairro,
                'zipcode' => $order->shipping_cep,
                'city' => $order->shipping_cidade,
                'state' => $order->shipping_uf,
                'country' => 'BR',
            ],
            'isTest' => str_starts_with(strtolower((string) $order->gateway_transaction_id), 'test'),
            'pixQrCode' => $order->pix_copia_cola,
            'abandonouNa' => $cart ? $this->abandonedAt($cart) : null,
            'trackingNumber' => null,
            'integrationsPartners' => (object) [],
        ];
    }

    private function cartPayload(AbandonedCart $cart): array
    {
        $items = collect($cart->items ?? []);
        $address = $cart->shipping_address ?? [];
        $totalInCents = (int) round(((float) $cart->total) * 100);
        $recoveryUrl = $cart->recovery_token
            ? rtrim(config('app.url'), '/').'/api/checkout/recover/'.$cart->recovery_token
            : null;

        return [
            'eventType' => Webhook::EVENT_CART_ABANDONED,
            'title' => "jCheckout | Carrinho #{$cart->id} abandonado",
            'text' => "jCheckout | Carrinho #{$cart->id} abandonado",
            'image' => null,
            'actions' => $recoveryUrl ? [[
                'name' => "Recuperar carrinho #{$cart->id}",
                'url' => $recoveryUrl,
            ]] : [],
            'orderId' => 'CART-'.$cart->id,
            'platform' => 'jCheckout',
            'currency' => 'BRL',
            'paymentMethod' => $cart->payment_method,
            'status' => 'waiting_payment',
            'createdAt' => $cart->created_at?->toISOString(),
            'approvedDate' => null,
            'refundedAt' => null,
            'customer' => [
                'name' => $cart->customer_name,
                'email' => $cart->customer_email,
                'phone' => $cart->customer_phone,
                'document' => $cart->customer_document,
                'country' => 'BR',
                'ip' => $cart->ip_address,
            ],
            'products' => $items->map(fn ($item) => [
                'id' => $item['product_id'] ?? $item['id'] ?? null,
                'name' => $item['name'] ?? $item['title'] ?? 'Produto',
                'planId' => $item['product_id'] ?? $item['id'] ?? null,
                'planName' => $item['name'] ?? $item['title'] ?? 'Produto',
                'quantity' => (int) ($item['qty'] ?? $item['quantity'] ?? 1),
                'priceInCents' => (int) round(((float) ($item['price'] ?? $item['unit_price'] ?? 0)) * 100),
                'image' => $item['image_url'] ?? $item['image'] ?? null,
            ])->values()->all(),
            'coupons' => [],
            'trackingParameters' => [
                'src' => null,
                'sck' => null,
                'utm_source' => $cart->utm_source,
                'utm_campaign' => $cart->utm_campaign,
                'utm_medium' => $cart->utm_medium,
                'utm_content' => null,
                'utm_term' => null,
            ],
            'commission' => [
                'totalPriceInCents' => $totalInCents,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => $totalInCents,
            ],
            'address' => [
                'street' => $address['logradouro'] ?? $address['street'] ?? null,
                'number' => $address['numero'] ?? $address['number'] ?? null,
                'complement' => $address['complemento'] ?? $address['complement'] ?? null,
                'neighborhood' => $address['bairro'] ?? $address['neighborhood'] ?? null,
                'zipcode' => $address['cep'] ?? $address['zipcode'] ?? null,
                'city' => $address['cidade'] ?? $address['city'] ?? null,
                'state' => $address['uf'] ?? $address['state'] ?? null,
                'country' => 'BR',
            ],
            'isTest' => false,
            'pixQrCode' => null,
            'abandonouNa' => $this->abandonedAt($cart),
            'trackingNumber' => null,
            'integrationsPartners' => (object) [],
        ];
    }

    private function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            Webhook::EVENT_ORDER_CREATED => 'criado',
            Webhook::EVENT_ORDER_PAID => 'pago',
            Webhook::EVENT_ORDER_REFUSED => 'recusado',
            Webhook::EVENT_PIX_CREATED => 'Pix gerado',
            Webhook::EVENT_BILLET_CREATED => 'boleto gerado',
            default => strtolower($eventType),
        };
    }

    private function abandonedAt(AbandonedCart $cart): string
    {
        return match ($cart->step_reached) {
            AbandonedCart::STEP_DADOS => 'Dados do cliente',
            AbandonedCart::STEP_ENTREGA => 'Entrega',
            AbandonedCart::STEP_PAGAMENTO => 'Pagamento - forma de pagamento',
            AbandonedCart::STEP_PAGAMENTO_TENTADO => 'Pagamento - tentativa de pagamento',
            default => 'Checkout',
        };
    }
}
