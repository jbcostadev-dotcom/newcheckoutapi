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
            'credentials_configured' => !empty($store->shopify_client_id) && !empty($store->shopify_client_secret),
        ]);
    }

    /**
     * Salvar as credenciais do app Shopify criado pelo próprio lojista.
     */
    public function updateCredentials(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'shopify_client_id' => 'required|string|max:255',
            'shopify_client_secret' => 'required|string|max:255',
        ]);

        $store->update([
            'shopify_client_id' => $validated['shopify_client_id'],
            'shopify_client_secret' => $validated['shopify_client_secret'],
        ]);

        return response()->json([
            'message' => 'Credenciais Shopify salvas com sucesso.',
            'credentials_configured' => true,
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

    /**
     * Inicia o OAuth usando as credenciais do app Shopifypertencentes à loja.
     *
     * Query: shop (domínio myshopify), store_id
     */
    public function install(Request $request)
    {
        $request->validate([
            'shop' => 'required|string',
            'store_id' => 'required|integer|exists:stores,id',
        ]);

        $shop = $request->shop;
        $storeId = $request->store_id;

        $store = Store::findOrFail($storeId);
        $clientId = $store->shopify_client_id;

        if (!$clientId) {
            return response()->json([
                'error' => 'Configure as credenciais do seu app Shopify antes de conectar.',
            ], 422);
        }

        // redirect_uri é fixo e único por plataforma (deve estar na whitelist do app do lojista).
        $redirectUri = urlencode(config('services.shopify.redirect_uri'));
        $scopes = config('services.shopify.scopes', 'read_products,read_orders');

        // state carrega o store_id para o callback resolver a loja e suas credenciais.
        $state = base64_encode(json_encode(['store_id' => $storeId]));

        $installUrl = "https://{$shop}/admin/oauth/authorize?client_id={$clientId}&scope={$scopes}&redirect_uri={$redirectUri}&state={$state}";

        return response()->json(['url' => $installUrl]);
    }

    /**
     * Callback OAuth: troca o code por access_token usando as credenciais da loja.
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $shop = $request->query('shop');
        $state = $request->query('state');

        $stateData = $state ? json_decode(base64_decode($state), true) : null;
        $storeId = $stateData['store_id'] ?? null;

        if (!$storeId || !$code || !$shop) {
            return response()->json(['error' => 'Invalid callback parameters'], 400);
        }

        $store = Store::find($storeId);
        if (!$store || !$store->shopify_client_id || !$store->shopify_client_secret) {
            return response()->json(['error' => 'Loja ou credenciais Shopify não encontradas.'], 404);
        }

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $store->shopify_client_id,
            'client_secret' => $store->shopify_client_secret,
            'code' => $code,
        ]);

        if ($response->successful()) {
            $accessToken = $response->json()['access_token'] ?? null;

            if (!$accessToken) {
                return response()->json(['error' => 'Token não retornado pela Shopify.'], 502);
            }

            $store->update([
                'shopify_domain' => $shop,
                'shopify_access_token' => $accessToken,
            ]);

            SyncShopifyProducts::dispatch($store);

            return response()->json([
                'message' => 'Shopify successfully connected and products are being synced.',
                'store_id' => $store->id,
            ]);
        }

        return response()->json([
            'error' => 'Failed to obtain access token',
            'details' => $response->json(),
        ], 502);
    }
}