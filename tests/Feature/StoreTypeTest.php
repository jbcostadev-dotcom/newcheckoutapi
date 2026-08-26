<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creation_accepts_only_the_three_supported_types(): void
    {
        $user = User::factory()->create();

        foreach (Store::TYPES as $index => $type) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/stores', [
                    'name' => 'Loja '.$index,
                    'type' => $type,
                    'subdomain' => 'loja-tipo-'.$index,
                ])
                ->assertCreated()
                ->assertJsonPath('type', $type);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/stores', [
                'name' => 'Tipo inválido',
                'type' => 'Marketplace',
                'subdomain' => 'tipo-invalido',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_legacy_landing_page_is_treated_as_a_physical_store(): void
    {
        $store = new Store(['type' => Store::LEGACY_TYPE_LANDING]);

        $this->assertSame(Store::TYPE_LANDING_PHYSICAL, $store->normalizedType());
        $this->assertTrue($store->requiresShipping());
        $this->assertTrue($store->supportsGifts());
    }

    public function test_digital_checkout_omits_shipping_options(): void
    {
        $store = $this->createStore(Store::TYPE_LANDING_DIGITAL, 'digital');
        $product = $store->products()->create([
            'name' => 'Curso online',
            'price' => 97,
            'is_active' => true,
        ]);
        $store->shippingMethods()->create([
            'name' => 'Entrega expressa',
            'price' => 19.90,
            'min_delivery_days' => 1,
            'max_delivery_days' => 2,
            'is_active' => true,
        ]);

        $this->getJson("/api/checkout?store_id={$store->id}&product_ids={$product->id}")
            ->assertOk()
            ->assertJsonPath('store.type', Store::TYPE_LANDING_DIGITAL)
            ->assertJsonCount(0, 'shipping_methods')
            ->assertJsonCount(0, 'gifts');
    }

    public function test_physical_checkout_keeps_shipping_options(): void
    {
        $store = $this->createStore(Store::TYPE_LANDING_PHYSICAL, 'fisica');
        $product = $store->products()->create([
            'name' => 'Camiseta',
            'price' => 79.90,
            'is_active' => true,
        ]);
        $store->shippingMethods()->create([
            'name' => 'Entrega padrão',
            'price' => 12.50,
            'min_delivery_days' => 3,
            'max_delivery_days' => 5,
            'is_active' => true,
        ]);

        $this->getJson("/api/checkout?store_id={$store->id}&product_ids={$product->id}")
            ->assertOk()
            ->assertJsonPath('store.type', Store::TYPE_LANDING_PHYSICAL)
            ->assertJsonCount(1, 'shipping_methods');
    }

    private function createStore(string $type, string $suffix): Store
    {
        $user = User::factory()->create();

        return Store::create([
            'user_id' => $user->id,
            'name' => 'Loja '.$suffix,
            'type' => $type,
            'subdomain' => 'loja-'.$suffix,
        ]);
    }
}
