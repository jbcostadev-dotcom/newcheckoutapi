<?php

namespace App\Jobs;

use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncShopifyCollections implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(protected Store $store)
    {
    }

    public function handle(): void
    {
        if (! $this->store->isShopifyConnected()) {
            return;
        }

        $apiVersion = (string) config('services.shopify.api_version', '2026-07');
        $endpoint = "https://{$this->store->shopify_domain}/admin/api/{$apiVersion}/graphql.json";
        $headers = [
            'X-Shopify-Access-Token' => $this->store->shopify_access_token,
            'Content-Type' => 'application/json',
        ];
        $query = <<<'GRAPHQL'
query SyncCollections($cursor: String) {
  collections(first: 50, after: $cursor, sortKey: UPDATED_AT, reverse: true) {
    nodes {
      id
      legacyResourceId
      title
      handle
      descriptionHtml
      updatedAt
      sortOrder
      image {
        url
      }
      productsCount {
        count
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;

        $seenIds = [];
        $cursor = null;
        $page = 1;
        $syncedAt = now();

        do {
            $response = Http::withHeaders($headers)->post($endpoint, [
                'query' => $query,
                'variables' => ['cursor' => $cursor],
            ]);

            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || ! empty($payload['errors'])) {
                Log::warning('Shopify collections sync falhou', [
                    'store_id' => $this->store->id,
                    'status' => $response->status(),
                    'errors' => is_array($payload) ? ($payload['errors'] ?? null) : null,
                    'body' => $response->successful() ? null : $response->body(),
                ]);

                return;
            }

            $connection = $payload['data']['collections'] ?? null;
            if (! is_array($connection)) {
                Log::warning('Shopify collections sync retornou resposta inválida', [
                    'store_id' => $this->store->id,
                ]);

                return;
            }

            foreach ($connection['nodes'] ?? [] as $collection) {
                $graphqlId = (string) ($collection['id'] ?? '');
                $legacyId = (string) ($collection['legacyResourceId'] ?? '');

                if ($graphqlId === '' || $legacyId === '') {
                    continue;
                }

                $seenIds[] = $graphqlId;
                $this->store->shopifyCollections()->updateOrCreate(
                    ['shopify_graphql_id' => $graphqlId],
                    [
                        'shopify_collection_id' => $legacyId,
                        'title' => (string) ($collection['title'] ?? 'Coleção sem título'),
                        'handle' => $collection['handle'] ?? null,
                        'description' => $collection['descriptionHtml'] ?? null,
                        'image_url' => $collection['image']['url'] ?? null,
                        'products_count' => (int) ($collection['productsCount']['count'] ?? 0),
                        'sort_order' => $collection['sortOrder'] ?? null,
                        'shopify_updated_at' => $collection['updatedAt'] ?? null,
                        'last_synced_at' => $syncedAt,
                    ]
                );
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $pageInfo['endCursor'] ?? null;
            $page++;

            if ($hasNextPage && ! $cursor) {
                Log::warning('Shopify collections sync sem cursor para a próxima página', [
                    'store_id' => $this->store->id,
                ]);

                return;
            }

            if ($page > 1000) {
                Log::warning('Shopify collections sync interrompido por limite de páginas', [
                    'store_id' => $this->store->id,
                    'pages' => $page,
                ]);

                return;
            }

            if ($hasNextPage) {
                usleep(250_000);
            }
        } while ($hasNextPage);

        $existingIds = $this->store->shopifyCollections()
            ->pluck('shopify_graphql_id')
            ->all();
        $missingIds = array_diff($existingIds, $seenIds);

        foreach (array_chunk($missingIds, 500) as $chunk) {
            $this->store->shopifyCollections()
                ->whereIn('shopify_graphql_id', $chunk)
                ->delete();
        }

        Log::info('Shopify collections sync concluído', [
            'store_id' => $this->store->id,
            'shopify_domain' => $this->store->shopify_domain,
            'collections_imported' => count($seenIds),
        ]);
    }
}
