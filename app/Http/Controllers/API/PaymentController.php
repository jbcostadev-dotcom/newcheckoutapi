<?php

namespace App\Http\Controllers\API;

use App\Exceptions\UnipayException;
use App\Http\Controllers\Controller;
use App\Models\CardPaymentAttempt;
use App\Models\Store;
use App\Models\Order;
use App\Services\UnipayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private const MAX_FAILED_CARD_ATTEMPTS = 3;
    private const FAILED_ATTEMPTS_WINDOW_HOURS = 24;

    /**
     * Processa um checkout com 1 ou N produtos via Unipay (FastSoft Brasil).
     *
     * Payload:
     *   domain, items: [{product_id, qty}], customer_*, payment_method (pix|credit_card|boleto)
     *   credit_card: card_number, card_holder, card_expiry (MM/AA), card_cvv, installments, card_brand?, card_last4?
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'nullable|integer|min:1',
            'customer_name' => 'required|string',
            'customer_email' => 'required|email',
            'customer_document' => 'required|string|min:11|max:14',
            'customer_phone' => 'required|string|min:10|max:20',
            'payment_method' => 'required|in:pix,credit_card,boleto',
            'card_number' => 'required_if:payment_method,credit_card|string|min:13|max:19',
            'card_holder' => 'required_if:payment_method,credit_card|string|min:3|max:100',
            'card_expiry' => 'required_if:payment_method,credit_card|string|regex:/^\d{2}\/\d{2}$/',
            'card_cvv' => 'required_if:payment_method,credit_card|string|min:3|max:4',
            'installments' => 'nullable|integer|min:1|max:12',
            'card_brand' => 'nullable|string|max:30',
            'card_last4' => 'nullable|string|max:4',
            'shipping_method_id' => 'nullable|integer',
            'shipping_address' => 'required|array',
            'shipping_address.cep' => 'required|string|min:8|max:9',
            'shipping_address.logradouro' => 'required|string|min:3|max:255',
            'shipping_address.numero' => 'required|string|min:1|max:30',
            'shipping_address.complemento' => 'nullable|string|max:120',
            'shipping_address.bairro' => 'required|string|min:2|max:120',
            'shipping_address.cidade' => 'nullable|string|max:120',
            'shipping_address.uf' => 'nullable|string|max:2',
        ]);

        $store = Store::resolveByDomain($validated['domain']);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        // Resolve the correct gateway based on the payment method configured in checkout settings.
        $settings = $store->checkoutSettings;
        $paymentMethod = $validated['payment_method'];

        // Validate that the payment method is enabled.
        $methodEnabledMap = [
            'pix' => $settings->pix_enabled ?? true,
            'credit_card' => $settings->card_enabled ?? true,
            'boleto' => $settings->boleto_enabled ?? false,
        ];

        if (!($methodEnabledMap[$paymentMethod] ?? false)) {
            return response()->json(['error' => 'Payment method is not enabled'], 400);
        }

        // Validações antecipadas de cartão: Luhn, validade e CVV por bandeira.
        if ($paymentMethod === 'credit_card') {
            $cardValidationError = $this->validateCardData($validated);
            if ($cardValidationError) {
                return response()->json([
                    'error' => $cardValidationError['message'],
                    'field' => $cardValidationError['field'],
                ], 422);
            }

            $duplicate = $this->getDuplicateFailedAttempt($validated);
            if ($duplicate) {
                return response()->json([
                    'error' => $duplicate->error_message ?: 'Este cartão não foi autorizado. Verifique os dados ou tente outro cartão.',
                    'field' => 'card_number',
                ], 422);
            }

            $failedAttempts = $this->countRecentFailedAttempts($validated);
            if ($failedAttempts >= self::MAX_FAILED_CARD_ATTEMPTS) {
                return response()->json([
                    'error' => 'Você atingiu o limite de 3 tentativas de pagamento com cartão. Tente novamente mais tarde.',
                    'field' => 'card_number',
                ], 422);
            }
        }

        // Resolve gateway for this payment method.
        $gatewayIdMap = [
            'pix' => $settings->pix_gateway_id ?? null,
            'credit_card' => $settings->card_gateway_id ?? null,
            'boleto' => $settings->boleto_gateway_id ?? null,
        ];

        $gatewayId = $gatewayIdMap[$paymentMethod] ?? null;
        $gateway = null;

        if ($gatewayId) {
            $gateway = $store->gateways()->where('id', $gatewayId)->where('is_active', true)->first();
        }

        // Fallback: first active gateway
        if (!$gateway) {
            $gateway = $store->gateways()->where('is_active', true)->first();
        }

        if (!$gateway || !$gateway->secret_key) {
            return response()->json(['error' => 'No active payment gateway configured for this method'], 400);
        }

        // Resolve installment rate and apply to final total for credit card.
        $installments = (int) ($validated['installments'] ?? 1);
        $installmentLimit = (int) ($gateway->installment_limit ?? 12);
        if ($installments > $installmentLimit) {
            $installments = $installmentLimit;
        }
        if ($installments < 1) {
            $installments = 1;
        }

        $rate = 0.0;
        if ($paymentMethod === 'credit_card' && $installments > ($gateway->interest_free_installments ?? 1)) {
            $type = $gateway->installment_type ?? 'default';
            if ($type === 'custom' && is_array($gateway->installment_rates)) {
                $rate = (float) ($gateway->installment_rates[$installments - 1] ?? 0);
            } else {
                $rate = (float) ($gateway->default_installment_rate ?? 3.14);
            }
        }

        // Compound interest: total = base * (1 + rate/100)^installments
        $interestMultiplier = pow(1 + $rate / 100, $installments);

        // Agrupa itens por product_id somando qty (defensivo contra duplicação).
        $grouped = [];
        foreach ($validated['items'] as $item) {
            $pid = (int) $item['product_id'];
            $qty = max(1, (int) ($item['qty'] ?? 1));
            if (isset($grouped[$pid])) {
                $grouped[$pid] += $qty;
            } else {
                $grouped[$pid] = $qty;
            }
        }

        $productIds = array_keys($grouped);
        $products = $store->products()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return response()->json(['error' => 'No active products found'], 404);
        }

        // Calcula total e monta lista de itens com snapshot.
        $orderItemsData = [];
        $total = 0.0;

        foreach ($grouped as $pid => $qty) {
            $product = $products->get($pid);
            if (!$product) {
                return response()->json(['error' => "Product {$pid} not found or inactive"], 404);
            }
            $unitPrice = (float) $product->price;
            $orderItemsData[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'attributes' => $product->attributes,
                'unit_price' => $unitPrice,
                'qty' => $qty,
            ];
            $total += $unitPrice * $qty;
        }

        $total = round($total, 2);

        // Calcula valor do frete.
        $shippingMethodId = $validated['shipping_method_id'] ?? null;
        $shippingPrice = null;

        if ($shippingMethodId) {
            $shippingMethod = $store->shippingMethods()
                ->where('id', $shippingMethodId)
                ->where('is_active', true)
                ->first();

            if (!$shippingMethod) {
                return response()->json(['error' => 'Shipping method not found or inactive'], 400);
            }

            $methodPrice = $shippingMethod->price ? (float) $shippingMethod->price : 0;
            $minValueFree = $shippingMethod->min_value_free_shipping
                ? (float) $shippingMethod->min_value_free_shipping
                : null;

            $shippingPrice = ($minValueFree !== null && $total >= $minValueFree) ? 0 : $methodPrice;
        }

        $finalTotal = round($total + ($shippingPrice ?? 0), 2);

        // Apply installment interest to credit card payments.
        if ($paymentMethod === 'credit_card' && $interestMultiplier > 1) {
            $finalTotal = round($finalTotal * $interestMultiplier, 2);
        }

        // Cria pedido + itens em transação (atomicidade).
        $order = DB::transaction(function () use ($store, $validated, $orderItemsData, $finalTotal, $shippingMethodId, $shippingPrice, $installments) {
            $ship = $validated['shipping_address'] ?? [];
            $order = Order::create([
                'store_id' => $store->id,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_document' => $validated['customer_document'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'amount' => $finalTotal,
                'payment_method' => $validated['payment_method'],
                'status' => Order::STATUS_PENDING,
                'installments' => $installments,
                'shipping_method_id' => $shippingMethodId,
                'shipping_price' => $shippingPrice,
                'shipping_cep' => $ship['cep'] ?? null,
                'shipping_logradouro' => $ship['logradouro'] ?? null,
                'shipping_numero' => $ship['numero'] ?? null,
                'shipping_complemento' => $ship['complemento'] ?? null,
                'shipping_bairro' => $ship['bairro'] ?? null,
                'shipping_cidade' => $ship['cidade'] ?? null,
                'shipping_uf' => $ship['uf'] ?? null,
            ]);

            foreach ($orderItemsData as $itemData) {
                $order->items()->create($itemData);
            }

            return $order;
        });

        $service = new UnipayService($gateway);
        $postbackUrl = rtrim(config('app.url'), '/') . '/api/webhook/unipay';
        $ip = $request->ip();

        try {
            switch ($validated['payment_method']) {
                case 'pix':
                    $payload = UnipayService::buildPixPayload($order, $postbackUrl, 1, $ip);
                    break;

                case 'credit_card':
                    if (empty($validated['card_number']) || empty($validated['card_holder']) || empty($validated['card_expiry']) || empty($validated['card_cvv'])) {
                        return response()->json(['error' => 'Dados do cartão são obrigatórios para credit_card'], 422);
                    }

                    [$expMonth, $expYear] = explode('/', $validated['card_expiry']);
                    $expMonth = (int) $expMonth;
                    $expYear = (int) ('20' . $expYear);

                    $cardNumberDigits = preg_replace('/\D/', '', $validated['card_number']);
                    $cardBrand = $validated['card_brand'] ?? $this->guessCardBrand($cardNumberDigits);
                    $cardLast4 = $validated['card_last4'] ?? substr($cardNumberDigits, -4);

                    $order->update([
                        'card_brand' => $cardBrand,
                        'card_last4' => $cardLast4,
                        'installments' => $installments,
                    ]);
                    $payload = UnipayService::buildCardPayload(
                        $order,
                        [
                            'number' => $cardNumberDigits,
                            'holderName' => strtoupper(trim($validated['card_holder'])),
                            'expirationMonth' => $expMonth,
                            'expirationYear' => $expYear,
                            'cvv' => $validated['card_cvv'],
                        ],
                        $installments,
                        $postbackUrl,
                        $ip
                    );
                    break;

                case 'boleto':
                    $payload = UnipayService::buildBoletoPayload($order, $postbackUrl, 3, $ip);
                    break;

                default:
                    return response()->json(['error' => 'Unsupported payment_method'], 422);
            }

            $result = $service->createTransaction($payload);

            if ($paymentMethod === 'credit_card') {
                $this->recordCardAttempt($validated, $store, $order, CardPaymentAttempt::STATUS_SUCCESS, null, $result, $ip);
            }
        } catch (UnipayException $e) {
            Log::error('Unipay createTransaction falhou', [
                'order_id' => $order->id,
                'status' => $e->statusCode,
                'body' => $e->body,
                'message' => $e->getMessage(),
            ]);

            $order->update(['status' => Order::STATUS_FAILED]);

            if ($paymentMethod === 'credit_card') {
                $this->recordCardAttempt($validated, $store, $order, CardPaymentAttempt::STATUS_FAILED, $e->getMessage(), $e->body, $ip);
            }

            return response()->json([
                'error' => 'Falha ao criar transação na Unipay',
                'message' => $e->getMessage(),
                'details' => $e->body,
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Erro inesperado ao criar transação na Unipay', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $order->update(['status' => Order::STATUS_FAILED]);

            if ($paymentMethod === 'credit_card') {
                $this->recordCardAttempt($validated, $store, $order, CardPaymentAttempt::STATUS_FAILED, $e->getMessage(), null, $ip);
            }

            return response()->json([
                'error' => 'Falha ao comunicar com a Unipay',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Persiste dados retornados pela Unipay.
        $updateData = [];
        $transactionId = $result['id'] ?? $result['data']['id'] ?? null;
        if ($transactionId) {
            $updateData['gateway_transaction_id'] = (string) $transactionId;
        }

        $returnedStatus = $result['status'] ?? $result['data']['status'] ?? null;
        $mappedStatus = Order::mapFastSoftStatus($returnedStatus);
        if ($mappedStatus) {
            $updateData['status'] = $mappedStatus;
        } elseif ($validated['payment_method'] !== 'credit_card') {
            $updateData['status'] = Order::STATUS_WAITING_PAYMENT;
        } else {
            $updateData['status'] = Order::STATUS_PROCESSING;
        }

        // PIX: qrcode (copia e cola) + expiração.
        $pixData = $result['pix'] ?? $result['data']['pix'] ?? null;
        if (is_array($pixData)) {
            if (!empty($pixData['qrcode'])) {
                $updateData['pix_copia_cola'] = $pixData['qrcode'];
            }
            if (!empty($pixData['expirationDate'])) {
                $updateData['gateway_expires_at'] = $pixData['expirationDate'];
            }
        }

        // Boleto: url + barcode + linha digitável.
        $boletoData = $result['boleto'] ?? $result['data']['boleto'] ?? null;
        if (is_array($boletoData)) {
            if (!empty($boletoData['url'])) {
                $updateData['boleto_url'] = $boletoData['url'];
            }
            if (!empty($boletoData['barcode'])) {
                $updateData['boleto_barcode'] = $boletoData['barcode'];
            }
            if (!empty($boletoData['digitableLine'])) {
                $updateData['boleto_digitable_line'] = $boletoData['digitableLine'];
            }
            if (!empty($boletoData['expirationDate'])) {
                $updateData['gateway_expires_at'] = $boletoData['expirationDate'];
            }
        }

        // Cartão: brand + last4 se retornados.
        $cardData = $result['card'] ?? $result['data']['card'] ?? null;
        if (is_array($cardData)) {
            if (!empty($cardData['brand'])) {
                $updateData['card_brand'] = $cardData['brand'];
            }
            if (!empty($cardData['lastDigits'])) {
                $updateData['card_last4'] = $cardData['lastDigits'];
            }
        }

        if (!empty($updateData)) {
            $order->update($updateData);
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->fresh()->status,
            'payment_method' => $order->payment_method,
            'gateway_transaction_id' => $order->gateway_transaction_id,
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'boleto_url' => $order->boleto_url,
            'boleto_barcode' => $order->boleto_barcode,
            'boleto_digitable_line' => $order->boleto_digitable_line,
            'card_brand' => $order->card_brand,
            'card_last4' => $order->card_last4,
            'installments' => $order->installments,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
        ]);
    }

    /**
     * Retorna o status de pagamento/pedido (PIX, cartão ou boleto).
     */
    public function getPixStatus(int $orderId)
    {
        $order = Order::with('store')->find($orderId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'boleto_url' => $order->boleto_url,
            'boleto_barcode' => $order->boleto_barcode,
            'boleto_digitable_line' => $order->boleto_digitable_line,
            'card_brand' => $order->card_brand,
            'card_last4' => $order->card_last4,
            'installments' => $order->installments,
            'total' => $order->amount,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
            'created_at' => $order->created_at?->toISOString(),
            'store_name' => $order->store?->name,
        ]);
    }

    /**
     * Recebe o webhook/postback da Unipay (FastSoft Brasil).
     *
     * Payload esperado (conforme doc):
     *   { type, objectId, data: { id, status, pix, boleto, card, ... } }
     *
     * Decisão: sem verificação de assinatura/IP (mesmo padrão do mock anterior).
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();

        $data = $payload['data'] ?? $payload;
        $transactionId = $data['id'] ?? $payload['objectId'] ?? $payload['transaction_id'] ?? null;
        $status = $data['status'] ?? $payload['status'] ?? null;

        if (!$transactionId) {
            return response()->json(['error' => 'Invalid payload: missing transaction id'], 400);
        }

        $order = Order::where('gateway_transaction_id', (string) $transactionId)->first();

        if ($order) {
            $mapped = Order::mapFastSoftStatus($status);
            if ($mapped) {
                $order->update(['status' => $mapped]);
            }

            // Atualiza campos extras se vieram no webhook.
            $extra = [];

            $pixData = $data['pix'] ?? null;
            if (is_array($pixData)) {
                if (!empty($pixData['qrcode']) && !$order->pix_copia_cola) {
                    $extra['pix_copia_cola'] = $pixData['qrcode'];
                }
                if (!empty($pixData['expirationDate']) && !$order->gateway_expires_at) {
                    $extra['gateway_expires_at'] = $pixData['expirationDate'];
                }
            }

            $boletoData = $data['boleto'] ?? null;
            if (is_array($boletoData)) {
                if (!empty($boletoData['url']) && !$order->boleto_url) {
                    $extra['boleto_url'] = $boletoData['url'];
                }
                if (!empty($boletoData['barcode']) && !$order->boleto_barcode) {
                    $extra['boleto_barcode'] = $boletoData['barcode'];
                }
                if (!empty($boletoData['digitableLine']) && !$order->boleto_digitable_line) {
                    $extra['boleto_digitable_line'] = $boletoData['digitableLine'];
                }
                if (!empty($boletoData['expirationDate']) && !$order->gateway_expires_at) {
                    $extra['gateway_expires_at'] = $boletoData['expirationDate'];
                }
            }

            $cardData = $data['card'] ?? null;
            if (is_array($cardData)) {
                if (!empty($cardData['brand']) && !$order->card_brand) {
                    $extra['card_brand'] = $cardData['brand'];
                }
                if (!empty($cardData['lastDigits']) && !$order->card_last4) {
                    $extra['card_last4'] = $cardData['lastDigits'];
                }
            }

            if (!empty($extra)) {
                $order->update($extra);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Valida número (Luhn), validade (mês seguinte ao atual) e CVV por bandeira.
     *
     * @return array{field: string, message: string}|null
     */
    private function validateCardData(array $validated): ?array
    {
        $number = preg_replace('/\D/', '', $validated['card_number'] ?? '');

        if (strlen($number) < 13 || strlen($number) > 19) {
            return ['field' => 'card_number', 'message' => 'Número do cartão inválido.'];
        }

        if (!$this->isLuhnValid($number)) {
            return ['field' => 'card_number', 'message' => 'Cartão inválido.'];
        }

        $brand = $this->guessCardBrand($number);
        $cvv = $validated['card_cvv'] ?? '';
        $expectedCvvLength = $brand === 'AMEX' ? 4 : 3;

        if (strlen($cvv) !== $expectedCvvLength) {
            return ['field' => 'card_cvv', 'message' => 'CVV inválido.'];
        }

        $expiry = $validated['card_expiry'] ?? '';
        if (!preg_match('/^\d{2}\/\d{2}$/', $expiry)) {
            return ['field' => 'card_expiry', 'message' => 'Data de validade inválida.'];
        }

        [$expMonth, $expYear] = explode('/', $expiry);
        $expMonth = (int) $expMonth;
        $expYear = (int) ('20' . $expYear);

        if ($expMonth < 1 || $expMonth > 12) {
            return ['field' => 'card_expiry', 'message' => 'Mês de validade inválido.'];
        }

        $minDate = Carbon::now()->addMonthNoOverflow()->startOfMonth();
        $expDate = Carbon::create($expYear, $expMonth, 1)->startOfMonth();

        if ($expDate->lessThan($minDate)) {
            return ['field' => 'card_expiry', 'message' => 'A validade do cartão deve ser de pelo menos um mês após o mês atual.'];
        }

        return null;
    }

    /**
     * Algoritmo de Luhn para validar números de cartão.
     */
    private function isLuhnValid(string $number): bool
    {
        $sum = 0;
        $alternate = false;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int) $number[$i];

            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }

    /**
     * Identifica a bandeira a partir do BIN (apenas heurística para exibição).
     */
    private function guessCardBrand(string $number): ?string
    {
        if (preg_match('/^3[47]/', $number)) {
            return 'AMEX';
        }

        if (preg_match('/^(6011|65|644|645|646|647|648|649|622)/', $number)) {
            return 'DISCOVER';
        }

        if (preg_match('/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6363|650|6516|6550)/', $number)) {
            return 'ELO';
        }

        if (preg_match('/^4/', $number)) {
            return 'VISA';
        }

        if (preg_match('/^(5[1-5]|2[2-7])/', $number)) {
            return 'MASTERCARD';
        }

        return null;
    }

    /**
     * Busca uma tentativa falha com os mesmos dados de cartão, validade e CVV.
     * A restrição é por CPF do cliente; se o CPF não estiver disponível, usa o e-mail.
     */
    private function getDuplicateFailedAttempt(array $validated): ?CardPaymentAttempt
    {
        $number = preg_replace('/\D/', '', $validated['card_number'] ?? '');

        $query = CardPaymentAttempt::where('card_fingerprint', $this->cardFingerprint($number))
            ->where('card_expiry', $validated['card_expiry'])
            ->where('card_cvv_hash', $this->cvvHash($validated['card_cvv'] ?? ''))
            ->where('status', CardPaymentAttempt::STATUS_FAILED);

        if (!empty($validated['customer_document'])) {
            $query->where('customer_document', $validated['customer_document']);
        } else {
            $query->where('customer_email', $validated['customer_email']);
        }

        return $query->latest()->first();
    }

    /**
     * Conta tentativas falhas do cliente nas últimas N horas.
     * A restrição é por CPF do cliente; se o CPF não estiver disponível, usa o e-mail.
     */
    private function countRecentFailedAttempts(array $validated): int
    {
        $query = CardPaymentAttempt::where('status', CardPaymentAttempt::STATUS_FAILED)
            ->where('created_at', '>=', Carbon::now()->subHours(self::FAILED_ATTEMPTS_WINDOW_HOURS));

        if (!empty($validated['customer_document'])) {
            $query->where('customer_document', $validated['customer_document']);
        } else {
            $query->where('customer_email', $validated['customer_email']);
        }

        return $query->count();
    }

    /**
     * Persiste uma tentativa de pagamento com cartão.
     */
    private function recordCardAttempt(
        array $validated,
        Store $store,
        ?Order $order,
        string $status,
        ?string $errorMessage = null,
        ?array $gatewayResponse = null,
        ?string $ip = null
    ): CardPaymentAttempt {
        $number = preg_replace('/\D/', '', $validated['card_number'] ?? '');
        $brand = $validated['card_brand'] ?? $this->guessCardBrand($number);

        return CardPaymentAttempt::create([
            'store_id' => $store->id,
            'order_id' => $order?->id,
            'customer_email' => $validated['customer_email'],
            'customer_document' => $validated['customer_document'] ?? null,
            'card_fingerprint' => $this->cardFingerprint($number),
            'card_last4' => $validated['card_last4'] ?? substr($number, -4),
            'card_expiry' => $validated['card_expiry'] ?? '',
            'card_cvv_hash' => $this->cvvHash($validated['card_cvv'] ?? ''),
            'card_brand' => $brand,
            'status' => $status,
            'error_message' => $errorMessage,
            'gateway_response' => $gatewayResponse,
            'ip_address' => $ip,
        ]);
    }

    /**
     * Fingerprint segura (SHA-256) do número do cartão.
     */
    private function cardFingerprint(string $number): string
    {
        return hash('sha256', $number);
    }

    /**
     * Hash seguro (SHA-256) do CVV.
     */
    private function cvvHash(string $cvv): string
    {
        return hash('sha256', $cvv);
    }
}