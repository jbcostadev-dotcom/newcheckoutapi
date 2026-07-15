<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * Get checkout data for a specific domain and product.
     */
    public function show(Request $request)
    {
        $domain = $request->query('domain');
        $productId = $request->query('product_id');

        if (!$domain || !$productId) {
            return response()->json(['error' => 'Missing domain or product parameters'], 400);
        }

        $store = Store::resolveByDomain($domain);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $product = $store->products()
            ->where('id', $productId)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json([
            'store' => [
                'name' => $store->name,
                'settings' => $store->checkoutSettings ?? (object) [
                    'primary_color' => '#6366f1',
                    'secondary_color' => '#8b5cf6',
                    'dark_mode' => true,
                    'enable_order_bump' => false,
                    'button_text' => 'Finalizar Compra',
                ],
                'gateways' => $store->gateways->map(function ($gateway) {
                    return ['provider' => $gateway->provider];
                }),
            ],
            'product' => $product,
        ]);
    }
}
