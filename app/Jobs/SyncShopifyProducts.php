<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use App\Models\Store;
use App\Services\CheckoutUrlGenerator;

class SyncShopifyProducts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 1;

    protected Store $store;
    protected CheckoutUrlGenerator $urlGenerator;

    public function __construct(Store $store)
    {
        $this->store = $store;
        $this->urlGenerator = app(CheckoutUrlGenerator::class);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->store->isShopifyConnected()) {
            return;
        }

        $headers = [
            'X-Shopify-Access-Token' => $this->store->shopify_access_token,
        ];

        $endpoint = "https://{$this->store->shopify_domain}/admin/api/2025-07/products.json";

        // Paginação Shopify Admin API (até 250 por página).
        $seenVariantIds = [];
        $page = 1;

        while ($endpoint) {
            $response = Http::withHeaders($headers)->get($endpoint, [
                'limit' => 250,
                'page_info' => null,
            ]);

            if (!$response->successful()) {
                Log::warning('Shopify sync falhou', [
                    'store' => $this->store->id,
                    'status' => $response->status(),
                ]);
                return;
            }

            $products = $response->json()['products'] ?? [];

            foreach ($products as $shopifyProduct) {
                $imageSrc = $shopifyProduct['image']['src'] ?? null;

                foreach ($shopifyProduct['variants'] ?? [] as $variant) {
                    $variantId = (string) $variant['id'];
                    $seenVariantIds[] = $variantId;

                    // Nome amigável: "Camiseta - Preta / G" ou só o título se variante única.
                    $variantName = $this->buildVariantName(
                        $shopifyProduct['title'] ?? 'Produto',
                        $variant['title'] ?? null
                    );

                    $product = $this->store->products()->updateOrCreate(
                        [
                            'store_id' => $this->store->id,
                            'shopify_variant_id' => $variantId,
                        ],
                        [
                            'shopify_product_id' => (string) $shopifyProduct['id'],
                            'name' => $variantName,
                            'description' => $shopifyProduct['body_html'] ?? null,
                            'price' => $variant['price'] ?? 0,
                            'compare_at_price' => $variant['compare_at_price'] ?? null,
                            'image_url' => $imageSrc,
                            'is_active' => ($shopifyProduct['status'] ?? '') === 'active',
                        ]
                    );

                    // Atualiza sempre o link direto — domínio pode ter mudado.
                    $product->update([
                        'checkout_url' => $this->urlGenerator->generate($this->store, (int) $product->id),
                    ]);
                }
            }

            // Próxima página via cursor Link header (rel="next").
            $endpoint = $this->getNextPageUrl($response->header('Link'));
            $page++;

            // Proteção contra loop infinito improvável.
            if ($page > 500) {
                break;
            }
        }

        // Marca variantes Shopify que sumiram como inativas — preserva histórico de pedido.
        if (!empty($seenVariantIds)) {
            $this->store->products()
                ->whereNotNull('shopify_variant_id')
                ->whereNotIn('shopify_variant_id', $seenVariantIds)
                ->update(['is_active' => false]);
        }

        Log::info('Shopify sync concluído', [
            'store_id' => $this->store->id,
            'shopify_domain' => $this->store->shopify_domain,
            'variants_imported' => count($seenVariantIds),
        ]);
    }

    /**
     * Constrói o nome do produto considerando a variante.
     */
    protected function buildVariantName(string $title, ?string $variantTitle): string
    {
        if (!$variantTitle || $variantTitle === '' || strtolower($variantTitle) === 'default title') {
            return $title;
        }
        return "{$title} - {$variantTitle}";
    }

    /**
     * Extrai a URL da próxima página do header Link do Shopify.
     * Exemplo: <https://.../products.json?page_info=abc&limit=250>; rel="next", <...>; rel="previous"
     */
    protected function getNextPageUrl(?string $linkHeader): ?string
    {
        if (!$linkHeader) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            if (str_contains($part, 'rel="next"')) {
                if (preg_match('/<([^>]+)>/', $part, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}