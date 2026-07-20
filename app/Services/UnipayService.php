<?php

namespace App\Services;

use App\Exceptions\UnipayException;
use App\Models\Gateway;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class UnipayService
{
    protected Gateway $gateway;
    protected string $baseUrl;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
        $this->baseUrl = rtrim(config('services.unipay.api_url', 'https://api.fastsoftbrasil.com'), '/');
    }

    /**
     * Monta o header de autenticação Basic base64("x:SECRET_KEY").
     */
    protected function authHeaders(): array
    {
        $secret = $this->gateway->secret_key;
        if (!$secret) {
            throw new UnipayException('Chave secreta (secret_key) da Unipay não configurada.');
        }

        $token = base64_encode('x:' . $secret);

        return [
            'Authorization' => 'Basic ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    protected function client()
    {
        return Http::withHeaders($this->authHeaders())->baseUrl($this->baseUrl);
    }

    /**
     * Lança exceção padronizada para respostas não-2xx.
     */
    protected function throwIfFailed($response, string $context): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $body = null;
        try {
            $body = $response->json();
        } catch (\Throwable $e) {
            $body = ['raw' => $response->body()];
        }

        $message = 'Erro na chamada à Unipay (' . $context . '): HTTP ' . $response->status();
        if (is_array($body) && !empty($body['message'])) {
            $message = is_array($body['message']) 
                ? json_encode($body['message'], JSON_UNESCAPED_UNICODE) 
                : (string) $body['message'];
        }

        throw new UnipayException($message, $response->status(), $body);
    }

    /**
     * Testa a conexão consultando o saldo da carteira.
     */
    public function testConnection(): array
    {
        $response = $this->client()->get('/api/user/wallet/balance');

        return $this->throwIfFailed($response, 'testConnection');
    }

    /**
     * Cria uma transação na Unipay.
     *
     * @param array $payload Estrutura completa conforme doc FastSoft.
     */
    public function createTransaction(array $payload): array
    {
        $response = $this->client()->post('/api/user/transactions', $payload);

        return $this->throwIfFailed($response, 'createTransaction');
    }

    /**
     * Obtém uma transação pelo id.
     */
    public function getTransaction(string $id): array
    {
        $response = $this->client()->get('/api/user/transactions/' . urlencode($id));

        return $this->throwIfFailed($response, 'getTransaction');
    }

    /**
     * Calcula a taxa de uma transação (opcional).
     */
    public function getFee(array $payload): array
    {
        $response = $this->client()->post('/api/user/transactions/fee', $payload);

        return $this->throwIfFailed($response, 'getFee');
    }

    /**
     * Reembolsa uma transação.
     */
    public function refund(string $id, ?float $amount = null): array
    {
        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = (int) round($amount * 100);
        }

        $response = $this->client()->post('/api/user/transactions/' . urlencode($id) . '/refund', $payload);

        return $this->throwIfFailed($response, 'refund');
    }

    /**
     * Atualiza status de entrega.
     */
    public function updateDelivery(string $id, array $body): array
    {
        $response = $this->client()->post('/api/user/transactions/' . urlencode($id) . '/delivery', $body);

        return $this->throwIfFailed($response, 'updateDelivery');
    }

    // ── Builders de payload ───────────────────────────────────────────

    /**
     * Monta a base comum de customer/shipping/items a partir do Order.
     */
    protected static function baseOrderData(Order $order, string $postbackUrl, ?string $ip = null): array
    {
        $customerName = $order->customer_name;
        $customerEmail = $order->customer_email;
        $customerPhone = $order->customer_phone;
        $document = $order->customer_document;
        $documentType = self::guessDocumentType($document);

        $shipping = [
            'fee' => $order->shipping_price ? (int) round((float) $order->shipping_price * 100) : 0,
            'address' => [
                'street' => $order->shipping_logradouro,
                'streetNumber' => $order->shipping_numero,
                'complement' => $order->shipping_complemento,
                'zipCode' => $order->shipping_cep ? preg_replace('/\D/', '', $order->shipping_cep) : null,
                'neighborhood' => $order->shipping_bairro,
                'city' => $order->shipping_cidade,
                'state' => $order->shipping_uf,
                'country' => 'br',
            ],
        ];

        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'title' => $item->name,
                'unitPrice' => (int) round((float) $item->unit_price * 100),
                'quantity' => (int) $item->qty,
                'tangible' => true,
                'externalRef' => (string) $item->product_id,
            ];
        }

        return [
            'customer' => [
                'name' => $customerName,
                'email' => $customerEmail,
                'document' => array_filter([
                    'number' => $document,
                    'type' => $documentType,
                ]),
                'phone' => $customerPhone,
                'externaRef' => 'order-' . $order->id,
            ],
            'shipping' => $shipping,
            'items' => $items,
            'traceable' => true,
            'ip' => $ip,
            'postbackUrl' => $postbackUrl,
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
        ];
    }

    /**
     * Payload para PIX.
     */
    public static function buildPixPayload(Order $order, string $postbackUrl, int $expiresInDays = 1, ?string $ip = null): array
    {
        return array_merge(self::baseOrderData($order, $postbackUrl, $ip), [
            'amount' => (int) round((float) $order->amount * 100),
            'paymentMethod' => 'PIX',
            'pix' => [
                'expiresInDays' => $expiresInDays,
            ],
        ]);
    }

    /**
     * Payload para Cartão de Crédito.
     *
     * A FastSoft espera os dados do cartão diretamente no objeto card
     * e installments no root do payload.
     */
    public static function buildCardPayload(
        Order $order,
        array $cardData,
        int $installments = 1,
        string $postbackUrl,
        ?string $ip = null,
    ): array {
        return array_merge(self::baseOrderData($order, $postbackUrl, $ip), [
            'amount' => (int) round((float) $order->amount * 100),
            'paymentMethod' => 'CREDIT_CARD',
            'installments' => $installments,
            'card' => [
                'number' => $cardData['number'],
                'holderName' => $cardData['holderName'],
                'expirationMonth' => (int) $cardData['expirationMonth'],
                'expirationYear' => (int) $cardData['expirationYear'],
                'cvv' => $cardData['cvv'],
            ],
        ]);
    }

    /**
     * Payload para Boleto.
     */
    public static function buildBoletoPayload(Order $order, string $postbackUrl, int $expiresInDays = 3, ?string $ip = null): array
    {
        return array_merge(self::baseOrderData($order, $postbackUrl, $ip), [
            'amount' => (int) round((float) $order->amount * 100),
            'paymentMethod' => 'BOLETO',
            'boleto' => [
                'expiresInDays' => $expiresInDays,
            ],
        ]);
    }

    /**
     * Adivinha o tipo de documento (CPF/CNPJ) pelo tamanho.
     */
    protected static function guessDocumentType(?string $document): ?string
    {
        if (!$document) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $document);

        if (strlen($digits) <= 11) {
            return 'CPF';
        }

        return 'CNPJ';
    }
}