<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Jobs\SyncShopifyProducts;

class ShopifyController extends Controller
{
    /**
     * Status da integração Shopify de uma loja.
     */
    public function status(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        return response()->json([
            'connected' => $store->isShopifyConnected(),
            'shopify_domain' => $store->shopify_domain,
        ]);
    }

    /**
     * Disparar sincronização de produtos do Shopify.
     */
    public function sync(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        if (!$store->isShopifyConnected()) {
            return response()->json(['error' => 'Shopify não está conectado para esta loja.'], 400);
        }

        SyncShopifyProducts::dispatch($store);

        return response()->json([
            'message' => 'Sincronização de produtos iniciada com sucesso.',
        ]);
    }
    public function install(Request $request)
    {
        $request->validate([
            'shop' => 'required|string',
            'store_id' => 'required|exists:stores,id'
        ]);

        $shop = $request->shop;
        $storeId = $request->store_id;
        $clientId = config('services.shopify.client_id');
        $redirectUri = urlencode(config('services.shopify.redirect_uri'));
        $scopes = config('services.shopify.scopes', 'read_products,read_orders');

        if (!$clientId) {
            return response()->json(['error' => 'Shopify Client ID não configurado.'], 500);
        }

        // Armazena o store_id na sessão (ou passa via state para recuperar no callback)
        $state = base64_encode(json_encode(['store_id' => $storeId]));

        $installUrl = "https://{$shop}/admin/oauth/authorize?client_id={$clientId}&scope={$scopes}&redirect_uri={$redirectUri}&state={$state}";

        return response()->json(['url' => $installUrl]);
    }

    public function callback(Request $request)
    {
        $code = $request->code;
        $shop = $request->shop;
        $state = json_decode(base64_decode($request->state), true);
        $storeId = $state['store_id'] ?? null;

        if (!$storeId || !$code || !$shop) {
            return response()->json(['error' => 'Invalid callback parameters'], 400);
        }

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
        ]);

        if ($response->successful()) {
            $accessToken = $response->json()['access_token'];

            $store = Store::find($storeId);
            $store->update([
                'shopify_domain' => $shop,
                'shopify_access_token' => $accessToken,
            ]);

            // Dispatch job to sync products
            SyncShopifyProducts::dispatch($store);

            return response()->json(['message' => 'Shopify successfully connected and products are syncing.']);
        }

        return response()->json(['error' => 'Failed to obtain access token'], 500);
    }
}
