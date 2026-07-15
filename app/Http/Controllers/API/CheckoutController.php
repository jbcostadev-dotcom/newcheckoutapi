<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * Retorna os dados do checkout público.
     *
     * Query params:
     *   - domain      : subdomínio / custom_domain da loja
     *   - product_ids : lista de IDs como CSV "1,1,2" (repetições = quantidade)
     *
     * Responde com store, lista de produtos e total (soma considerando qty).
     */
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

        // IDs podem vir repetidos (mesmo produto várias unidades). Preservamos a ordem.
        $ids = collect(explode(',', $productIdsParam))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['error' => 'No valid product_ids provided'], 400);
        }

        // IDs únicos para buscar no banco.
        $uniqueIds = $ids->unique()->values()->all();

        $products = $store->products()
            ->whereIn('id', $uniqueIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return response()->json(['error' => 'No active products found'], 404);
        }

        // Constrói a lista preservando repetições (qty).
        $items = [];
        $total = 0.0;

        foreach ($ids as $id) {
            $product = $products->get($id);
            if (!$product) {
                continue; // ignora IDs inativos/inexistentes
            }
            $items[] = $product;
            $total += (float) $product->price;
        }

        if (empty($items)) {
            return response()->json(['error' => 'No active products found'], 404);
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
            'products' => $items,
            'total' => round($total, 2),
        ]);
    }
}