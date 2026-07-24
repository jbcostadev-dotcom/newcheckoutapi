<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza pedidos (Order) com a Shopify via Admin API REST.
 *
 * Fluxo:
 *  - create(): cria o pedido na Shopify como `financial_status = pending`
 *    (aguardando pagamento) e persiste `shopify_order_id`.
 *  - markAsPaid(): adiciona uma transação `capture/success` ao pedido
 *    Shopify, transicionando-o para `paid` (aprovado).
 *
 * Requer os escopos `read_orders` e `write_orders` no app Shopify da loja.
 *
 * Best-effort: falhas são logadas e não bloqueiam o fluxo de checkout.
 */
class ShopifyOrderSync
{
    protected string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = (string) config('services.shopify.api_version', '2025-07');
    }

    /**
     * Cria o pedido na Shopify como pendente (unpaid) e persiste o ID retornado.
     *
     * @return string|null Shopify order id (string) ou null em caso de falha.
     */
    public function create(Store $store, Order $order): ?string
    {
        if (! $store->isShopifyConnected()) {
            return null;
        }

        // Garante que o customer exista na Shopify antes de referenciá-lo.
        $customer = $order->customer;
        if ($customer && ! $customer->shopify_customer_id) {
            app(ShopifyCustomerSync::class)->sync($store, $customer);
            $customer = $customer->fresh();
        }

        $payload = $this->buildOrderPayload($store, $order);

        try {
            $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/orders.json";
            $response = $this->request($store, 'POST', $endpoint, ['order' => $payload]);

            $id = $response['order']['id'] ?? null;

            if ($id) {
                $order->update(['shopify_order_id' => (string) $id]);

                return (string) $id;
            }
        } catch (\Throwable $e) {
            Log::warning('Shopify order create falhou', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Marca um pedido (previamente criado) como pago na Shopify,
     * registrando uma transação `capture` com status `success`.
     */
    public function markAsPaid(Store $store, Order $order): void
    {
        if (! $store->isShopifyConnected()) {
            return;
        }

        if (! $order->shopify_order_id) {
            // Tenta criar o pedido na Shopify antes de marcar como pago.
            $created = $this->create($store, $order);
            if (! $created) {
                return;
            }
            $order->refresh();
        }

        if (! $order->shopify_order_id) {
            return;
        }

        try {
            // Evita 409 (Conflict) consultando o estado financeiro atual do pedido.
            $shopifyOrder = $this->getShopifyOrder($store, $order->shopify_order_id);
            $financialStatus = $shopifyOrder['order']['financial_status'] ?? null;

            if (in_array($financialStatus, ['paid', 'partially_paid', 'authorized', 'partially_refunded', 'refunded'], true)) {
                Log::info('Shopify order já está pago/finalizado, ignorando markAsPaid', [
                    'store_id' => $store->id,
                    'order_id' => $order->id,
                    'shopify_order_id' => $order->shopify_order_id,
                    'financial_status' => $financialStatus,
                ]);

                return;
            }

            $amount = number_format((float) $order->amount, 2, '.', '');

            $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/orders/{$order->shopify_order_id}/transactions.json";
            $this->request($store, 'POST', $endpoint, [
                'transaction' => [
                    'kind' => 'capture',
                    'status' => 'success',
                    'amount' => $amount,
                    'currency' => 'BRL',
                    'source_name' => 'external',
                ],
            ]);

            Log::info('Shopify order marcado como pago', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'amount' => $amount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shopify order markAsPaid falhou', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Busca os dados atuais do pedido na Shopify.
     *
     * @return array<mixed>
     */
    protected function getShopifyOrder(Store $store, string $shopifyOrderId): array
    {
        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}.json";

        return $this->request($store, 'GET', $endpoint);
    }

    /**
     * Monta o payload do pedido para a Shopify a partir dos itens internos.
     *
     * @return array<string,mixed>
     */
    protected function buildOrderPayload(Store $store, Order $order): array
    {
        [$firstName, $lastName] = $this->splitName($order->customer_name);

        $payload = [
            // pending = aguardando pagamento (unpaid no painel Shopify).
            'financial_status' => 'pending',
            'inventory_behaviour' => 'decrement_obeying_policy',
            // Não enviar notificações automáticas — o customer pode ainda não ter pago.
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'currency' => 'BRL',
            'email' => $order->customer_email,
            'note' => 'Pedido criado pelo checkout (pedido interno #'.$order->id.').',
            'note_attributes' => [
                ['name' => 'internal_order_id', 'value' => (string) $order->id],
            ],
        ];

        // Itens: usa shopify_variant_id quando disponível; pula itens sem variant_id.
        $lineItems = [];
        foreach ($order->items as $item) {
            $variantId = $item->product?->shopify_variant_id;
            if (! $variantId) {
                continue;
            }
            $lineItems[] = [
                'variant_id' => (int) $variantId,
                'quantity' => (int) $item->qty,
            ];
        }

        if (! empty($lineItems)) {
            $payload['line_items'] = $lineItems;
        }

        // Vincula o customer quando já sincronizado.
        $customer = $order->customer;
        if ($customer && $customer->shopify_customer_id) {
            $payload['customer'] = ['id' => (int) $customer->shopify_customer_id];
        }

        // Endereço de entrega e faturamento (shipping/billing).
        if ($order->shipping_logradouro && $order->shipping_cidade && $order->shipping_uf) {
            $address = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'address1' => trim($order->shipping_logradouro.' '.($order->shipping_numero ?? '')),
                'address2' => $order->shipping_complemento,
                'city' => $order->shipping_cidade,
                'province' => $order->shipping_uf,
                'zip' => $order->shipping_cep,
                'country' => 'BR',
                'phone' => $order->customer_phone,
            ];

            $payload['shipping_address'] = $address;
            $payload['billing_address'] = $address;
        }

        // Frete como shipping line quando presente.
        if ($order->shipping_price !== null) {
            $shippingCost = (float) $order->shipping_price;
            $payload['shipping_lines'] = [
                [
                    'title' => 'Frete',
                    'price' => number_format($shippingCost, 2, '.', ''),
                    'code' => 'checkout_frete',
                    'source' => 'external',
                ],
            ];
        }

        return $payload;
    }

    /**
     * Separa o nome completo em primeiro e último nome.
     * Se houver apenas uma palavra, ela vai em first_name e last_name fica vazio.
     *
     * @return array{string,string}
     */
    protected function splitName(?string $fullName): array
    {
        $name = trim((string) $fullName);
        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name);
        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [$first, $last];
    }

    /**
     * Wrapper de request com header de auth.
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, DELETE).
     * @param  array<string,mixed>|null  $body  Corpo JSON.
     * @return array<mixed>
     */
    protected function request(Store $store, string $method, string $endpoint, ?array $body = null): array
    {
        $client = Http::withHeaders([
            'X-Shopify-Access-Token' => $store->shopify_access_token,
        ]);

        $response = $client->{strtolower($method)}($endpoint, $body ?? []);

        if (! $response->successful()) {
            Log::warning('Shopify Order API erro', [
                'store_id' => $store->id,
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Shopify order API falhou: '.$response->status(), $response->status());
        }

        return $response->json() ?? [];
    }
}
