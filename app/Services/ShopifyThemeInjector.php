<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Injeta (e remove) o snippet de redirecionamento de checkout no tema
 * publicado de uma loja Shopify, via Admin API REST.
 *
 * Mecanismo:
 *  1. Cria/atualiza o asset `snippets/checkout-j.liquid` com o JS de
 *     interceptação. O conteúdo é genérico (igual para todas as lojas).
 *  2. Edita `layout/theme.liquid` inserindo um bloco de render idempotente
 *     delimitado por marcadores de comentário, antes de `</head>`.
 *
 * O JS lê o carrinho via /cart.js, chama nosso endpoint de resolução e
 * redireciona para o checkout externo. Também injeta um botão "Comprar
 * agora" nas páginas de produto, pois o "Buy it now" nativo é um iframe
 * cross-origin não interceptável.
 */
class ShopifyThemeInjector
{
    protected const SNIPPET_KEY = 'snippets/checkout-j.liquid';
    protected const LAYOUT_KEY = 'layout/theme.liquid';
    protected const START_MARKER = '{% comment %} checkout-j-start {% endcomment %}';
    protected const END_MARKER = '{% comment %} checkout-j-end {% endcomment %}';

    protected string $apiVersion;

    public function __construct()
    {
        // Versão estável da Admin API. Mantida em config p/ atualizar de um lugar.
        $this->apiVersion = (string) config('services.shopify.api_version', '2025-07');
    }

    /**
     * Injeta o snippet no tema publicado da loja.
     *
     * @return array{theme_id: int, theme_name: string}
     * @throws \RuntimeException em caso de falha de API ou ausência de escopo.
     */
    public function inject(Store $store): array
    {
        $theme = $this->getPublishedTheme($store);

        $this->upsertSnippet($store);

        $this->upsertLayoutRender($store, $theme);

        $store->update([
            'shopify_injected_theme_id' => (int) $theme['id'],
            'shopify_injected_at' => now(),
        ]);

        Log::info('Shopify checkout snippet injetado', [
            'store_id' => $store->id,
            'shopify_domain' => $store->shopify_domain,
            'theme_id' => $theme['id'],
            'theme_name' => $theme['name'] ?? null,
        ]);

        return [
            'theme_id' => (int) $theme['id'],
            'theme_name' => (string) ($theme['name'] ?? ''),
        ];
    }

