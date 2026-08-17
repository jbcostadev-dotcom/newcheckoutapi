<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_list_update_and_delete_a_kit(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $firstProduct = $store->products()->create(['name' => 'Camiseta', 'price' => 49.90]);
        $secondProduct = $store->products()->create(['name' => 'Boné', 'price' => 30]);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/kits", [
                'name' => 'Kit Verão',
                'is_active' => true,
                'products' => [
                    ['product_id' => $firstProduct->id, 'quantity' => 2],
                    ['product_id' => $secondProduct->id, 'quantity' => 1],
                ],
            ]);

        $created
            ->assertCreated()
            ->assertJsonPath('name', 'Kit Verão')
            ->assertJsonPath('products_count', 2)
            ->assertJsonPath('items_count', 3)
            ->assertJsonPath('subtotal', 129.8)
            ->assertJsonPath('products.0.pivot.quantity', 2);

        $kitId = $created->json('id');
        $expectedProducts = implode(',', [
            $firstProduct->id,
            $firstProduct->id,
            $secondProduct->id,
        ]);
        $this->assertStringContainsString(
            "products={$expectedProducts}",
            $created->json('checkout_url'),
        );

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/kits")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $kitId);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/stores/{$store->id}/kits/{$kitId}", [
                'name' => 'Kit Verão Atualizado',
                'is_active' => false,
                'products' => [
                    ['product_id' => $secondProduct->id, 'quantity' => 3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Kit Verão Atualizado')
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('products_count', 1)
            ->assertJsonPath('items_count', 3)
            ->assertJsonPath('subtotal', 90);

        $this->assertDatabaseHas('kit_product', [
            'kit_id' => $kitId,
            'product_id' => $secondProduct->id,
            'quantity' => 3,
        ]);
        $this->assertDatabaseMissing('kit_product', [
            'kit_id' => $kitId,
            'product_id' => $firstProduct->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/stores/{$store->id}/kits/{$kitId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('kits', ['id' => $kitId]);
    }

    public function test_kit_rejects_products_from_another_store(): void
    {
        [$user, $store] = $this->createUserAndStore();
        [, $otherStore] = $this->createUserAndStore();
        $foreignProduct = $otherStore->products()->create([
            'name' => 'Produto externo',
            'price' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/kits", [
                'name' => 'Kit inválido',
                'is_active' => true,
                'products' => [
                    ['product_id' => $foreignProduct->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('products');

        $this->assertDatabaseCount('kits', 0);
    }

    public function test_user_cannot_access_kits_from_another_users_store(): void
    {
        [, $store] = $this->createUserAndStore();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/stores/{$store->id}/kits")
            ->assertNotFound();
    }

    public function test_kit_requires_at_least_one_product(): void
    {
        [$user, $store] = $this->createUserAndStore();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/kits", [
                'name' => 'Kit vazio',
                'is_active' => true,
                'products' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('products');
    }

    public function test_kit_limits_the_total_cart_quantity(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $firstProduct = $store->products()->create(['name' => 'Produto A', 'price' => 10]);
        $secondProduct = $store->products()->create(['name' => 'Produto B', 'price' => 20]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/kits", [
                'name' => 'Kit grande demais',
                'is_active' => true,
                'products' => [
                    ['product_id' => $firstProduct->id, 'quantity' => 99],
                    ['product_id' => $secondProduct->id, 'quantity' => 2],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('products');
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
}
