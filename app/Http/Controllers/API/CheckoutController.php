<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Gateway;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    private function defaultSettings()
    {
        return (object) [
            'primary_color' => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'dark_mode' => true,
            'enable_order_bump' => false,
            'button_text' => 'Finalizar Compra',
            'banner_message' => 'Digite aqui a mensagem',
            'header_store_name_visible' => true,
            'header_secure_badge' => true,
            'announcement_bar_enabled' => true,
            'announcement_bar_bg' => '#333333',
            'announcement_bar_text_color' => '#d4a843',
            'banner_height' => 'md',
            'summary_title' => 'Resumo do pedido',
            'summary_show_discount' => true,
            'summary_coupon_enabled' => true,
            'step_title_font_size' => '1.25rem',
            'scarcity_enabled' => false,
            'scarcity_type' => 'countdown',
            'scarcity_text' => null,
            'scarcity_countdown_minutes' => 15,
            'pix_confirmation_title' => 'Aguardando pagamento...',
            'pix_confirmation_message' => null,
            'pix_confirmation_logo' => null,
            'footer_text' => 'Ambiente seguro · SSL criptografado',
            'footer_show_cnpj' => false,
            'footer_cnpj' => null,
            'font_family' => 'Inter',
            'font_size_base' => '16px',
            'social_proofs_enabled' => true,
            'pix_enabled' => true,
            'card_enabled' => true,
            'boleto_enabled' => false,
            'pix_gateway_id' => null,
            'card_gateway_id' => null,
            'boleto_gateway_id' => null,
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
                'gateway_id' => $settings->pix_gateway_id ?? null,
            ],
            'card' => [
                'enabled' => (bool) ($settings->card_enabled ?? true),
                'gateway_id' => $settings->card_gateway_id ?? null,
            ],
            'boleto' => [
                'enabled' => (bool) ($settings->boleto_enabled ?? false),
                'gateway_id' => $settings->boleto_gateway_id ?? null,
            ],
        ];

        $result = [];
        foreach ($methods as $key => $meta) {
            $gateway = null;
            if ($meta['gateway_id']) {
                $gateway = $store->gateways()->where('id', $meta['gateway_id'])->where('is_active', true)->first();
            }
            // Fallback: if no gateway assigned but method is enabled, try the first active gateway
            if (!$gateway && $meta['enabled']) {
                $gateway = $store->gateways()->where('is_active', true)->first();
            }

            $result[$key] = [
                'enabled' => $meta['enabled'],
                'gateway_provider' => $gateway?->provider,
                'public_key' => ($gateway && $gateway->provider === 'unipay') ? $gateway->api_key : null,
            ];
        }

        return $result;
    }

    public function show(Request $request)
    {
        $domain = $request->query('domain');
        $productIdsParam = $request->query('product_ids');

        if (!$domain || !$productIdsParam) {
            return response()->json(['error' => 'Missing domain or product_ids parameters'], 400);
        }

        $store = Store::resolveByDomain($domain);

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
            ->get(['id', 'name', 'parent_title', 'attributes', 'description', 'price', 'compare_at_price', 'image_url', 'shopify_product_id', 'shopify_variant_id'])
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
                'name' => $store->name,
                'settings' => $effectiveSettings,
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
        $domain = $request->query('domain');

        if (!$domain) {
            return response()->json(['error' => 'Missing domain parameter'], 400);
        }

        $store = Store::resolveByDomain($domain);

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
                'name' => $store->name,
                'settings' => $effectiveSettings,
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
}