    /**
     * Remove o snippet e o bloco de render do tema publicado.
     */
    public function remove(Store $store): bool
    {
        if (!$store->isShopifyConnected()) {
            return false;
        }

        $theme = $this->getPublishedThemeOrNull($store);

        if ($theme) {
            // Remove o bloco de render do theme.liquid (best-effort).
            try {
                $this->removeLayoutRender($store);
            } catch (\Throwable $e) {
                Log::warning('Falha ao remover bloco de render do theme.liquid', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Deleta o snippet (best-effort — 404 é ok).
            try {
                $this->deleteAsset($store, self::SNIPPET_KEY);
            } catch (\Throwable $e) {
                Log::warning('Falha ao deletar snippet do checkout', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $store->update([
            'shopify_injected_theme_id' => null,
            'shopify_injected_at' => null,
        ]);

        return true;
    }

    /**
     * Verifica se o tema publicado ainda contém o bloco de render.
     * Útil para o admin detectar se o lojista trocou/edição o tema.
     */
    public function isInjected(Store $store): bool
    {
        if (!$store->isShopifyConnected()) {
            return false;
        }

        try {
            $asset = $this->getAsset($store, self::LAYOUT_KEY);
        } catch (\Throwable $e) {
            return false;
        }

        $content = $asset['asset']['value'] ?? '';

        return str_contains($content, self::START_MARKER);
    }

    /**
     * Resolves o tema publicado (role=main). Lança se não encontrar.
     */
    public function getPublishedTheme(Store $store): array
    {
        $theme = $this->getPublishedThemeOrNull($store);

        if (!$theme) {
            throw new \RuntimeException('Nenhum tema publicado encontrado na loja Shopify.');
        }

        return $theme;
    }

    protected function getPublishedThemeOrNull(Store $store): ?array
    {
        if (!$store->isShopifyConnected()) {
            return null;
        }

        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/themes.json?role=main";

        $response = $this->request($store, 'GET', $endpoint);

        $themes = $response->json()['themes'] ?? [];

        return $themes[0] ?? null;
    }

    /**
     * Cria ou atualiza o snippet do checkout.
     */
    protected function upsertSnippet(Store $store): void
    {
        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/themes/{$this->getPublishedTheme($store)['id']}/assets.json";

        $apiBase = rtrim((string) config('services.shopify.public_api_base', config('app.url')), '/');

        $this->request($store, 'PUT', $endpoint, [
            'asset' => [
                'key' => self::SNIPPET_KEY,
                'value' => str_replace('__CHECKOUT_J_API_BASE__', $apiBase, $this->snippetContent()),
            ],
        ]);
    }

    /**
     * Edita layout/theme.liquid inserindo/substituindo o bloco de render
     * delimitado pelos marcadores idempotentes.
     */
    protected function upsertLayoutRender(Store $store, array $theme): void
    {
        $asset = $this->getAsset($store, self::LAYOUT_KEY);
        $layout = $asset['asset']['value'] ?? '';

        $renderBlock = $this->buildRenderBlock();

        // Substitui o bloco existente (idempotente).
        if (preg_match(
            '/' . preg_quote(self::START_MARKER, '/') . '.*?' . preg_quote(self::END_MARKER, '/') . '/s',
            $layout,
            $m
        )) {
            $layout = str_replace($m[0], $renderBlock, $layout);
        } else {
            // Insere antes de </head> (preferido) ou </body> como fallback.
            if (str_contains($layout, '</head>')) {
                $layout = preg_replace(
                    '/<\/head>/i',
                    $renderBlock . "\n</head>",
                    $layout,
                    1
                );
            } elseif (str_contains($layout, '</body>')) {
                $layout = preg_replace(
                    '/<\/body>/i',
                    $renderBlock . "\n</body>",
                    $layout,
                    1
                );
            } else {
                // Sem âncoras conhecidas: anexa ao final.
                $layout .= "\n" . $renderBlock . "\n";
            }
        }

        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/themes/{$theme['id']}/assets.json";

        $this->request($store, 'PUT', $endpoint, [
            'asset' => [
                'key' => self::LAYOUT_KEY,
                'value' => $layout,
            ],
        ]);
    }

    /**
     * Remove o bloco de render do theme.liquid.
     */
    protected function removeLayoutRender(Store $store): void
    {
        $theme = $this->getPublishedTheme($store);
        $asset = $this->getAsset($store, self::LAYOUT_KEY);
        $layout = $asset['asset']['value'] ?? '';

        // Remove o bloco e eventuais quebras de linha adjacentes.
        $pattern = '/\s*?' . preg_quote(self::START_MARKER, '/') . '.*?' . preg_quote(self::END_MARKER, '/') . '\s*?/s';

        if (!preg_match($pattern, $layout)) {
            return; // nada a remover
        }

        $layout = preg_replace($pattern, "\n", $layout);

        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/themes/{$theme['id']}/assets.json";

        $this->request($store, 'PUT', $endpoint, [
            'asset' => [
                'key' => self::LAYOUT_KEY,
                'value' => $layout,
            ],
        ]);
    }

    protected function buildRenderBlock(): string
    {
        return self::START_MARKER . "\n  {% render 'checkout-j' %}\n" . self::END_MARKER;
    }

    protected function getAsset(Store $store, string $key): array
    {
        $theme = $this->getPublishedTheme($store);
        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/themes/{$theme['id']}/assets.json?asset[key]={$key}";

        $response = $this->request($store, 'GET', $endpoint);

        $body = $response->json();

        if (!isset($body['asset'])) {
            throw new \RuntimeException("Asset {$key} não encontrado no tema.");
        }

        return $body;
    }

    protected function deleteAsset(Store $store, string $key): void
    {
        $theme = $this->getPublishedTheme($store);
        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/themes/{$theme['id']}/assets.json?asset[key]=" . urlencode($key);

        $this->request($store, 'DELETE', $endpoint);
    }

    /**
     * Wrapper de request com headers de auth + tradução de erros.
     *
     * @throws \RuntimeException para 401/403/404/5xx com mensagem útil.
     */
    protected function request(Store $store, string $method, string $endpoint, ?array $body = null): \Illuminate\Http\Client\Response
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $store->shopify_access_token,
        ])->{strtolower($method)}($endpoint, $body ?? []);

        if (!$response->successful()) {
            $status = $response->status();
            $payload = $response->json();
            $bodyPreview = $response->body();

            $message = match (true) {
                $status === 401 || $status === 403 => 'A Shopify rejeitou a operação (escopo write_themes ausente ou token inválido). Reconnecte a loja.',
                $status === 404 => 'Recurso não encontrado na Shopify.',
                $status === 422 => 'A Shopify rejeitou a alteração do tema: ' . $this->extractShopifyError($payload, $bodyPreview),
                $status === 429 => 'Limite de requisições atingido na Shopify. Tente novamente em instantes.',
                $status >= 500 => 'Erro no servidor da Shopify.',
                default => 'Falha na API da Shopify.',
            };

            Log::warning('Shopify Admin API erro', [
                'store_id' => $store->id,
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status,
                'payload' => $payload,
                'body_preview' => substr($bodyPreview, 0, 2000),
            ]);

            throw new \RuntimeException($message, $status);
        }

        return $response;
    }

    /**
     * Extrai a mensagem de erro mais útil do payload da Shopify.
     */
    protected function extractShopifyError(?array $payload, string $body): string
    {
        if (!empty($payload['errors'])) {
            $errors = $payload['errors'];
            if (is_string($errors)) return $errors;
            if (is_array($errors)) return json_encode($errors, JSON_UNESCAPED_UNICODE);
        }
        return substr($body, 0, 200);
    }

    /**
     * Conteúdo do snippet do checkout. Genérico — não depende de dados da loja.
     * O domínio da loja é lido do Liquid via {{ shop.permanent_domain }}.
     * A URL base da API é substituída em upsertSnippet pelo placeholder
     * __CHECKOUT_J_API_BASE__, evitando interpolação dentro de strings Liquid.
     */
    protected function snippetContent(): string
    {
        return <<<LIQUID
{% comment %}
  Checkout J — redirecionamento de checkout
  Gerenciado automaticamente. Não edite este bloco manualmente.
{% endcomment %}
<script>
(function () {
  'use strict';

  var SHOPIFY_DOMAIN = {{ shop.permanent_domain | json }};
  var API_BASE = {{ '__CHECKOUT_J_API_BASE__' | json }};
  var REDIRECT_PATH = '/api/shopify/checkout-redirect';

  if (!SHOPIFY_DOMAIN || !API_BASE) return;

  // Idempotência: evita dupla injeção em temas que carregam o layout várias vezes.
  if (window.__checkoutJInjected) return;
  window.__checkoutJInjected = true;

  function fetchJson(url, opts) {
    opts = opts || {};
    return fetch(url, Object.assign({ headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin' }, opts))
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok) throw Object.assign(new Error(data.message || ('Erro ' + res.status)), { status: res.status, data: data });
          return data;
        });
      });
  }

  // Loader simples no topo da página durante o redirecionamento.
  function showLoader(label) {
    var bar = document.createElement('div');
    bar.id = 'checkout-j-loader';
    bar.setAttribute('style', 'position:fixed;top:0;left:0;right:0;height:3px;z-index:2147483647;background:#6366f1;box-shadow:0 0 8px rgba(99,102,241,.6);');
    var labelEl = document.createElement('div');
    labelEl.setAttribute('style', 'position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:2147483647;background:rgba(17,17,34,.92);color:#fff;font:13px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;padding:8px 14px;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.3);');
    labelEl.textContent = label || 'Abrindo checkout seguro...';
    document.body.appendChild(bar);
    document.body.appendChild(labelEl);
  }

  function redirectToCheckout(cartItems, variantId, qty) {
    var items = cartItems || [];
    if (variantId) {
      items = [{ variant_id: Number(variantId), quantity: Number(qty) || 1 }];
    }

    if (!items || !items.length) {
      alert('Seu carrinho está vazio.');
      return Promise.reject(new Error('empty cart'));
    }

    showLoader();

    return fetchJson(API_BASE + REDIRECT_PATH, {
      method: 'POST',
      body: JSON.stringify({ shop: SHOPIFY_DOMAIN, items: items })
    }).then(function (res) {
      if (!res || !res.redirect_url) throw new Error('Não foi possível gerar o link de checkout.');
      window.location.href = res.redirect_url;
    }).catch(function (err) {
      var el = document.getElementById('checkout-j-loader');
      if (el) el.remove();
      alert(err && err.message ? err.message : 'Falha ao abrir o checkout.');
      throw err;
    });
  }

  // Intercepta o botão "Finalizar compra" do carrinho (página /cart e drawer/mini-cart).
  // Tipos comuns de alvo: form[action*="/checkout"], [name="checkout"], botão com name=checkout.
  document.addEventListener('click', function (e) {
    var target = e.target;
    if (!(target instanceof Element)) return;

    var btn = target.closest('[name="checkout"]');
    if (!btn) {
      // alguns temas usam um <button type="submit"> ou <input type="submit"> sem name=checkout
      var form = target.closest('form');
      if (form && /\/checkout(?:\?|$|\/)/.test(form.getAttribute('action') || '')) {
        btn = target.closest('button, input[type="submit"]');
      }
    }

    if (!btn) return;

    e.preventDefault();
    e.stopImmediatePropagation();

    fetchJson('/cart.js').then(function (cart) {
      return redirectToCheckout(cart.items || []);
    }).catch(function () {
      // fallback: deixa o fluxo nativo seguir
      if (btn instanceof HTMLButtonElement || btn instanceof HTMLInputElement) {
        btn.click();
      }
    });
  }, true);

  // Intercepta submit do form de checkout (carrinho).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var action = form.getAttribute('action') || '';
    if (!/\/checkout/.test(action)) return;

    e.preventDefault();
    e.stopImmediatePropagation();

    fetchJson('/cart.js').then(function (cart) {
      return redirectToCheckout(cart.items || []);
    }).catch(function () {
      // fallback: submete o form original
      form.submit();
    });
  }, true);

  // Página de produto: injeta botão "Comprar agora" que usa a variante
  // selecionada (form[action="/cart/add"]). O "Buy it now" nativo é um iframe
  // cross-origin, por isso criamos um botão próprio.
  function injectBuyNowButtons() {
    var addForms = document.querySelectorAll('form[action*="/cart/add"]');
    Array.prototype.forEach.call(addForms, function (form) {
      if (form.querySelector('[data-checkout-j-buynow]')) return;

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('data-checkout-j-buynow', '1');
      btn.textContent = 'Comprar agora';
      btn.setAttribute('style', 'display:inline-flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:14px 18px;border-radius:8px;border:none;background:#6366f1;color:#fff;font:600 .95rem/1 -apple-system,Segoe UI,Roboto,sans-serif;cursor:pointer;');

      btn.addEventListener('click', function () {
        // Resolve a variante selecionada no form (select[name=id] ou input[name=id] ou radio).
        var variantInput = form.querySelector('select[name="id"], input[name="id"]:checked, input[type="hidden"][name="id"]');
        var variantId = variantInput ? variantInput.value : null;

        if (!variantId) {
          // Tenta ler a variante do JS do tema via window (heurística comum em temas).
          try {
            variantId = (window.product && window.product.variants && window.product.variants[0] && window.product.variants[0].id) || null;
          } catch (_) { variantId = null; }
        }

        if (!variantId) {
          alert('Selecione uma opção do produto antes de comprar.');
          return;
        }

        var qtyInput = form.querySelector('input[name="quantity"]');
        var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;

        redirectToCheckout(null, variantId, qty);
      });

      // Insere ao final do form, evitando sobrepor o "Adicionar ao carrinho".
      form.appendChild(btn);
    });
  }

  // Tenta injetar em DOMContentLoaded e também após um pequeno delay
  // (temas que carregam o form via JS/AJAX).
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectBuyNowButtons);
  } else {
    injectBuyNowButtons();
  }
  setTimeout(injectBuyNowButtons, 800);
  setTimeout(injectBuyNowButtons, 2000);
})();
</script>
LIQUID;
    }
}
