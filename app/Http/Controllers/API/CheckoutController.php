<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Gateway;
use App\Models\OrderBump;
use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    private function defaultSettings()
    {
        return (object) [
            'primary_color' => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'dark_mode' => true,
            'enable_order_bump' => true,
            'order_bump_display_mode' => 'stacked',
            'order_bump_scarcity_timer_enabled' => false,
            'order_bump_scarcity_timer_minutes' => 10,
            'order_bump_bg_color' => '#FEFCE8',
            'order_bump_border_color' => '#E2E8F0',
            'order_bump_button_color' => '#13BF8C',
            'order_bump_button_text_color' => '#FFFFFF',
            'upsell_bg_color' => '#FFFFFF',
            'upsell_border_color' => '#E2E8F0',
            'upsell_text_color' => '#1A1A1A',
            'upsell_button_color' => '#22C55E',
            'upsell_button_text_color' => '#FFFFFF',
            'gift_bg_color' => '#F7FFFA',
            'gift_border_color' => '#A4DFC1',
            'gift_badge_bg_color' => '#FFFFFF',
            'gift_badge_border_color' => '#6EE7B7',
            'gift_badge_text_color' => '#10B981',
            'gift_progress_color' => '#10B981',
            'gift_progress_bg_color' => '#E5E7EB',
            'button_text' => 'Finalizar Compra',
            'banner_message' => 'Digite aqui a mensagem',
            'header_store_name_visible' => true,
            'header_secure_badge' => true,
            'announcement_bar_enabled' => true,
            'announcement_bar_bg' => '#333333',
            'announcement_bar_text_color' => '#d4a843',
            'banner_height' => 'md',
            'summary_title' => 'Resumo do pedido',
            'summary_total_text_color' => '#00A37C',
            'summary_default_expanded' => true,
            'summary_show_discount' => true,
            'summary_show_installments' => true,
            'summary_coupon_enabled' => true,
            'quantity_selector_enabled' => true,
            'step_title_font_size' => '1.25rem',
            'scarcity_enabled' => false,
            'scarcity_type' => 'countdown',
            'scarcity_text' => 'Finalize seu pedido em até',
            'scarcity_title' => 'Frete grátis apenas hoje!',
            'scarcity_countdown_minutes' => 20,
            'scarcity_font_color' => '#000000',
            'scarcity_counter_color' => '#000000',
            'scarcity_counter_text_color' => '#ffffff',
            'pix_confirmation_title' => 'Aguardando pagamento...',
            'pix_confirmation_message' => null,
            'pix_confirmation_logo' => null,
            'footer_text' => 'Ambiente seguro · SSL criptografado',
            'footer_show_cnpj' => false,
            'footer_cnpj' => null,
            'font_family' => 'Inter',
            'font_size_base' => '16px',
            'social_proofs_enabled' => true,
            'accept_cpf' => true,
            'accept_cnpj' => false,
            'pix_enabled' => true,
            'card_enabled' => true,
            'boleto_enabled' => false,
            'pix_gateway_id' => null,
            'card_gateway_id' => null,
            'boleto_gateway_id' => null,
            'default_payment_method' => 'credit_card',
            'card_pre_selected_installment' => 1,
            'card_installment_limit' => 12,
            'pix_discount_percentage' => 1,
            'boleto_discount_percentage' => 0,
            'card_discount_percentage' => 5,
        ];
    }

    /**
     * Builds the payment_methods block for the checkout response.
     * Each method includes: enabled, gateway_provider, public_key.
     */
    private function buildPaymentMethods($settings, $store)
    {
        $methods = [
            'pix' => [
                'enabled' => (bool) ($settings->pix_enabled ?? true),
                'gateway_ids' => $settings->pix_gateway_ids ?? null,
                'legacy_gateway_id' => $settings->pix_gateway_id ?? null,
            ],
            'card' => [
                'enabled' => (bool) ($settings->card_enabled ?? true),
                'gateway_ids' => $settings->card_gateway_ids ?? null,
                'legacy_gateway_id' => $settings->card_gateway_id ?? null,
            ],
            'boleto' => [
                'enabled' => (bool) ($settings->boleto_enabled ?? false),
                'gateway_ids' => $settings->boleto_gateway_ids ?? null,
                'legacy_gateway_id' => $settings->boleto_gateway_id ?? null,
            ],
        ];

        $result = [];
        foreach ($methods as $key => $meta) {
            $gateway = null;

            // Try ordered list of gateway IDs first.
            $gwIds = is_array($meta['gateway_ids']) ? $meta['gateway_ids'] : [];
            foreach ($gwIds as $gwId) {
                $candidate = $store->gateways()->where('id', $gwId)->where('is_active', true)->first();
                if ($candidate) {
                    $gateway = $candidate;
                    break;
                }
            }

            // Backward compat: try legacy single gateway_id.
            if (!$gateway && $meta['legacy_gateway_id']) {
                $gateway = $store->gateways()->where('id', $meta['legacy_gateway_id'])->where('is_active', true)->first();
            }

            // Last resort: first active gateway.
            if (!$gateway && $meta['enabled']) {
                $gateway = $store->gateways()->where('is_active', true)->first();
            }

            $entry = [
                'enabled' => $meta['enabled'],
                'gateway_provider' => $gateway?->provider,
                'public_key' => ($gateway && $gateway->provider === 'unipay') ? $gateway->api_key : null,
            ];

            if ($key === 'card' && $gateway) {
                $installmentType = $gateway->installment_type ?? 'default';
                $defaultRate = (float) ($gateway->default_installment_rate ?? 3.14);
                $customRates = $gateway->installment_rates ?? array_fill(0, 12, $defaultRate);
                $limit = max(1, min(12, (int) ($settings->card_installment_limit ?? 12)));
                $preSelected = max(
                    1,
                    min($limit, (int) ($settings->card_pre_selected_installment ?? 1))
                );
                $interestFree = max(
                    1,
                    min($limit, (int) ($gateway->interest_free_installments ?? 1))
                );

                $entry['installment_config'] = [
                    'type' => $installmentType,
                    'default_rate' => $defaultRate,
                    'rates' => array_values($customRates),
                    'pre_selected' => $preSelected,
                    'limit' => $limit,
                    'interest_free' => $interestFree,
                ];
            }

            $result[$key] = $entry;
        }

        return $result;
    }

    /**
     * Constrói a lista de order bumps aplicáveis a um carrinho.
     * Cada bump traz os dados do produto oferecido, o preço original e o
     * preço com desconto, além de cores/labels para personalização visual.
     */
    private function buildOrderBumps($store, array $productIds, $settings)
    {
        $bumps = $store->orderBumps()
            ->with(['product:id,name,parent_title,attributes,price,compare_at_price,image_url'])
            ->where('is_active', true)
            ->get();

        $uniqueProductIds = array_values(array_unique($productIds));

        $result = [];
        foreach ($bumps as $bump) {
            // Validações básicas de elegibilidade.
            if (! $bump->product) {
                continue;
            }

            // Escopo "specific": só exibe se algum produto do carrinho for o alvo.
            if ($bump->scope === 'specific') {
                if (! $bump->target_product_id || ! in_array($bump->target_product_id, $uniqueProductIds, true)) {
                    continue;
                }
            }

            // Não oferece o bump se o próprio produto oferecido já estiver no carrinho.
            if (in_array($bump->product->id, $uniqueProductIds, true)) {
                continue;
            }

            $originalPrice = (float) $bump->product->price;
            $discountedPrice = $bump->calculateDiscountedPrice();

            $result[] = [
                'id' => $bump->id,
                'name' => $bump->name,
                'product_id' => $bump->product->id,
                'product' => [
                    'id' => $bump->product->id,
                    'name' => $bump->product->name,
                    'parent_title' => $bump->product->parent_title,
                    'attributes' => $bump->product->attributes,
                    'image_url' => $bump->product->image_url,
                    'original_price' => round($originalPrice, 2),
                    'bump_price' => $discountedPrice,
                ],
                'discount_type' => $bump->discount_type,
                'discount_value' => (float) $bump->discount_value,
                'scope' => $bump->scope,
                'show_credit_card' => (bool) $bump->show_credit_card,
                'show_pix' => (bool) $bump->show_pix,
                'show_boleto' => (bool) $bump->show_boleto,
                'offer_title' => $bump->offer_title,
                'offer_message' => $bump->offer_message,
                // The design is set once in the checkout settings for all Order Bumps.
                'bg_color' => $settings->order_bump_bg_color ?? '#FEFCE8',
                'border_color' => $settings->order_bump_border_color ?? '#E2E8F0',
                'button_color' => $settings->order_bump_button_color ?? '#13BF8C',
                'button_text_color' => $settings->order_bump_button_text_color ?? '#FFFFFF',
                'button_label' => $bump->button_label,
                'scarcity_timer_enabled' => (bool) ($settings->order_bump_scarcity_timer_enabled ?? false),
                'scarcity_timer_minutes' => (int) ($settings->order_bump_scarcity_timer_minutes ?: 10),
            ];
        }

        return $result;
    }

    /**
     * Builds a representative Order Bump for the checkout editor, including
     * stores that do not have an offer configured yet.
     */
    private function buildPreviewOrderBumps($settings): array
    {
        return [[
            'id' => 0,
            'name' => 'Order Bump de exemplo',
            'product_id' => 0,
            'product' => [
                'id' => 0,
                'name' => 'Produto adicional de exemplo',
                'parent_title' => null,
                'attributes' => null,
                'image_url' => null,
                'original_price' => 49.90,
                'bump_price' => 29.90,
            ],
            'discount_type' => 'percent',
            'discount_value' => 40,
            'scope' => 'any',
            'show_credit_card' => true,
            'show_pix' => true,
            'show_boleto' => true,
            'offer_title' => 'Você também pode gostar',
            'offer_message' => 'Adicione este item à sua compra com um desconto especial!',
            'bg_color' => $settings->order_bump_bg_color ?? '#FEFCE8',
            'border_color' => $settings->order_bump_border_color ?? '#E2E8F0',
            'button_color' => $settings->order_bump_button_color ?? '#13BF8C',
            'button_text_color' => $settings->order_bump_button_text_color ?? '#FFFFFF',
            'button_label' => 'Quero essa oferta',
            'scarcity_timer_enabled' => (bool) ($settings->order_bump_scarcity_timer_enabled ?? false),
            'scarcity_timer_minutes' => (int) ($settings->order_bump_scarcity_timer_minutes ?: 10),
        ]];
    }

    /**
     * Brindes ativos no período e aplicáveis ao escopo do carrinho.
     * A regra de quantidade/valor é exposta para atualizar o progresso quando
     * o cliente altera quantidades. A elegibilidade é revalidada no pagamento.
     */
    private function buildGifts($store, array $productIds, bool $ignoreScope = false): array
    {
        $now = Carbon::now();
        $cartProductIds = array_values(array_unique(array_map('intval', $productIds)));

        $gifts = $store->gifts()
            ->with([
                'products' => fn ($query) => $query
                    ->where('is_active', true)
                    ->select('products.id', 'name', 'parent_title', 'attributes', 'image_url', 'stock_quantity'),
                'targetProducts:id',
            ])
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '>=', $now)
            ->get();

        $result = [];
        foreach ($gifts as $gift) {
            if ($gift->products->isEmpty()) {
                continue;
            }

            $targetIds = $gift->targetProducts->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! $ignoreScope && $gift->scope === 'specific' && empty(array_intersect($targetIds, $cartProductIds))) {
                continue;
            }

            $result[] = [
                'id' => $gift->id,
                'name' => $gift->name,
                'rule_type' => $gift->rule_type,
                'min_quantity' => $gift->min_quantity,
                'min_value' => $gift->min_value !== null ? (float) $gift->min_value : null,
                'scope' => $gift->scope,
                'products' => $gift->products->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'parent_title' => $product->parent_title,
                    'attributes' => $product->attributes,
                    'price' => 0,
                    'image_url' => $product->image_url,
                    'stock_quantity' => $product->stock_quantity,
                ])->values(),
            ];
        }

        return $result;
    }

    private function buildPreviewGift(): array
    {
        return [[
            'id' => -1,
            'name' => 'Brinde de exemplo',
            'rule_type' => 'min_value',
            'min_quantity' => null,
            'min_value' => 299,
            'scope' => 'any',
            'products' => [
                [
                    'id' => -101,
                    'name' => 'Tênis Confortável Preto 33',
                    'parent_title' => 'Tênis Confortável',
                    'attributes' => [
                        ['name' => 'Cor', 'value' => 'Preto'],
                        ['name' => 'Tamanho', 'value' => '33'],
                    ],
                    'price' => 0,
                    'image_url' => null,
                    'stock_quantity' => 10,
                ],
                [
                    'id' => -102,
                    'name' => 'Tênis Confortável Preto 34',
                    'parent_title' => 'Tênis Confortável',
                    'attributes' => [
                        ['name' => 'Cor', 'value' => 'Preto'],
                        ['name' => 'Tamanho', 'value' => '34'],
                    ],
                    'price' => 0,
                    'image_url' => null,
                    'stock_quantity' => 10,
                ],
                [
                    'id' => -103,
                    'name' => 'Tênis Confortável Rosa 33',
                    'parent_title' => 'Tênis Confortável',
                    'attributes' => [
                        ['name' => 'Cor', 'value' => 'Rosa'],
                        ['name' => 'Tamanho', 'value' => '33'],
                    ],
                    'price' => 0,
                    'image_url' => null,
                    'stock_quantity' => 10,
                ],
            ],
        ]];
    }

    /**
     * Constrói o bloco de configuração do Google Ads exposto ao checkout.
     * Retorna apenas os campos necessários ao client-side (sem segredos).
     */
    private function buildGoogleAdsBlock($store): array
    {
        $setting = $store->googleAdsSetting;

        if (!$setting || !$setting->isActive()) {
            return ['enabled' => false];
        }

        $selectedIds = is_array($setting->selected_product_ids)
            ? array_values(array_unique(array_map('intval', $setting->selected_product_ids)))
            : [];

        return [
            'enabled' => true,
            'pixel_id' => $setting->pixel_id,
            'pixel_name' => $setting->pixel_name,
            'conversion_label' => $setting->conversion_label,
            'only_paid_sales' => (bool) $setting->only_paid_sales,
            'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => $selectedIds,
        ];
    }

    /**
     * Expõe somente a configuração pública do Meta Pixel. Access token e
     * test event code permanecem exclusivamente no backend.
     */
    private function buildMetaPixelBlock($store): array
    {
        $setting = $store->metaPixelSetting;
        if (!$setting || !$setting->isActive()) {
            return ['enabled' => false];
        }

        $selectedIds = is_array($setting->selected_product_ids)
            ? array_values(array_unique(array_map('intval', $setting->selected_product_ids)))
            : [];

        return [
            'enabled' => true,
            'pixel_id' => $setting->pixel_id,
            'browser_enabled' => (bool) $setting->isBrowserActive(),
            'capi_enabled' => (bool) $setting->isCapiActive(),
            'only_paid_sales' => (bool) $setting->only_paid_sales,
            'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => $selectedIds,
            'require_consent' => (bool) $setting->require_consent,
        ];
    }

    /**
     * Expõe somente a configuração pública do TikTok Pixel. Access token e
     * test event code permanecem exclusivamente no backend.
     */
    private function buildTikTokPixelBlock($store): array
    {
        $setting = $store->tiktokPixelSetting;
        if (!$setting || !$setting->isActive()) {
            return ['enabled' => false];
        }

        $selectedIds = is_array($setting->selected_product_ids)
            ? array_values(array_unique(array_map('intval', $setting->selected_product_ids)))
            : [];

        return [
            'enabled' => true,
            'pixel_code' => $setting->pixel_code,
            'browser_enabled' => (bool) $setting->isBrowserActive(),
            'events_api_enabled' => (bool) $setting->isEventsApiActive(),
            'only_paid_sales' => (bool) $setting->only_paid_sales,
            'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => $selectedIds,
            'require_consent' => (bool) $setting->require_consent,
        ];
    }

    /** Exposes only public Kwai Pixel data; server credentials remain private. */
    private function buildKwaiPixelBlock($store): array
    {
        $setting = $store->kwaiPixelSetting;
        if (!$setting || !$setting->isActive()) return ['enabled' => false];
        $selectedIds = is_array($setting->selected_product_ids)
            ? array_values(array_unique(array_map('intval', $setting->selected_product_ids))) : [];
        return [
            'enabled' => true,
            'pixel_code' => $setting->pixel_code,
            'browser_enabled' => (bool) $setting->isBrowserActive(),
            'events_api_enabled' => (bool) $setting->isEventsApiActive(),
            'only_paid_sales' => (bool) $setting->only_paid_sales,
            'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => $selectedIds,
            'require_consent' => (bool) $setting->require_consent,
        ];
    }

    /** Exposes the Taboola Account ID and event names; the postback URL stays server-side. */
    private function buildTaboolaPixelBlock($store): array
    {
        $setting = $store->taboolaPixelSetting;
        if (!$setting || !$setting->isActive()) return ['enabled' => false];
        $selectedIds = is_array($setting->selected_product_ids)
            ? array_values(array_unique(array_map('intval', $setting->selected_product_ids))) : [];
        return [
            'enabled' => true,
            'account_id' => $setting->account_id,
            'browser_enabled' => (bool) $setting->isBrowserActive(),
            's2s_enabled' => (bool) $setting->isS2sActive(),
            'only_paid_sales' => (bool) $setting->only_paid_sales,
            'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => $selectedIds,
            'require_consent' => (bool) $setting->require_consent,
            'page_view_event_name' => $setting->page_view_event_name ?: 'page_view',
            'view_content_event_name' => $setting->view_content_event_name ?: 'PRODUCT_VIEW',
            'add_to_cart_event_name' => $setting->add_to_cart_event_name ?: 'ADD_TO_CART',
            'initiate_checkout_event_name' => $setting->initiate_checkout_event_name ?: 'CHECKOUT',
            'add_payment_info_event_name' => $setting->add_payment_info_event_name ?: 'ADD_PAYMENT_INFO',
            'purchase_event_name' => $setting->purchase_event_name ?: 'PURCHASE',
        ];
    }

    public function show(Request $request)
    {
        $identifier = $request->query('store_id') ?? $request->query('domain');
        $productIdsParam = $request->query('product_ids');

        if (!$identifier || !$productIdsParam) {
            return response()->json(['error' => 'Missing store_id/domain or product_ids parameters'], 400);
        }

        $store = Store::resolveByIdentifier($identifier);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $ids = collect(explode(',', $productIdsParam))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['error' => 'No valid product_ids provided'], 400);
        }

        $uniqueIds = $ids->unique()->values()->all();

        $products = $store->products()
            ->whereIn('id', $uniqueIds)
            ->where('is_active', true)
            ->get(['id', 'name', 'parent_title', 'attributes', 'description', 'price', 'compare_at_price', 'image_url', 'sku', 'product_type', 'vendor', 'tags', 'shopify_product_id', 'shopify_variant_id'])
            ->keyBy('id');

        if ($products->isEmpty()) {
            return response()->json(['error' => 'No active products found'], 404);
        }

        $items = [];
        $total = 0.0;

        foreach ($ids as $id) {
            $product = $products->get($id);
            if (!$product) {
                continue;
            }
            $items[] = $product;
            $total += (float) $product->price;
        }

        if (empty($items)) {
            return response()->json(['error' => 'No active products found'], 404);
        }

        $shippingMethods = $store->shippingMethods()
            ->where('is_active', true)
            ->orderBy('price', 'asc')
            ->get()
            ->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name,
                    'price' => $method->price ? round((float) $method->price, 2) : null,
                    'min_value_free_shipping' => $method->min_value_free_shipping
                        ? round((float) $method->min_value_free_shipping, 2)
                        : null,
                    'min_delivery_days' => (int) $method->min_delivery_days,
                    'max_delivery_days' => (int) $method->max_delivery_days,
                    'icon' => $method->icon,
                ];
            });

        $effectiveSettings = $store->checkoutSettings ?? $this->defaultSettings();

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'settings' => $effectiveSettings,
                'google_ads' => $this->buildGoogleAdsBlock($store),
                'meta_pixel' => $this->buildMetaPixelBlock($store),
                'tiktok_pixel' => $this->buildTikTokPixelBlock($store),
                'kwai_pixel' => $this->buildKwaiPixelBlock($store),
                'taboola_pixel' => $this->buildTaboolaPixelBlock($store),
                'gateways' => $store->gateways->map(function ($gateway) {
                    return [
                        'provider' => $gateway->provider,
                        'public_key' => $gateway->provider === 'unipay' ? $gateway->api_key : null,
                    ];
                }),
                'payment_methods' => $this->buildPaymentMethods($effectiveSettings, $store),
            ],
            'products' => $items,
            'total' => round($total, 2),
            'shipping_methods' => $shippingMethods,
            'order_bumps' => $this->buildOrderBumps($store, $uniqueIds, $effectiveSettings),
            'gifts' => $this->buildGifts($store, $uniqueIds),
            'social_proofs' => $store->socialProofs()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($p) => [
                    'name' => $p->name,
                    'testimonial' => $p->testimonial,
                    'photo_url' => $p->photo_url,
                    'stars' => $p->stars,
                ]),
        ]);
    }

    public function preview(Request $request)
    {
        $identifier = $request->query('store_id') ?? $request->query('domain');

        if (!$identifier) {
            return response()->json(['error' => 'Missing store_id or domain parameter'], 400);
        }

        $store = Store::resolveByIdentifier($identifier);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $mockProducts = [
            [
                'id' => 1,
                'name' => 'Produto Exemplo',
                'description' => 'Produto de demonstração para o editor do checkout.',
                'price' => 99.90,
                'image_url' => null,
            ],
            [
                'id' => 2,
                'name' => 'Produto Bônus',
                'description' => null,
                'price' => 49.90,
                'image_url' => null,
            ],
        ];

        $shippingMethods = $store->shippingMethods()
            ->where('is_active', true)
            ->orderBy('price', 'asc')
            ->get()
            ->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name,
                    'price' => $method->price ? round((float) $method->price, 2) : null,
                    'min_value_free_shipping' => $method->min_value_free_shipping
                        ? round((float) $method->min_value_free_shipping, 2)
                        : null,
                    'min_delivery_days' => (int) $method->min_delivery_days,
                    'max_delivery_days' => (int) $method->max_delivery_days,
                    'icon' => $method->icon,
                ];
            });

        $effectiveSettings = $store->checkoutSettings ?? $this->defaultSettings();

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'settings' => $effectiveSettings,
                'google_ads' => $this->buildGoogleAdsBlock($store),
                'meta_pixel' => $this->buildMetaPixelBlock($store),
                'tiktok_pixel' => $this->buildTikTokPixelBlock($store),
                'kwai_pixel' => $this->buildKwaiPixelBlock($store),
                'taboola_pixel' => $this->buildTaboolaPixelBlock($store),
                'gateways' => $store->gateways->map(function ($gateway) {
                    return [
                        'provider' => $gateway->provider,
                        'public_key' => $gateway->provider === 'unipay' ? $gateway->api_key : null,
                    ];
                }),
                'payment_methods' => $this->buildPaymentMethods($effectiveSettings, $store),
            ],
            'products' => $mockProducts,
            'total' => 99.90,
            'preview' => true,
            'shipping_methods' => $shippingMethods,
            'order_bumps' => $this->buildPreviewOrderBumps($effectiveSettings),
            'gifts' => $this->buildPreviewGift(),
            'social_proofs' => $store->socialProofs()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($p) => [
                    'name' => $p->name,
                    'testimonial' => $p->testimonial,
                    'photo_url' => $p->photo_url,
                    'stars' => $p->stars,
                ]),
        ]);
    }

    /**
     * Valida um cupom de desconto para o carrinho atual.
     */
    public function validateCoupon(Request $request)
    {
        $identifier = $request->query('store_id') ?? $request->query('domain');
        $productIdsParam = $request->query('product_ids');
        $code = $request->query('code');

        if (!$identifier || !$code) {
            return response()->json(['error' => 'Missing store_id/domain or code parameters'], 400);
        }

        $store = Store::resolveByIdentifier($identifier);
        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $coupon = $store->coupons()
            ->where('code', $code)
            ->where('status', 'active')
            ->first();

        if (!$coupon) {
            return response()->json(['error' => 'Cupom inválido ou inativo'], 422);
        }

        $now = Carbon::now();
        if ($now->lt(Carbon::parse($coupon->starts_at))) {
            return response()->json(['error' => 'Este cupom ainda não está ativo'], 422);
        }
        if ($now->gt(Carbon::parse($coupon->expires_at))) {
            return response()->json(['error' => 'Este cupom expirou'], 422);
        }
        if ($coupon->max_uses > 0 && $coupon->used_count >= $coupon->max_uses) {
            return response()->json(['error' => 'Este cupom atingiu o limite de usos'], 422);
        }

        $productIds = collect(explode(',', $productIdsParam ?? ''))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$coupon->applies_to_all_products) {
            $allowedIds = $coupon->products()->pluck('products.id')->toArray();
            $hasAllowed = false;
            foreach ($productIds as $id) {
                if (in_array($id, $allowedIds, true)) {
                    $hasAllowed = true;
                    break;
                }
            }
            if (!$hasAllowed) {
                return response()->json(['error' => 'Este cupom não é válido para os produtos do carrinho'], 422);
            }
        }

        return response()->json([
            'valid' => true,
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'discount_type' => $coupon->discount_type,
                'discount_value' => (float) $coupon->discount_value,
                'free_shipping' => (bool) $coupon->free_shipping,
                'shipping_method_id' => $coupon->shipping_method_id,
                'first_purchase_only' => (bool) $coupon->first_purchase_only,
                'accumulate_with_promos' => (bool) $coupon->accumulate_with_promos,
                'min_purchase_value' => $coupon->min_purchase_value ? (float) $coupon->min_purchase_value : null,
                'min_items_required' => (bool) $coupon->min_items_required,
                'min_items_quantity' => $coupon->min_items_quantity ? (int) $coupon->min_items_quantity : null,
            ],
        ]);
    }
}
