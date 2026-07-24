<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Upsell;
use App\Services\GatewayResolverService;
use App\Services\ShopifyOrderSync;
use App\Services\UnipayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpsellController extends Controller
{
    /**
     * Listar upsells da loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $upsells = $store->upsells()
            ->with(['product:id,name,price,image_url', 'targetProduct:id,name,price'])
            ->latest()
            ->get();

        return response()->json($upsells);
    }

    /**
     * Criar novo upsell.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer|exists:products,id',
            'discount_value' => 'required|numeric|min:0',
            'discount_type' => 'required|in:fixed,percent',
            'scope' => 'required|in:any,specific',
            'target_product_id' => 'nullable|integer|exists:products,id',
            'show_credit_card' => 'boolean',
            'show_pix' => 'boolean',
            'show_boleto' => 'boolean',
            'offer_title' => 'nullable|string|max:255',
            'offer_message' => 'nullable|string|max:2000',
            'bg_color' => 'nullable|string|max:20',
            'border_color' => 'nullable|string|max:20',
            'button_color' => 'nullable|string|max:20',
            'button_text_color' => 'nullable|string|max:20',
            'button_label' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $ownsProduct = $store->products()->where('id', $validated['product_id'])->exists();
        if (!$ownsProduct) {
            return response()->json(['error' => 'Product does not belong to this store'], 422);
        }

        if (!empty($validated['target_product_id'])) {
            $ownsTarget = $store->products()->where('id', $validated['target_product_id'])->exists();
            if (!$ownsTarget) {
                return response()->json(['error' => 'Target product does not belong to this store'], 422);
            }
        }

        $upsell = $store->upsells()->create($validated);
        $upsell->load(['product:id,name,price,image_url', 'targetProduct:id,name,price']);

        return response()->json($upsell, 201);
    }

    /**
     * Atualizar upsell.
     */
    public function update(Request $request, string $storeId, string $upsellId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $upsell = $store->upsells()->findOrFail($upsellId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'product_id' => 'sometimes|required|integer|exists:products,id',
            'discount_value' => 'sometimes|required|numeric|min:0',
            'discount_type' => 'sometimes|required|in:fixed,percent',
            'scope' => 'sometimes|required|in:any,specific',
            'target_product_id' => 'nullable|integer|exists:products,id',
            'show_credit_card' => 'boolean',
            'show_pix' => 'boolean',
            'show_boleto' => 'boolean',
            'offer_title' => 'sometimes|nullable|string|max:255',
            'offer_message' => 'sometimes|nullable|string|max:2000',
            'bg_color' => 'sometimes|nullable|string|max:20',
            'border_color' => 'sometimes|nullable|string|max:20',
            'button_color' => 'sometimes|nullable|string|max:20',
            'button_text_color' => 'sometimes|nullable|string|max:20',
            'button_label' => 'sometimes|nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        if (!empty($validated['product_id'])) {
            $ownsProduct = $store->products()->where('id', $validated['product_id'])->exists();
            if (!$ownsProduct) {
                return response()->json(['error' => 'Product does not belong to this store'], 422);
            }
        }

        if (array_key_exists('target_product_id', $validated) && !empty($validated['target_product_id'])) {
            $ownsTarget = $store->products()->where('id', $validated['target_product_id'])->exists();
            if (!$ownsTarget) {
                return response()->json(['error' => 'Target product does not belong to this store'], 422);
            }
        }

        $upsell->update($validated);
        $upsell->load(['product:id,name,price,image_url', 'targetProduct:id,name,price']);

        return response()->json($upsell);
    }

    /**
     * Remover upsell.
     */
    public function destroy(Request $request, string $storeId, string $upsellId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $upsell = $store->upsells()->findOrFail($upsellId);
        $upsell->delete();

        return response()->json(null, 204);
    }

    /**
     * Endpoint público: retorna a oferta de upsell disponível para um pedido.
     */
    public function getOffer(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $store = Store::resolveByDomain($validated['domain']);
        if (!$store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('store_id', $store->id)
            ->with(['items.product:id,name,image_url'])
            ->firstOrFail();

        if (!$order->isApproved()) {
            return response()->json(['error' => 'Order is not approved'], 400);
        }

        if ($order->hasUpsellDecided()) {
            return response()->json([
                'has_upsell' => false,
                'upsell' => null,
                'order' => [
                    'id' => $order->id,
                    'payment_method' => $order->payment_method,
                    'card_brand' => $order->card_brand,
                    'card_last4' => $order->card_last4,
                ],
            ]);
        }

        $upsell = $this->findApplicableUpsell($order, $store);

        return response()->json([
            'has_upsell' => $upsell !== null,
            'upsell' => $upsell ? $this->formatUpsellOffer($upsell) : null,
            'order' => [
                'id' => $order->id,
                'payment_method' => $order->payment_method,
                'card_brand' => $order->card_brand,
                'card_last4' => $order->card_last4,
            ],
            'installment_config' => $order->payment_method === 'credit_card'
                ? $this->buildInstallmentConfig($store, $order->gateway_id)
                : null,
        ]);
    }

    /**
     * Endpoint público: processa a cobrança do upsell.
     */
    public function charge(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'order_id' => 'required|integer|exists:orders,id',
            'upsell_id' => 'required|integer|exists:upsells,id',
            'variant_attributes' => 'nullable|array',
            'installments' => 'nullable|integer|min:1|max:12',
        ]);

        $store = Store::resolveByDomain($validated['domain']);
        if (!$store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('store_id', $store->id)
            ->with('items')
            ->firstOrFail();

        if (!$order->isApproved()) {
            return response()->json(['error' => 'Order is not approved'], 400);
        }

        if ($order->hasUpsellDecided()) {
            return response()->json([
                'success' => $order->upsell_status === 'accepted',
                'message' => 'Upsell já foi processado para este pedido.',
            ]);
        }

        $upsell = Upsell::where('id', $validated['upsell_id'])
            ->where('store_id', $store->id)
            ->active()
            ->with('product')
            ->first();

        if (!$upsell) {
            return response()->json(['error' => 'Upsell not found or inactive'], 404);
        }

        if (!$this->isUpsellApplicable($upsell, $order)) {
            return response()->json(['error' => 'Upsell not applicable to this order'], 422);
        }

        if ($order->payment_method === 'credit_card' && !$upsell->show_credit_card) {
            return response()->json(['error' => 'Upsell not available for credit card'], 422);
        }

        if ($order->payment_method === 'pix' && !$upsell->show_pix) {
            return response()->json(['error' => 'Upsell not available for pix'], 422);
        }

        $finalPrice = $upsell->calculateDiscountedPrice();
        $variantAttributes = $validated['variant_attributes'] ?? null;

        try {
            if ($order->payment_method === 'credit_card') {
                return $this->chargeCard($order, $upsell, $finalPrice, $variantAttributes, $validated['installments'] ?? 1);
            }

            if ($order->payment_method === 'pix') {
                return $this->chargePix($order, $upsell, $finalPrice, $variantAttributes);
            }

            return response()->json(['error' => 'Payment method not supported for upsell'], 400);
        } catch (\Throwable $e) {
            Log::error('Erro ao processar upsell', [
                'order_id' => $order->id,
                'upsell_id' => $upsell->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar o upsell: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Endpoint público: cliente recusa o upsell.
     */
    public function decline(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $store = Store::resolveByDomain($validated['domain']);
        if (!$store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('store_id', $store->id)
            ->firstOrFail();

        if ($order->hasUpsellDecided()) {
            return response()->json(['success' => true, 'message' => 'Upsell already decided']);
        }

        $order->update(['upsell_status' => 'declined']);

        return response()->json(['success' => true]);
    }

    /**
     * Busca um upsell ativo aplicável ao pedido.
     */
    private function findApplicableUpsell(Order $order, Store $store): ?Upsell
    {
        $paymentMethod = $order->payment_method;

        $query = $store->upsells()
            ->active()
            ->where(function ($q) use ($paymentMethod) {
                if ($paymentMethod === 'credit_card') {
                    $q->where('show_credit_card', true);
                } elseif ($paymentMethod === 'pix') {
                    $q->where('show_pix', true);
                } elseif ($paymentMethod === 'boleto') {
                    $q->where('show_boleto', true);
                }
            })
            ->with('product');

        $upsells = $query->get();

        foreach ($upsells as $upsell) {
            if ($this->isUpsellApplicable($upsell, $order)) {
                return $upsell;
            }
        }

        return null;
    }

    /**
     * Normaliza o número de parcelamentos dentro do limite configurado na gateway.
     */
    private function normalizeInstallments($gateway, int $installments): int
    {
        $limit = (int) ($gateway->installment_limit ?? 12);
        if ($installments > $limit) {
            $installments = $limit;
        }
        if ($installments < 1) {
            $installments = 1;
        }
        return $installments;
    }

    /**
     * Aplica juros compostos ao valor conforme configuração de parcelamento da gateway.
     * Fórmula idêntica ao checkout principal.
     */
    private function applyInstallmentInterest($gateway, float $amount, int $installments): float
    {
        $rate = 0.0;
        if ($installments > ($gateway->interest_free_installments ?? 1)) {
            $type = $gateway->installment_type ?? 'default';
            if ($type === 'custom' && is_array($gateway->installment_rates)) {
                $rate = (float) ($gateway->installment_rates[$installments - 1] ?? 0);
            } else {
                $rate = (float) ($gateway->default_installment_rate ?? 3.14);
            }
        }

        if ($rate <= 0) {
            return $amount;
        }

        return round($amount * pow(1 + $rate / 100, $installments), 2);
    }

    /**
     * Monta as configurações de parcelamento usando a gateway do pedido ou
     * a mesma lógica de fallback do checkout.
     */
    private function buildInstallmentConfig(Store $store, ?int $orderGatewayId): ?array
    {
        $settings = $store->checkoutSettings;

        $gateway = null;
        if ($orderGatewayId) {
            $gateway = $store->gateways()->where('id', $orderGatewayId)->where('is_active', true)->first();
        }

        if (!$gateway && !empty($settings->card_gateway_ids) && is_array($settings->card_gateway_ids)) {
            foreach ($settings->card_gateway_ids as $gwId) {
                $candidate = $store->gateways()->where('id', $gwId)->where('is_active', true)->first();
                if ($candidate) {
                    $gateway = $candidate;
                    break;
                }
            }
        }

        if (!$gateway && ($settings->card_gateway_id ?? null)) {
            $gateway = $store->gateways()->where('id', $settings->card_gateway_id)->where('is_active', true)->first();
        }

        if (!$gateway) {
            $gateway = $store->gateways()->where('is_active', true)->first();
        }

        if (!$gateway) {
            return null;
        }

        $installmentType = $gateway->installment_type ?? 'default';
        $defaultRate = (float) ($gateway->default_installment_rate ?? 3.14);
        $customRates = $gateway->installment_rates ?? array_fill(0, 12, $defaultRate);
        $preSelected = (int) ($gateway->pre_selected_installment ?? 1);
        $limit = (int) ($gateway->installment_limit ?? 12);
        $interestFree = (int) ($gateway->interest_free_installments ?? 1);

        return [
            'type' => $installmentType,
            'default_rate' => $defaultRate,
            'rates' => array_values($customRates),
            'pre_selected' => $preSelected,
            'limit' => $limit,
            'interest_free' => $interestFree,
        ];
    }

    /**
     * Verifica se o upsell é aplicável ao pedido considerando escopo.
     */
    private function isUpsellApplicable(Upsell $upsell, Order $order): bool
    {
        if ($upsell->scope === 'any') {
            return true;
        }

        if ($upsell->scope === 'specific' && $upsell->target_product_id) {
            return $order->items->contains('product_id', $upsell->target_product_id);
        }

        return false;
    }

    /**
     * Formata o upsell para resposta pública.
     */
    private function formatUpsellOffer(Upsell $upsell): array
    {
        $product = $upsell->product;
        $finalPrice = $upsell->calculateDiscountedPrice();

        return [
            'id' => $upsell->id,
            'name' => $upsell->name,
            'product_id' => $upsell->product_id,
            'discount_value' => $upsell->discount_value,
            'discount_type' => $upsell->discount_type,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->image_url,
                'original_price' => (float) $product->price,
                'upsell_price' => $finalPrice,
                'attributes' => $product->attributes,
            ],
            'offer_title' => $upsell->offer_title,
            'offer_message' => $upsell->offer_message,
            'button_label' => $upsell->button_label,
            'bg_color' => $upsell->bg_color,
            'border_color' => $upsell->border_color,
            'button_color' => $upsell->button_color,
            'button_text_color' => $upsell->button_text_color,
        ];
    }

    /**
     * Processa cobrança de upsell no cartão.
     */
    private function chargeCard(Order $order, Upsell $upsell, float $finalPrice, ?array $variantAttributes, int $installments)
    {
        if (empty($order->card_token)) {
            return response()->json([
                'success' => false,
                'message' => 'Cartão não disponível para cobrança automática.',
            ], 422);
        }

        // Simulação para cartões de teste (gateway_transaction_id iniciado com 'test-')
        if (str_starts_with((string) $order->gateway_transaction_id, 'test-')) {
            $this->applyUpsellToOrder($order, $upsell, $finalPrice, $variantAttributes, $order->gateway ?? $order->store->gateways()->where('is_active', true)->first());
            return response()->json(['success' => true]);
        }

        $gatewaysToTry = GatewayResolverService::resolve($order->store, 'credit_card', $order->gateway_id);

        if (empty($gatewaysToTry)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma gateway de cartão ativa configurada.',
            ], 400);
        }

        // Aplica as mesmas taxas de parcelamento configuradas na gateway (igual ao checkout).
        $primaryGateway = $gatewaysToTry[0];
        $installments = $this->normalizeInstallments($primaryGateway, $installments);
        $finalPrice = $this->applyInstallmentInterest($primaryGateway, $finalPrice, $installments);

        $payload = $this->buildUpsellCardPayload($order, $finalPrice, $installments);
        $result = null;
        $lastError = null;
        $usedGateway = null;

        foreach ($gatewaysToTry as $idx => $gwCandidate) {
            try {
                $service = $this->getGatewayService($gwCandidate);
                $result = $service->createTransaction($payload);
                $usedGateway = $gwCandidate;

                if ($idx > 0) {
                    Log::info('Upsell cartão: gateway fallback bem-sucedido', [
                        'order_id' => $order->id,
                        'gateway_id' => $gwCandidate->id,
                        'provider' => $gwCandidate->provider,
                        'attempt' => $idx + 1,
                    ]);
                }

                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('Upsell cartão: gateway falhou, tentando fallback', [
                    'order_id' => $order->id,
                    'gateway_id' => $gwCandidate->id,
                    'provider' => $gwCandidate->provider,
                    'attempt' => $idx + 1,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => $lastError ? $lastError->getMessage() : 'Todas as gateways falharam.',
            ], 422);
        }

        // Adiciona item ao pedido existente
        $this->applyUpsellToOrder($order, $upsell, $finalPrice, $variantAttributes, $usedGateway);

        return response()->json([
            'success' => true,
            'gateway_id' => $usedGateway->id,
        ]);
    }

    /**
     * Processa geração de PIX para upsell.
     */
    private function chargePix(Order $order, Upsell $upsell, float $finalPrice, ?array $variantAttributes)
    {
        $gatewaysToTry = GatewayResolverService::resolve($order->store, 'pix', $order->gateway_id);

        if (empty($gatewaysToTry)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma gateway PIX ativa configurada.',
            ], 400);
        }

        $payload = $this->buildUpsellPixPayload($order, $finalPrice);
        $result = null;
        $lastError = null;
        $usedGateway = null;

        foreach ($gatewaysToTry as $idx => $gwCandidate) {
            try {
                $service = $this->getGatewayService($gwCandidate);
                $result = $service->createTransaction($payload);
                $usedGateway = $gwCandidate;

                if ($idx > 0) {
                    Log::info('Upsell PIX: gateway fallback bem-sucedido', [
                        'order_id' => $order->id,
                        'gateway_id' => $gwCandidate->id,
                        'provider' => $gwCandidate->provider,
                        'attempt' => $idx + 1,
                    ]);
                }

                break;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('Upsell PIX: gateway falhou, tentando fallback', [
                    'order_id' => $order->id,
                    'gateway_id' => $gwCandidate->id,
                    'provider' => $gwCandidate->provider,
                    'attempt' => $idx + 1,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => $lastError ? $lastError->getMessage() : 'Todas as gateways falharam.',
            ], 422);
        }

        $this->applyUpsellToOrder($order, $upsell, $finalPrice, $variantAttributes, $usedGateway, $result);

        return response()->json([
            'success' => true,
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
        ]);
    }

    /**
     * Aplica o upsell ao pedido existente (unificação).
     */
    private function applyUpsellToOrder(
        Order $order,
        Upsell $upsell,
        float $finalPrice,
        ?array $variantAttributes,
        $usedGateway,
        ?array $gatewayResult = null
    ): void {
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $upsell->product_id,
            'name' => $upsell->product->name,
            'qty' => 1,
            'unit_price' => $finalPrice,
            'attributes' => $variantAttributes,
        ]);

        // Sincroniza o item de upsell no pedido Shopify já existente (best-effort).
        // Se o pedido Shopify ainda não existir, o item será incluído quando
        // markAsPaid/create forem chamados posteriormente.
        try {
            $store = $order->store;
            if ($store && $store->isShopifyConnected()) {
                app(ShopifyOrderSync::class)->syncExtraItem($store, $order->fresh(), $item);
            }
        } catch (\Throwable $e) {
            Log::warning('Shopify sync do upsell falhou', [
                'order_id' => $order->id,
                'upsell_id' => $upsell->id,
                'error' => $e->getMessage(),
            ]);
        }

        $updateData = [
            'amount' => round((float) $order->amount + $finalPrice, 2),
            'upsell_id' => $upsell->id,
            'upsell_amount' => $finalPrice,
            'upsell_status' => 'accepted',
            'upsell_product_id' => $upsell->product_id,
            'gateway_id' => $usedGateway->id,
        ];

        if ($gatewayResult && $order->payment_method === 'pix') {
            $pixData = $gatewayResult['pix'] ?? $gatewayResult['data']['pix'] ?? null;
            if (is_array($pixData)) {
                $updateData['pix_copia_cola'] = $pixData['qrcode'] ?? $pixData['copyPaste'] ?? null;
            }
            $updateData['gateway_transaction_id'] = $gatewayResult['id'] ?? $gatewayResult['transaction_id'] ?? null;

            $expiresAt = null;
            if (is_array($pixData) && !empty($pixData['expirationDate'])) {
                $expiresAt = $pixData['expirationDate'];
            } elseif (!empty($gatewayResult['expiration_date'])) {
                $expiresAt = $gatewayResult['expiration_date'];
            } elseif (!empty($gatewayResult['expires_at'])) {
                $expiresAt = $gatewayResult['expires_at'];
            }
            if ($expiresAt) {
                $updateData['gateway_expires_at'] = $expiresAt;
            }
        }

        $order->update($updateData);
    }

    /**
     * Monta payload de cobrança de upsell no cartão.
     */
    private function buildUpsellCardPayload(Order $order, float $amount, int $installments = 1): array
    {
        return [
            'transaction_type' => 'credit_card',
            'amount' => (int) round($amount * 100),
            'card_token' => $order->card_token,
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'document' => $order->customer_document,
                'phone' => $order->customer_phone,
            ],
            'description' => 'Upsell - Pedido #' . $order->id,
            'order_id' => $order->id,
            'installments' => max(1, $installments),
        ];
    }

    /**
     * Monta payload de geração de PIX para upsell.
     */
    private function buildUpsellPixPayload(Order $order, float $amount): array
    {
        return [
            'transaction_type' => 'pix',
            'amount' => (int) round($amount * 100),
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'document' => $order->customer_document,
                'phone' => $order->customer_phone,
            ],
            'description' => 'Upsell - Pedido #' . $order->id,
            'order_id' => $order->id,
        ];
    }

    /**
     * Factory para serviços de gateway.
     */
    private function getGatewayService($gateway)
    {
        return match ($gateway->provider) {
            'unipay' => new UnipayService($gateway),
            default => throw new \Exception('Provedor de gateway não suportado: ' . $gateway->provider),
        };
    }
}
