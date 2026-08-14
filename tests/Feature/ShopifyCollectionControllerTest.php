<?php

namespace Tests\Feature;

use App\Jobs\SyncShopifyCollections;
use App\Jobs\SyncShopifyProducts;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifyCollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_searches_collections_for_the_authenticated_store(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $this->createCollection($store, '1', 'Coleção Verão');
        $this->createCollection($store, '2', 'Coleção Inverno');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/shopify/collections?search=Verão")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Coleção Verão')
            ->assertJsonPath('data.0.products_count', 3);
    }

    public function test_cannot_list_collections_from_another_users_store(): void
    {
        [, $store] = $this->createUserAndStore();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/stores/{$store->id}/shopify/collections")
            ->assertNotFound();
    }

    public function test_catalog_sync_dispatches_products_and_collections_jobs(): void
    {
        Queue::fake();
        [$user, $store] = $this->createUserAndStore(true);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/shopify/sync")
            ->assertOk()
            ->assertJsonPath('message', 'Sincronização de produtos e coleções iniciada com sucesso.');

        Queue::assertPushed(SyncShopifyProducts::class);
        Queue::assertPushed(SyncShopifyCollections::class);
    }

    private function createUserAndStore(bool $connected = false): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste-'.$user->id,
            'shopify_domain' => $connected ? 'loja-teste.myshopify.com' : null,
            'shopify_access_token' => $connected ? 'token-fake' : null,
        ]);

        return [$user, $store];
    }

    private function createCollection(Store $store, string $id, string $title): void
    {
        $store->shopifyCollections()->create([
            'shopify_collection_id' => $id,
            'shopify_graphql_id' => "gid://shopify/Collection/{$id}",
            'title' => $title,
            'handle' => "colecao-{$id}",
            'products_count' => 3,
            'shopify_updated_at' => '2026-08-13 12:00:00',
        ]);
    }
}
