<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_listing_paginates_product_groups_without_splitting_variants(): void
    {
        [$user, $store] = $this->createUserAndStore();

        $this->createShopifyVariant($store, 'shopify-1', 'variant-1', 'Azul');
        $this->createShopifyVariant($store, 'shopify-1', 'variant-2', 'Vermelho');
        $this->createShopifyVariant($store, 'shopify-2', 'variant-3', 'Unico');
        $store->products()->create([
            'name' => 'Produto manual',
            'description' => str_repeat('d', 500),
            'price' => 30,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/products?view=grouped&per_page=2&page=1",
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');

        $secondPage = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/products?view=grouped&per_page=2&page=2",
        );

        $secondPage
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');

        $allGroups = collect($response->json('data'))
            ->concat($secondPage->json('data'));
        $group = $allGroups->firstWhere('group_key', 'shopify:shopify-1');

        $this->assertNotNull($group);
        $this->assertCount(2, $group['variants']);
    }

    public function test_grouped_listing_returns_summaries_and_loads_full_details_separately(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $product = $store->products()->create([
            'name' => 'Produto detalhado',
            'description' => str_repeat('x', 500),
            'price' => 19.90,
            'tags' => ['pesado'],
            'weight' => 1.250,
        ]);

        $list = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/products?view=grouped",
        );

        $list
            ->assertOk()
            ->assertJsonPath('data.0.product.id', $product->id)
            ->assertJsonPath('data.0.product.description_excerpt', str_repeat('x', 180))
            ->assertJsonMissingPath('data.0.product.description')
            ->assertJsonMissingPath('data.0.product.tags')
            ->assertJsonMissingPath('data.0.product.weight');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('description', str_repeat('x', 500))
            ->assertJsonPath('tags.0', 'pesado');
    }

    public function test_search_returns_the_whole_shopify_group_when_one_variant_matches(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $this->createShopifyVariant($store, 'shopify-1', 'variant-1', 'Azul');
        $this->createShopifyVariant($store, 'shopify-1', 'variant-2', 'Vermelho');
        $this->createShopifyVariant($store, 'shopify-2', 'variant-3', 'Preto');

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/products?view=grouped&search=Azul",
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.group_key', 'shopify:shopify-1')
            ->assertJsonCount(2, 'data.0.variants');
    }

    public function test_legacy_listing_remains_compatible_for_existing_product_selectors(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $store->products()->create(['name' => 'Produto', 'price' => 10]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/products")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_grouped_listing_cannot_access_another_users_store(): void
    {
        [, $store] = $this->createUserAndStore();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/stores/{$store->id}/products?view=grouped")
            ->assertNotFound();
    }

    private function createUserAndStore(): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste-'.$user->id,
        ]);

        return [$user, $store];
    }

    private function createShopifyVariant(Store $store, string $productId, string $variantId, string $color): void
    {
        $store->products()->create([
            'shopify_product_id' => $productId,
            'shopify_variant_id' => $variantId,
            'name' => 'Camiseta',
            'parent_title' => 'Camiseta',
            'attributes' => [['name' => 'Cor', 'value' => $color]],
            'description' => str_repeat('descricao longa ', 50),
            'price' => 20,
        ]);
    }
}
