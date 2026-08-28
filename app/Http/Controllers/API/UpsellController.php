<?php

namespace App\Http\Controllers\API;

use App\Exceptions\UnipayException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Upsell;
use App\Services\GatewayResolverService;
use App\Services\PaymentIdempotencyService;
use App\Services\PostPurchasePixService;
use App\Services\ShopifyOrderSync;
use App\Services\UnipayService;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
            'offer_type' => 'sometimes|in:upsell,downsell',
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
            'offer_type' => 'sometimes|in:upsell,downsell',
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
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
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

        $offerType = $order->upsell_status === 'declined' ? 'downsell' : 'upsell';
        $offerAlreadyFinished = $offerType === 'upsell'
            ? $order->upsell_status === 'accepted'
            : $order->hasDownsellDecided();
        $upsell = $offerAlreadyFinished
            ? null
            : $this->findApplicableOffer($order, $store, $offerType);

        return response()->json([
            'has_upsell' => $upsell !== null,
            'has_downsell' => $offerType === 'downsell' && $upsell !== null,
            'offer_type' => $upsell ? $offerType : null,
            'upsell' => $upsell ? $this->formatUpsellOffer($upsell) : null,
            'settings' => $store->checkoutSettings?->only([
                'primary_color', 'dark_mode', 'logo_url', 'banner_url', 'banner_height', 'banner_message',
                'header_store_name_visible', 'header_secure_badge', 'header_logo_alignment',
                'header_bg_color', 'header_icon_color', 'font_family', 'font_size_base',
                'announcement_bar_enabled', 'announcement_bar_bg', 'announcement_bar_text_color',
                'upsell_bg_color', 'upsell_border_color', 'upsell_text_color',
                'upsell_button_color', 'upsell_button_text_color',
                'downsell_bg_color', 'downsell_border_color', 'downsell_text_color',
                'downsell_button_color', 'downsell_button_text_color',
            ]) ?? [],
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
        return $this->chargeOffer($request, 'upsell');
    }

    /**
     * Endpoint público: processa a cobrança do downsell.
     */
    public function chargeDownsell(Request $request)
    {
        return $this->chargeOffer($request, 'downsell');
    }

    private function chargeOffer(Request $request, string $offerType)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'order_id' => 'required|integer|exists:orders,id',
            'upsell_id' => 'required|integer|exists:upsells,id',
            'variant_id' => 'nullable|integer|exists:products,id',
            'variant_attributes' => 'nullable|array',
            'installments' => 'nullable|integer|min:1|max:12',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
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

        if ($offerType === 'downsell' && $order->upsell_status !== 'declined') {
            return response()->json(['error' => 'Downsell is only available after declining the upsell'], 422);
        }

        if ($this->hasOfferDecided($order, $offerType)) {
            return response()->json([
                'success' => $this->offerStatus($order, $offerType) === 'accepted',
                'message' => ucfirst($offerType) . ' já foi processado para este pedido.',
            ]);
        }

        $upsell = Upsell::where('id', $validated['upsell_id'])
            ->where('store_id', $store->id)
            ->ofType($offerType)
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

        $variantProduct = $upsell->product;
        if (!empty($validated['variant_id'])) {
            $selectedVariant = $store->products()
                ->where('id', $validated['variant_id'])
                ->where('shopify_product_id', $upsell->product->shopify_product_id)
                ->first();
            if ($selectedVariant) {
                $variantProduct = $selectedVariant;
            }
        }

        $statusColumn = $this->offerStatusColumn($offerType);
        app(PaymentIdempotencyService::class)->attachOrder($request, $order);
        $claimStatus = DB::transaction(function () use ($order, $offerType, $statusColumn) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (!$lockedOrder->isApproved() || ($offerType === 'downsell' && $lockedOrder->upsell_status !== 'declined')) {
                return 'unavailable';
            }
            if ($this->hasOfferDecided($lockedOrder, $offerType) || $this->isOfferProcessing($lockedOrder, $offerType)) {
                return $this->offerStatus($lockedOrder, $offerType);
            }

            $lockedOrder->update([$statusColumn => 'processing']);

            return 'claimed';
        });

        if ($claimStatus === 'unavailable' || $claimStatus === 'declined') {
            return response()->json(['success' => false, 'message' => 'Esta oferta não está mais disponível.'], 422);
        }

        if ($claimStatus === 'accepted') {
            return response()->json(['success' => true, 'message' => 'Esta oferta já foi processada.']);
        }

        if ($claimStatus === 'processing') {
            return response()->json([
                'success' => false,
                'order_id' => $order->id,
                'status' => Order::STATUS_PROCESSING,
                'idempotency_status' => 'processing',
                'retry_after_seconds' => 2,
            ], 202, ['Retry-After' => '2']);
        }

        app(PaymentIdempotencyService::class)->markGatewayStarted($request, $order);

        try {
            if ($order->payment_method === 'credit_card') {
                $response = $this->chargeCard($request, $order, $upsell, $variantProduct, $finalPrice, $variantAttributes, $validated['installments'] ?? 1);
            } elseif ($order->payment_method === 'pix') {
                $response = $this->chargePix($request, $order, $upsell, $variantProduct, $finalPrice, $variantAttributes);
            } else {
                $response = response()->json(['error' => 'Payment method not supported for upsell'], 400);
            }

            if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                Order::whereKey($order->id)
                    ->where($statusColumn, 'processing')
                    ->update([$statusColumn => null]);
            }

            return $response;
        } catch (\Throwable $e) {
            // Um erro após iniciar a gateway pode representar uma cobrança em andamento.
            // Mantenha o bloqueio até a reconciliação da intenção de pagamento.
            Log::error('Erro ao processar oferta adicional', [
                'order_id' => $order->id,
                'upsell_id' => $upsell->id,
                'offer_type' => $offerType,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível confirmar a cobrança da oferta. Aguarde a confirmação.',
            ], 500);
        }
    }

    /**
     * Endpoint público: cliente recusa o upsell.
     */
    public function decline(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'order_id' => 'required|integer|exists:orders,id',
            'offer_type' => 'sometimes|in:upsell,downsell',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
        if (!$store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $offerType = $validated['offer_type'] ?? 'upsell';
        return DB::transaction(function () use ($validated, $store, $offerType) {
            $order = Order::where('id', $validated['order_id'])
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$order->isApproved()) {
                return response()->json(['error' => 'Order is not approved'], 400);
            }

            if ($offerType === 'downsell' && $order->upsell_status !== 'declined') {
                return response()->json(['error' => 'Downsell is only available after declining the upsell'], 422);
            }

            if ($this->hasOfferDecided($order, $offerType)) {
                return response()->json(['success' => true, 'message' => ucfirst($offerType) . ' already decided']);
            }

            if ($this->isOfferProcessing($order, $offerType)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A cobrança da oferta ainda está sendo confirmada.',
                ], 409);
            }

            if (!$this->findApplicableOffer($order, $store, $offerType)) {
                return response()->json(['error' => 'No applicable offer to decline'], 422);
            }

            $order->update([$this->offerStatusColumn($offerType) => 'declined']);

            return response()->json(['success' => true]);
        });
    }

    /**
     * Busca uma oferta ativa aplicável ao pedido.
     */
    private function findApplicableOffer(Order $order, Store $store, string $offerType): ?Upsell
    {
        $paymentMethod = $order->payment_method;

        $query = $store->upsells()
            ->ofType($offerType)
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

    private function offerStatusColumn(string $offerType): string
    {
        return $offerType === 'downsell' ? 'downsell_status' : 'upsell_status';
    }

    private function offerStatus(Order $order, string $offerType): ?string
    {
        return $offerType === 'downsell' ? $order->downsell_status : $order->upsell_status;
    }

    private function hasOfferDecided(Order $order, string $offerType): bool
    {
        return in_array($this->offerStatus($order, $offerType), ['accepted', 'declined'], true);
    }

    private function isOfferProcessing(Order $order, string $offerType): bool
    {
        return $this->offerStatus($order, $offerType) === 'processing';
    }

    /**
     * Normaliza o número de parcelamentos dentro do limite global do checkout.
     */
    private function normalizeInstallments($gateway, int $installments, ?int $configuredLimit = null): int
    {
        $limit = (int) ($configuredLimit ?? $gateway->installment_limit ?? 12);
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
     * Monta as configurações de parcelamento usando as taxas da gateway do pedido
     * e os limites globais do checkout.
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
        $limit = max(1, min(12, (int) ($settings?->card_installment_limit ?? 12)));
        $preSelected = max(
            1,
            min($limit, (int) ($settings?->card_pre_selected_installment ?? 1))
        );
        $interestFree = max(
            1,
            min($limit, (int) ($gateway->interest_free_installments ?? 1))
        );

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

        $variants = [];
        if ($product && $product->shopify_product_id) {
            $variants = $product->store
                ->products()
                ->where('shopify_product_id', $product->shopify_product_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'image_url', 'attributes'])
                ->toArray();
        }

        return [
            'id' => $upsell->id,
            'offer_type' => $upsell->offer_type,
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
                'variants' => $variants,
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
    private function chargeCard(Request $request, Order $order, Upsell $upsell, Product $variantProduct, float $finalPrice, ?array $variantAttributes, int $installments)
    {
        if (empty($order->card_token)) {
            return response()->json([
                'success' => false,
                'message' => 'Cartão não disponível para cobrança automática.',
            ], 422);
        }

        // Simulação para cartões de teste (gateway_transaction_id iniciado com 'test-')
        if (str_starts_with((string) $order->gateway_transaction_id, 'test-')) {
            $this->applyUpsellToOrder($order, $upsell, $variantProduct, $finalPrice, $variantAttributes, $order->gateway ?? $order->store->gateways()->where('is_active', true)->first());
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
        $installments = $this->normalizeInstallments(
            $primaryGateway,
            $installments,
            $order->store->checkoutSettings?->card_installment_limit
        );
        $finalPrice = $this->applyInstallmentInterest($primaryGateway, $finalPrice, $installments);

        $payload = $this->buildUpsellCardPayload($order, $finalPrice, $installments, $upsell->offer_type);
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
            } catch (UnipayException $e) {
                if (($e->statusCode ?? 500) >= 500) {
                    throw $e;
                }

                $lastError = $e;
                Log::warning('Upsell cartão: gateway falhou, tentando fallback', [
                    'order_id' => $order->id,
                    'gateway_id' => $gwCandidate->id,
                    'provider' => $gwCandidate->provider,
                    'attempt' => $idx + 1,
                    'message' => $e->getMessage(),
                ]);
                continue;
            } catch (ConnectionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => $lastError ? $lastError->getMessage() : 'Todas as gateways falharam.',
            ], 422);
        }

        // Adiciona item ao pedido existente
        $this->applyUpsellToOrder($order, $upsell, $variantProduct, $finalPrice, $variantAttributes, $usedGateway);

        $transactionId = $result['id'] ?? $result['data']['id'] ?? null;
        app(PaymentIdempotencyService::class)->attachGatewayTransaction($request, $transactionId ? (string) $transactionId : null);

        return response()->json([
            'success' => true,
            'gateway_id' => $usedGateway->id,
        ]);
    }

    /**
     * Processa geração de PIX para upsell.
     */
    private function chargePix(Request $request, Order $order, Upsell $upsell, Product $variantProduct, float $finalPrice, ?array $variantAttributes)
    {
        $gatewaysToTry = GatewayResolverService::resolve($order->store, 'pix', $order->gateway_id);

        if (empty($gatewaysToTry)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma gateway PIX ativa configurada.',
            ], 400);
        }

        $payload = $this->buildUpsellPixPayload($order, $finalPrice, $upsell->offer_type);
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
            } catch (UnipayException $e) {
                if (($e->statusCode ?? 500) >= 500) {
                    throw $e;
                }

                $lastError = $e;
                Log::warning('Upsell PIX: gateway falhou, tentando fallback', [
                    'order_id' => $order->id,
                    'gateway_id' => $gwCandidate->id,
                    'provider' => $gwCandidate->provider,
                    'attempt' => $idx + 1,
                    'message' => $e->getMessage(),
                ]);
                continue;
            } catch (ConnectionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => $lastError ? $lastError->getMessage() : 'Todas as gateways falharam.',
            ], 422);
        }

        $response = app(PostPurchasePixService::class)->stage(
            $order, $upsell, $variantProduct, $finalPrice, $variantAttributes, $usedGateway, $result
        );

        $transactionId = $result['id'] ?? $result['data']['id'] ?? null;
        app(PaymentIdempotencyService::class)->attachGatewayTransaction($request, $transactionId ? (string) $transactionId : null);

        return response()->json($response);
    }

    /**
     * Aplica o upsell ao pedido existente (unificação).
     */
    private function applyUpsellToOrder(
        Order $order,
        Upsell $upsell,
        Product $variantProduct,
        float $finalPrice,
        ?array $variantAttributes,
        $usedGateway
    ): void {
        $offerType = $upsell->offer_type === 'downsell' ? 'downsell' : 'upsell';
        $item = DB::transaction(function () use ($order, $upsell, $variantProduct, $finalPrice, $variantAttributes, $usedGateway) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $offerType = $upsell->offer_type === 'downsell' ? 'downsell' : 'upsell';
            $statusColumn = $this->offerStatusColumn($offerType);
            if ($lockedOrder->{$statusColumn} === 'accepted') {
                return null;
            }

            $item = OrderItem::create([
                'order_id' => $lockedOrder->id,
                'product_id' => $variantProduct->id,
                'name' => $variantProduct->name,
                'qty' => 1,
                'unit_price' => $finalPrice,
                'attributes' => $variantAttributes ?? $variantProduct->attributes,
            ]);

            $updateData = [
                'amount' => round((float) $lockedOrder->amount + $finalPrice, 2),
                "{$offerType}_id" => $upsell->id,
                "{$offerType}_amount" => $finalPrice,
                $statusColumn => 'accepted',
                "{$offerType}_product_id" => $variantProduct->id,
                'gateway_id' => $usedGateway?->id ?? $lockedOrder->gateway_id,
            ];

            $lockedOrder->update($updateData);

            return $item;
        });

        if (! $item) {
            return;
        }

        // Sincroniza o item de upsell no pedido Shopify já existente (best-effort).
        // Se o pedido Shopify ainda não existir, o item será incluído quando
        // markAsPaid/create forem chamados posteriormente.
        try {
            $store = $order->store;
            if ($store && $store->isShopifyConnected()) {
                app(ShopifyOrderSync::class)->syncExtraItem($store, $order->fresh(), $item);
            }
        } catch (\Throwable $e) {
            Log::warning('Shopify sync da oferta adicional falhou', [
                'order_id' => $order->id,
                'offer_id' => $upsell->id,
                'offer_type' => $offerType,
                'error' => $e->getMessage(),
            ]);
        }

        $order->refresh();
    }

    /**
     * Monta payload de cobrança de upsell no cartão.
     */
    private function buildUpsellCardPayload(Order $order, float $amount, int $installments = 1, string $offerType = 'upsell'): array
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
            'description' => ucfirst($offerType) . ' - Pedido #' . $order->id,
            'order_id' => $order->id,
            'installments' => max(1, $installments),
        ];
    }

    /**
     * Monta payload de geração de PIX para upsell.
     */
    private function buildUpsellPixPayload(Order $order, float $amount, string $offerType = 'upsell'): array
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
            'description' => ucfirst($offerType) . ' - Pedido #' . $order->id,
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
