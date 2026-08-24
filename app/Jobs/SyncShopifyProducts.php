<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\CheckoutResponseCache;
use App\Services\CheckoutUrlGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncShopifyProducts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

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
        if (! $this->store->isShopifyConnected()) {
            return;
        }

        $headers = [
            'X-Shopify-Access-Token' => $this->store->shopify_access_token,
        ];

        $apiVersion = (string) config('services.shopify.api_version', '2026-07');
        $baseEndpoint = "https://{$this->store->shopify_domain}/admin/api/{$apiVersion}/products.json";

        // Paginação Shopify Admin API (até 250 por página).
        $seenVariantIds = [];
        $page = 1;
        $endpoint = $baseEndpoint;

        while ($endpoint) {
            // A primeira página usa ?limit=250. Páginas seguintes usam a URL
            // completa do header Link (rel="next"), que já contém page_info e
            // limit; não devemos adicionar parâmetros extras para não corromper
            // o cursor de paginação.
            if ($endpoint === $baseEndpoint) {
                $response = Http::withHeaders($headers)->get($endpoint, [
                    'limit' => 250,
                ]);
            } else {
                $response = Http::withHeaders($headers)->get($endpoint);
            }

            if (! $response->successful()) {
                Log::warning('Shopify sync falhou', [
                    'store' => $this->store->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
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

                    // Dimensões e peso: a variante pode ter ou não; produto pai também.
                    $dimensions = $this->extractDimensions($shopifyProduct, $variant);
                    $weightData = $this->extractWeight($shopifyProduct, $variant);

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
                            'sku' => $variant['sku'] ?? null,
                            'barcode' => $variant['barcode'] ?? null,
                            'weight' => $weightData['weight'],
                            'weight_unit' => $weightData['weight_unit'],
                            'grams' => $variant['grams'] ?? null,
                            'height' => $dimensions['height'],
                            'width' => $dimensions['width'],
                            'length' => $dimensions['length'],
                            'dimension_unit' => $dimensions['dimension_unit'],
                            'product_type' => $shopifyProduct['product_type'] ?? null,
                            'vendor' => $shopifyProduct['vendor'] ?? null,
                            'tags' => $this->extractTags($shopifyProduct['tags'] ?? null),
                            'taxable' => $variant['taxable'] ?? null,
                            'requires_shipping' => $variant['requires_shipping'] ?? null,
                            'inventory_policy' => $variant['inventory_policy'] ?? null,
                            'fulfillment_service' => $variant['fulfillment_service'] ?? null,
                            'inventory_item_id' => isset($variant['inventory_item_id']) ? (string) $variant['inventory_item_id'] : null,
                            'position' => $variant['position'] ?? null,
                            'tax_code' => $variant['tax_code'] ?? null,
                            'cost' => $variant['cost'] ?? null,
                            'description' => $this->truncateDescription($shopifyProduct['body_html'] ?? null),
                            'price' => $variant['price'] ?? 0,
                            'compare_at_price' => $variant['compare_at_price'] ?? null,
                            'stock_quantity' => ($variant['inventory_management'] ?? null) === 'shopify'
                                ? ($variant['inventory_quantity'] ?? 0)
                                : null,
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
                Log::warning('Shopify sync interrompido por limite de páginas', [
                    'store_id' => $this->store->id,
                    'pages' => $page,
                ]);
                break;
            }

            // Shopify Admin API REST: bucket padrão ~2 req/s. Pequena pausa
            // entre páginas para evitar 429 e dar tempo ao banco/local.
            if ($endpoint) {
                usleep(500_000);
            }
        }

        // Marca variantes Shopify que sumiram como inativas — preserva histórico de pedido.
        if (! empty($seenVariantIds)) {
            $activeVariantIds = $this->store->products()
                ->whereNotNull('shopify_variant_id')
                ->pluck('shopify_variant_id')
                ->all();

            $missingVariantIds = array_diff($activeVariantIds, $seenVariantIds);

            foreach (array_chunk($missingVariantIds, 500) as $chunk) {
                $this->store->products()
                    ->whereIn('shopify_variant_id', $chunk)
                    ->update(['is_active' => false]);
            }
        }

        // Garante invalidação também para o update em massa das variantes
        // ausentes, que não dispara eventos individuais do Eloquent.
        app(CheckoutResponseCache::class)->invalidateStore((int) $this->store->id);

        Log::info('Shopify sync concluído', [
            'store_id' => $this->store->id,
            'shopify_domain' => $this->store->shopify_domain,
            'variants_imported' => count($seenVariantIds),
        ]);
    }

    /**
     * Extrai peso da variante ou, se ausente, do produto pai.
     * O REST Admin API da Shopify retorna "weight" + "weight_unit" no produto
     * e/ou na variante; a variante costuma ter a versão mais específica.
     *
     * @return array{weight: float|null, weight_unit: string|null}
     */
    protected function extractWeight(array $shopifyProduct, array $variant): array
    {
        $weight = $variant['weight'] ?? $shopifyProduct['weight'] ?? null;
        $unit = $variant['weight_unit'] ?? $shopifyProduct['weight_unit'] ?? null;

        return [
            'weight' => $weight === null ? null : (float) $weight,
            'weight_unit' => $unit ? (string) $unit : null,
        ];
    }

    /**
     * Extrai dimensões da variante (campos customizados de shipping) ou do
     * produto pai. A Shopify não expõe dimensões nativamente no REST clássico,
     * mas mantemos o helper pronto para quando presentes (apps de frete ou
     * metafields populados previamente).
     *
     * @return array{height: float|null, width: float|null, length: float|null, dimension_unit: string|null}
     */
    protected function extractDimensions(array $shopifyProduct, array $variant): array
    {
        $height = $variant['height'] ?? $shopifyProduct['height'] ?? null;
        $width = $variant['width'] ?? $shopifyProduct['width'] ?? null;
        $length = $variant['length'] ?? $shopifyProduct['length'] ?? null;
        $unit = $variant['dimension_unit'] ?? $shopifyProduct['dimension_unit'] ?? null;

        return [
            'height' => $height === null ? null : (float) $height,
            'width' => $width === null ? null : (float) $width,
            'length' => $length === null ? null : (float) $length,
            'dimension_unit' => $unit ? (string) $unit : null,
        ];
    }

    /**
     * Normaliza tags do Shopify (string separada por vírgula) para array.
     *
     * @return string[]|null
     */
    protected function extractTags(?string $tags): ?array
    {
        if ($tags === null || $tags === '') {
            return null;
        }

        $list = array_map('trim', explode(',', $tags));
        $list = array_values(array_filter($list, fn ($t) => $t !== ''));

        return $list ?: null;
    }

    /**
     * Trunca a descrição HTML para o limite seguro de LONGTEXT (4 GB é praticamente
     * ilimitado, mas usamos um teto alto para evitar abuso de memória).
     */
    protected function truncateDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $maxBytes = 1_000_000; // ~1 MB
        if (strlen($description) <= $maxBytes) {
            return $description;
        }

        return substr($description, 0, $maxBytes);
    }

    /**
     * Monta a lista de atributos estruturados a partir das options do produto
     * Shopify e dos valores option1/option2/option3 da variante.
     *
     * @param  array<int,string|null>  $optionNames
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
            if (! $name || strtolower($name) === 'title') {
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
        if (! $linkHeader) {
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
