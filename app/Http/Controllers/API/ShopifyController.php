<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\ShopifyThemeInjector;
use App\Services\CheckoutUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Jobs\SyncShopifyProducts;
use App\Jobs\InjectShopifyCheckout;

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
            'pending_domain' => $store->shopify_pending_domain,
            'credentials_configured' => !empty($store->shopify_client_id) && !empty($store->shopify_client_secret),
            'checkout_injected' => !empty($store->shopify_injected_theme_id),
            'injected_theme_id' => $store->shopify_injected_theme_id,
            'injected_at' => $store->shopify_injected_at,
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
            'shopify_domain_input' => 'nullable|string|max:255',
        ]);

        $store->update([
            'shopify_client_id' => $validated['shopify_client_id'],
            'shopify_client_secret' => $validated['shopify_client_secret'],
            'shopify_pending_domain' => $validated['shopify_domain_input'] ?? $store->shopify_pending_domain,
        ]);

        return response()->json([
            'message' => 'Credenciais Shopify salvas com sucesso.',
            'credentials_configured' => true,
            'pending_domain' => $store->shopify_pending_domain,
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
     * Injeta (ou reinjeta) o snippet de checkout no tema publicado.
     * Síncrono para feedback imediato no painel — usado pelo botão "Integrar/Reintegrar".
     */
    public function injectCheckout(Request $request, ShopifyThemeInjector $injector, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        if (!$store->isShopifyConnected()) {
            return response()->json(['message' => 'Shopify não está conectado para esta loja.'], 400);
        }

        try {
            $result = $injector->inject($store);
        } catch (\Throwable $e) {
            $status = (int) $e->getCode();
            $httpStatus = in_array($status, [401, 403, 404, 422, 429], true) ? $status : 502;

            Log::warning('Falha na injeção do snippet Shopify', [
                'store_id' => $store->id,
                'shopify_domain' => $store->shopify_domain,
                'status' => $status,
                'message' => $e->getMessage(),
            ]);

            $message = $this->mapShopifyError($status, $e->getMessage());

            return response()->json([
                'message' => $message,
                'error' => $e->getMessage(),
                'status' => $status,
            ], $httpStatus);
        }

        return response()->json([
            'message' => 'Código de checkout injetado no tema com sucesso.',
            'injected' => true,
            'theme_id' => $result['theme_id'],
            'theme_name' => $result['theme_name'],
        ]);
    }

    /**
     * Remove o snippet de checkout do tema publicado.
     */
    public function removeCheckout(Request $request, ShopifyThemeInjector $injector, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        if (!$store->isShopifyConnected()) {
            return response()->json(['message' => 'Shopify não está conectado para esta loja.'], 400);
        }

        try {
            $injector->remove($store);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'message' => 'Código de checkout removido do tema.',
            'injected' => false,
        ]);
    }

    /**
     * Desconecta a loja Shopify, remove o snippet do tema (quando possível) e
     * libera o domínio Shopify para ser usado por outra loja.
     */
    public function disconnect(Request $request, ShopifyThemeInjector $injector, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        if (!$store->isShopifyConnected()) {
            return response()->json(['message' => 'Shopify não está conectado para esta loja.'], 400);
        }

        // Tenta remover o snippet do tema antes de limpar as credenciais.
        try {
            $injector->remove($store);
        } catch (\Throwable $e) {
            Log::warning('Não foi possível remover o snippet ao desconectar Shopify', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
            // Continua mesmo se falhar — o importante é liberar o domínio.
        }

        $store->update([
            'shopify_domain' => null,
            'shopify_access_token' => null,
            'shopify_client_id' => null,
            'shopify_client_secret' => null,
            'shopify_injected_theme_id' => null,
            'shopify_injected_at' => null,
        ]);

        return response()->json([
            'message' => 'Loja Shopify desconectada com sucesso.',
            'connected' => false,
        ]);
    }

    /**
     * Inicia o OAuth usando as credenciais do app Shopify pertencentes à loja.
     *
     * Query: shop (domínio myshopify), store_id
     */
    public function install(Request $request)
    {
        $request->validate([
            'shop' => 'nullable|string',
            'store_id' => 'required|integer|exists:stores,id',
        ]);

        $storeId = $request->store_id;

        $store = Store::findOrFail($storeId);
        $clientId = $store->shopify_client_id;

        if (!$clientId) {
            return response()->json([
                'error' => 'Configure as credenciais do seu app Shopify antes de conectar.',
            ], 422);
        }

        // Domínio da loja: prioriza query param `shop`; senão, usa o pending_domain salvo.
        $shop = $request->shop ?: $store->shopify_pending_domain;

        if (!$shop) {
            return response()->json([
                'error' => 'Informe o domínio da loja Shopify (ex: sua-loja.myshopify.com).',
            ], 422);
        }

        // Normaliza "loja" → "loja.myshopify.com"
        if (!str_contains($shop, '.')) {
            $shop = $shop . '.myshopify.com';
        }

        // Persiste o pending_domain para o callback poder resolver a loja se necessário.
        if ($store->shopify_pending_domain !== $shop) {
            $store->update(['shopify_pending_domain' => $shop]);
        }

        // redirect_uri é fixo e único por plataforma (deve estar na whitelist do app do lojista).
        $redirectUri = urlencode(config('services.shopify.redirect_uri'));
        $scopes = config('services.shopify.scopes', 'read_products,read_orders,write_themes');

        // state carrega o store_id para o callback resolver a loja e suas credenciais.
        $state = base64_encode(json_encode(['store_id' => $storeId]));

        $installUrl = "https://{$shop}/admin/oauth/authorize?client_id={$clientId}&scope={$scopes}&redirect_uri={$redirectUri}&state={$state}";

        return redirect()->away($installUrl);
    }

    /**
     * Callback OAuth: troca o code por access_token usando as credenciais da loja.
     */
    public function callback(Request $request)
    {
        $frontendUrl = rtrim(config('services.shopify.frontend_url', 'https://app.bersenker.shop'), '/');
        $integrationsUrl = $frontendUrl . '/dashboard/integrations';

        $code = $request->query('code');
        $shop = $request->query('shop');
        $state = $request->query('state');

        $stateData = $state ? json_decode(base64_decode($state), true) : null;
        $storeId = $stateData['store_id'] ?? null;

        if (!$storeId || !$code || !$shop) {
            return redirect()->away($integrationsUrl . '?shopify=error&message=' . urlencode('Parâmetros de callback inválidos.'));
        }

        $store = Store::find($storeId);
        if (!$store || !$store->shopify_client_id || !$store->shopify_client_secret) {
            return redirect()->away($integrationsUrl . '?shopify=error&message=' . urlencode('Loja ou credenciais Shopify não encontradas.'));
        }

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $store->shopify_client_id,
            'client_secret' => $store->shopify_client_secret,
            'code' => $code,
        ]);

        if ($response->successful()) {
            $accessToken = $response->json()['access_token'] ?? null;

            if (!$accessToken) {
                return redirect()->away($integrationsUrl . '?shopify=error&message=' . urlencode('Token não retornado pela Shopify.'));
            }

            // Normaliza o domínio: aceita "loja.myshopify.com".
            $shopDomain = $shop;
            if (!str_contains($shop, '.')) {
                $shopDomain = $shop . '.myshopify.com';
            }

            // Garante que outra loja não esteja usando o mesmo domínio Shopify.
            $existing = Store::where('shopify_domain', $shopDomain)
                ->where('id', '!=', $store->id)
                ->first();

            if ($existing) {
                return redirect()->away($integrationsUrl . '?shopify=error&message=' . urlencode('Essa loja Shopify já está cadastrada em outra conta. Remova-a antes de integrá-la.'));
            }

            $store->update([
                'shopify_domain' => $shopDomain,
                'shopify_pending_domain' => null,
                'shopify_access_token' => $accessToken,
            ]);

            SyncShopifyProducts::dispatch($store);
            // Injeta o snippet no tema publicado em paralelo (best-effort).
            InjectShopifyCheckout::dispatch($store);

            return redirect()->away($integrationsUrl . '?shopify=connected');
        }

        $errorMsg = is_array($response->json()) ? (string) json_encode($response->json()) : 'Failed to obtain access token';
        return redirect()->away($integrationsUrl . '?shopify=error&message=' . urlencode($errorMsg));
    }

    /**
     * Endpoint público chamado pelo snippet injetado no tema.
     * Recebe os itens do carrinho da Shopify e devolve a URL do nosso checkout.
     *
     * Itens cuja variante não esteja mapeada/atíva são ignorados (skipped).
     */
    public function checkoutRedirect(Request $request, CheckoutUrlGenerator $urlGenerator)
    {
        $validated = $request->validate([
            'shop' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required',
            'items.*.quantity' => 'nullable|integer|min:1',
        ]);

        $shop = $validated['shop'];

        // Aceita "loja.myshopify.com" ou apenas "loja".
        $shopDomain = $shop;
        if (!str_contains($shop, '.')) {
            $shopDomain = $shop . '.myshopify.com';
        }

        $store = Store::where('shopify_domain', $shopDomain)
            ->where('status', true)
            ->first();

        if (!$store) {
            return response()->json(['message' => 'Loja não encontrada para este domínio Shopify.'], 404);
        }

        // Mapeia variant_id → product.id interno (somente ativos).
        $variantIds = array_map(fn ($item) => (string) $item['variant_id'], $validated['items']);

        $products = $store->products()
            ->whereIn('shopify_variant_id', $variantIds)
            ->where('is_active', true)
            ->get(['id', 'shopify_variant_id'])
            ->keyBy('shopify_variant_id');

        if ($products->isEmpty()) {
            return response()->json([
                'message' => 'Nenhum dos produtos do carrinho está disponível no checkout.',
            ], 404);
        }

        // Monta a lista de IDs internos preservando quantidade (repetições).
        $productIds = [];
        $skipped = [];
        foreach ($validated['items'] as $item) {
            $variantId = (string) $item['variant_id'];
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $product = $products->get($variantId);

            if (!$product) {
                $skipped[] = ['variant_id' => $variantId, 'quantity' => $qty];
                continue;
            }

            for ($i = 0; $i < $qty; $i++) {
                $productIds[] = (int) $product->id;
            }
        }

        if (empty($productIds)) {
            return response()->json([
                'message' => 'Nenhum produto do carrinho pôde ser redirecionado.',
            ], 404);
        }

        $redirectUrl = $urlGenerator->generateForCart($store, $productIds);

        return response()->json([
            'redirect_url' => $redirectUrl,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Traduz códigos de erro da Shopify em mensagens amigáveis para o painel.
     */
    protected function mapShopifyError(int $status, string $original): string
    {
        return match (true) {
            $status === 401 || $status === 403 =>
                'Permissão negada pela Shopify. Desconecte e reconecte a loja para conceder o escopo de edição de tema (write_themes).',
            $status === 422 =>
                'A Shopify rejeitou a alteração do tema. Verifique se o tema publicado permite edição e se o escopo write_themes foi concedido.',
            $status === 429 =>
                'Muitas requisições à Shopify. Aguarde alguns segundos e tente novamente.',
            $status === 404 =>
                'Tema ou recurso não encontrado na Shopify.',
            default => $original ?: 'Falha ao comunicar com a Shopify.',
        };
    }
}
