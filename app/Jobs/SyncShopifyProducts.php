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
                $parentTitle = $shopifyProduct['title'] ?? 'Produto';

                // Nomes das opções do produto no Shopify (max 3):
                // ex.: ["Cor", "Tamanho", "Material"].
                $optionNames = [];
                foreach ($shopifyProduct['options'] ?? [] as $opt) {
                    $optionNames[] = $opt['name'] ?? null;
                }

                foreach ($shopifyProduct['variants'] ?? [] as $variant) {
                    $variantId = (string) $variant['id'];
                    $seenVariantIds[] = $variantId;

                    // Atributos estruturados = combinação nome+valor de cada option.
                    $attributes = $this->buildAttributes($optionNames, $variant);

                    $product = $this->store->products()->updateOrCreate(
                        [
                            'store_id' => $this->store->id,
                            'shopify_variant_id' => $variantId,
                        ],
                        [
                            'shopify_product_id' => (string) $shopifyProduct['id'],
                            'name' => $parentTitle,
                            'parent_title' => $parentTitle,
                            'attributes' => $attributes ?: null,
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
     * Monta a lista de atributos estruturados a partir das options do produto
     * Shopify e dos valores option1/option2/option3 da variante.
     *
     * @param array<int,string|null> $optionNames
     * @return array<int,array{name:string,value:string}>
     */
    protected function buildAttributes(array $optionNames, array $variant): array
    {
        $values = [
            $variant['option1'] ?? null,
            $variant['option2'] ?? null,
            $variant['option3'] ?? null,
        ];

        $attributes = [];
        foreach ($values as $i => $value) {
            if ($value === null || $value === '' || strtolower($value) === 'default title') {
                continue;
            }
            $name = $optionNames[$i] ?? null;
            if (!$name || strtolower($name) === 'title') {
                continue;
            }
            $attributes[] = [
                'name' => $name,
                'value' => (string) $value,
            ];
        }

        return $attributes;
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