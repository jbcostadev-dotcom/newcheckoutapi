<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza pedidos (Order) com a Shopify via Admin API REST.
 *
 * Fluxo:
 *  - create(): cria o pedido na Shopify como `financial_status = pending`
 *    (aguardando pagamento) e persiste `shopify_order_id`.
 *  - markAsPaid(): adiciona uma transação `sale` (gateway manual) ao pedido
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
     * registrando uma única transação `sale` com gateway `manual`.
     *
     * Pedidos criados via Admin API não possuem gateway de pagamento associado,
     * então a Shopify rejeita transações (`authorization`, `sale`, ...) sem um
     * gateway. Usamos o gateway builtin `manual` (manual_payment_gateway=true),
     * que é justamente o usado pelo painel Shopify no botão "Marcar como pago".
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

            // Um único `sale` (auth+capture em um passo) é a forma suportada pela
            // Shopify para marcar pedidos criados via API como pagos. O `gateway`
            // `manual` é obrigatório aqui: sem ele a API rejeita qualquer `kind`
            // com "X is not a valid transaction", porque o pedido não possui um
            // gateway de pagamento associado (não foi checkout Shopify).
            $saleResponse = $this->request($store, 'POST', $endpoint, [
                'transaction' => [
                    'kind' => 'sale',
                    'status' => 'success',
                    'amount' => $amount,
                    'currency' => 'BRL',
                    'gateway' => 'manual',
                    'source' => 'external',
                ],
            ]);

            $saleId = $saleResponse['transaction']['id'] ?? null;
            if (! $saleId) {
                throw new \RuntimeException('Shopify sale transaction não retornou ID');
            }

            Log::info('Shopify order marcado como pago', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'amount' => $amount,
                'sale_id' => $saleId,
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

        // Itens: usa shopify_variant_id quando disponível; senão envia como
        // custom line item (title + price). Isso garante que order bumps e
        // upsells também apareçam no pedido Shopify, mesmo sem sincronização.
        $lineItems = [];
        foreach ($order->items as $item) {
            $variantId = $item->product?->shopify_variant_id;
            if ($variantId) {
                $lineItems[] = [
                    'variant_id' => (int) $variantId,
                    'quantity' => (int) $item->qty,
                ];
                continue;
            }

            $lineItems[] = [
                'title' => $item->name,
                'price' => number_format((float) $item->unit_price, 2, '.', ''),
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

    /**
     * Sincroniza um item adicionado depois (ex: upsell aceito) em um pedido
     * Shopify já existente, usando a GraphQL Order Editing API.
     *
     * Se o pedido ainda não existir na Shopify, não faz nada: ele será criado
     * posteriormente com todos os itens (inclusive o upsell).
     */
    public function syncExtraItem(Store $store, Order $order, OrderItem $item): void
    {
        if (! $store->isShopifyConnected()) {
            return;
        }

        if (! $order->shopify_order_id) {
            return;
        }

        try {
            $this->addLineItemToShopifyOrder($store, $order->shopify_order_id, $item);

            Log::info('Shopify item extra adicionado ao pedido', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shopify item extra sync falhou', [
                'store_id' => $store->id,
                'order_id' => $order->id,
                'shopify_order_id' => $order->shopify_order_id,
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Adiciona um OrderItem a um pedido Shopify existente via GraphQL Order Edit.
     *
     * @throws \RuntimeException
     */
    protected function addLineItemToShopifyOrder(Store $store, string $shopifyOrderId, OrderItem $item): void
    {
        $calculatedOrderId = $this->beginOrderEdit($store, $shopifyOrderId);

        $variantId = $item->product?->shopify_variant_id;

        if ($variantId) {
            $this->graphQlRequest($store, <<<'GRAPHQL'
                mutation addVariant($id: ID!, $variantId: ID!, $quantity: Int!) {
                    orderEditAddVariant(id: $id, variantId: $variantId, quantity: $quantity) {
                        calculatedOrder { id }
                        userErrors { field message }
                    }
                }
            GRAPHQL, [
                'id' => $calculatedOrderId,
                'variantId' => "gid://shopify/ProductVariant/{$variantId}",
                'quantity' => (int) $item->qty,
            ]);
        } else {
            $this->graphQlRequest($store, <<<'GRAPHQL'
                mutation addCustomItem($id: ID!, $title: String!, $quantity: Int!, $price: MoneyInput!) {
                    orderEditAddCustomItem(id: $id, title: $title, quantity: $quantity, price: $price) {
                        calculatedOrder { id }
                        userErrors { field message }
                    }
                }
            GRAPHQL, [
                'id' => $calculatedOrderId,
                'title' => $item->name,
                'quantity' => (int) $item->qty,
                'price' => [
                    'amount' => number_format((float) $item->unit_price, 2, '.', ''),
                    'currencyCode' => 'BRL',
                ],
            ]);
        }

        $this->commitOrderEdit($store, $calculatedOrderId);
    }

    /**
     * Inicia uma sessão de edição de pedido e retorna o CalculatedOrder ID.
     */
    protected function beginOrderEdit(Store $store, string $shopifyOrderId): string
    {
        $data = $this->graphQlRequest($store, <<<'GRAPHQL'
            mutation beginEdit($id: ID!) {
                orderEditBegin(id: $id) {
                    calculatedOrder { id }
                    userErrors { field message }
                }
            }
        GRAPHQL, [
            'id' => "gid://shopify/Order/{$shopifyOrderId}",
        ]);

        $calculatedOrderId = $data['orderEditBegin']['calculatedOrder']['id'] ?? null;
        if (! $calculatedOrderId) {
            throw new \RuntimeException('Falha ao iniciar edição do pedido Shopify');
        }

        return $calculatedOrderId;
    }

    /**
     * Salva as alterações da sessão de edição de pedido.
     */
    protected function commitOrderEdit(Store $store, string $calculatedOrderId): void
    {
        $this->graphQlRequest($store, <<<'GRAPHQL'
            mutation commitEdit($id: ID!) {
                orderEditCommit(id: $id, notifyCustomer: false, staffNote: "Item adicionado pelo checkout") {
                    order { id }
                    userErrors { field message }
                }
            }
        GRAPHQL, [
            'id' => $calculatedOrderId,
        ]);
    }

    /**
     * Executa uma query GraphQL na Shopify Admin API.
     *
     * @param  array<string, mixed>  $variables
     * @return array<mixed>
     *
     * @throws \RuntimeException
     */
    protected function graphQlRequest(Store $store, string $query, array $variables = []): array
    {
        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/graphql.json";

        $client = Http::withHeaders([
            'X-Shopify-Access-Token' => $store->shopify_access_token,
        ]);

        $response = $client->post($endpoint, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if (! $response->successful()) {
            Log::warning('Shopify GraphQL API erro', [
                'store_id' => $store->id,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Shopify GraphQL API falhou: '.$response->status(), $response->status());
        }

        $json = $response->json() ?? [];

        if (! empty($json['errors'])) {
            $messages = collect($json['errors'])->pluck('message')->implode('; ');
            throw new \RuntimeException('Shopify GraphQL erro: '.$messages);
        }

        $payload = $json['data'] ?? [];
        $userErrors = $this->extractGraphQlUserErrors($payload);
        if (! empty($userErrors)) {
            throw new \RuntimeException('Shopify GraphQL userErrors: '.collect($userErrors)->pluck('message')->implode('; '));
        }

        return $payload;
    }

    /**
     * Extrai userErrors de uma resposta GraphQL recursivamente.
     *
     * @param  array<mixed>  $data
     * @return array<int, array{field: string|null, message: string}>
     */
    protected function extractGraphQlUserErrors(array $data): array
    {
        $errors = [];
        foreach ($data as $value) {
            if (is_array($value) && isset($value['userErrors'])) {
                foreach ($value['userErrors'] as $error) {
                    $errors[] = [
                        'field' => $error['field'] ?? null,
                        'message' => $error['message'] ?? 'Erro desconhecido',
                    ];
                }
            }
        }

        return $errors;
    }
}
