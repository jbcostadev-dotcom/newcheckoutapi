<?php

namespace Tests\Feature;

use App\Jobs\SyncShopifyCollections;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncShopifyCollectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_all_paginated_collections_from_graphql(): void
    {
        $store = $this->createStore();
        $endpoint = 'https://loja-teste.myshopify.com/admin/api/2026-07/graphql.json';

        Http::fake([
            $endpoint => Http::sequence()
                ->push($this->response([
                    $this->collection('gid://shopify/Collection/1', '1', 'Verão', 12),
                ], true, 'cursor-1'))
                ->push($this->response([
                    $this->collection('gid://shopify/Collection/2', '2', 'Inverno', 7),
                ], false, null)),
        ]);

        (new SyncShopifyCollections($store))->handle();

        $this->assertDatabaseCount('shopify_collections', 2);
        $this->assertDatabaseHas('shopify_collections', [
            'store_id' => $store->id,
            'shopify_collection_id' => '1',
            'title' => 'Verão',
            'products_count' => 12,
        ]);
        $this->assertDatabaseHas('shopify_collections', [
            'store_id' => $store->id,
            'shopify_collection_id' => '2',
            'title' => 'Inverno',
            'products_count' => 7,
        ]);

        Http::assertSent(function ($request) use ($endpoint) {
            return $request->url() === $endpoint
                && ($request->data()['variables']['cursor'] ?? null) === 'cursor-1';
        });
    }

    public function test_removes_collections_that_no_longer_exist_in_shopify(): void
    {
        $store = $this->createStore();
        $store->shopifyCollections()->create([
            'shopify_collection_id' => '999',
            'shopify_graphql_id' => 'gid://shopify/Collection/999',
            'title' => 'Coleção removida',
        ]);

        Http::fake([
            'https://loja-teste.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(
                $this->response([], false, null)
            ),
        ]);

        (new SyncShopifyCollections($store))->handle();

        $this->assertDatabaseCount('shopify_collections', 0);
    }

    public function test_keeps_existing_data_when_shopify_returns_graphql_errors(): void
    {
        $store = $this->createStore();
        $store->shopifyCollections()->create([
            'shopify_collection_id' => '999',
            'shopify_graphql_id' => 'gid://shopify/Collection/999',
            'title' => 'Coleção preservada',
        ]);

        Http::fake([
            'https://loja-teste.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'errors' => [['message' => 'Access denied']],
            ]),
        ]);

        (new SyncShopifyCollections($store))->handle();

        $this->assertDatabaseHas('shopify_collections', [
            'shopify_collection_id' => '999',
            'title' => 'Coleção preservada',
        ]);
    }

    private function createStore(): Store
    {
        $user = User::factory()->create();

        return Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
            'shopify_domain' => 'loja-teste.myshopify.com',
            'shopify_access_token' => 'token-fake',
        ]);
    }

    private function response(array $nodes, bool $hasNextPage, ?string $endCursor): array
    {
        return [
            'data' => [
                'collections' => [
                    'nodes' => $nodes,
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => $endCursor,
                    ],
                ],
            ],
        ];
    }

    private function collection(string $id, string $legacyId, string $title, int $productsCount): array
    {
        return [
            'id' => $id,
            'legacyResourceId' => $legacyId,
            'title' => $title,
            'handle' => strtolower($title),
            'descriptionHtml' => "<p>{$title}</p>",
            'updatedAt' => '2026-08-13T12:00:00Z',
            'sortOrder' => 'BEST_SELLING',
            'image' => ['url' => 'https://example.com/collection.jpg'],
            'productsCount' => ['count' => $productsCount],
        ];
    }
}
