<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_owner_can_create_gift_with_selected_variants(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $store = $user->stores()->create(['name' => 'Loja Brindes', 'subdomain' => 'loja-brindes']);
        $variantOne = $store->products()->create([
            'name' => 'Tênis Preto 33',
            'parent_title' => 'Tênis Confortável',
            'attributes' => [['name' => 'Tamanho', 'value' => '33']],
            'price' => 99.90,
            'is_active' => true,
        ]);
        $variantTwo = $store->products()->create([
            'name' => 'Tênis Preto 34',
            'parent_title' => 'Tênis Confortável',
            'attributes' => [['name' => 'Tamanho', 'value' => '34']],
            'price' => 99.90,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/stores/{$store->id}/gifts", [
            'name' => 'Brinde de lançamento',
            'product_ids' => [$variantOne->id, $variantTwo->id],
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->addMonth()->toDateString(),
            'rule_type' => 'min_value',
            'min_value' => 299,
            'scope' => 'any',
            'target_product_ids' => [],
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Brinde de lançamento')
            ->assertJsonCount(2, 'products');
        $this->assertDatabaseHas('gifts', ['store_id' => $store->id, 'name' => 'Brinde de lançamento']);
        $this->assertDatabaseCount('gift_product', 2);
    }

    public function test_gift_cannot_use_product_from_another_store(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $store = $user->stores()->create(['name' => 'Loja A', 'subdomain' => 'loja-a']);
        $otherStore = $user->stores()->create(['name' => 'Loja B', 'subdomain' => 'loja-b']);
        $foreignProduct = $otherStore->products()->create([
            'name' => 'Produto externo',
            'price' => 10,
            'is_active' => true,
        ]);

        $this->postJson("/api/stores/{$store->id}/gifts", [
            'name' => 'Brinde inválido',
            'product_ids' => [$foreignProduct->id],
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->addDay()->toDateString(),
            'rule_type' => 'always',
            'scope' => 'any',
            'is_active' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('gifts', 0);
    }

    public function test_checkout_exposes_only_gifts_matching_product_scope_and_period(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $user = User::factory()->create();
        $store = $user->stores()->create(['name' => 'Loja Checkout', 'subdomain' => 'loja-checkout']);
        $cartProduct = $store->products()->create(['name' => 'Camiseta', 'price' => 147, 'is_active' => true]);
        $otherProduct = $store->products()->create(['name' => 'Calça', 'price' => 120, 'is_active' => true]);
        $giftProduct = $store->products()->create([
            'name' => 'Tênis 33',
            'parent_title' => 'Tênis Confortável',
            'attributes' => [['name' => 'Tamanho', 'value' => '33']],
            'price' => 199,
            'is_active' => true,
        ]);

        $eligible = $store->gifts()->create([
            'name' => 'Brinde por valor',
            'rule_type' => 'min_value',
            'min_value' => 299,
            'scope' => 'specific',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $eligible->products()->attach($giftProduct->id);
        $eligible->targetProducts()->attach($cartProduct->id);

        $notMatching = $store->gifts()->create([
            'name' => 'Outro escopo',
            'rule_type' => 'always',
            'scope' => 'specific',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $notMatching->products()->attach($giftProduct->id);
        $notMatching->targetProducts()->attach($otherProduct->id);

        $expired = $store->gifts()->create([
            'name' => 'Expirado',
            'rule_type' => 'always',
            'scope' => 'any',
            'starts_at' => now()->subDays(3),
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $expired->products()->attach($giftProduct->id);

        $response = $this->getJson("/api/checkout?store_id={$store->id}&product_ids={$cartProduct->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'gifts')
            ->assertJsonPath('gifts.0.name', 'Brinde por valor')
            ->assertJsonPath('gifts.0.products.0.id', $giftProduct->id);
    }

    public function test_checkout_editor_preview_always_contains_a_fake_gift(): void
    {
        $user = User::factory()->create();
        $store = $user->stores()->create(['name' => 'Loja Preview', 'subdomain' => 'loja-preview']);

        $response = $this->getJson("/api/checkout/preview?store_id={$store->id}");

        $response->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonCount(1, 'gifts')
            ->assertJsonPath('gifts.0.name', 'Brinde de exemplo')
            ->assertJsonPath('gifts.0.products.0.parent_title', 'Tênis Confortável');
    }

    public function test_store_owner_can_save_gift_display_colors(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $store = $user->stores()->create(['name' => 'Loja Cores', 'subdomain' => 'loja-cores']);

        $colors = [
            'gift_bg_color' => '#F7FFFA',
            'gift_border_color' => '#A4DFC1',
            'gift_badge_bg_color' => '#FFFFFF',
            'gift_badge_border_color' => '#6EE7B7',
            'gift_badge_text_color' => '#10B981',
            'gift_progress_color' => '#10B981',
            'gift_progress_bg_color' => '#E5E7EB',
        ];

        $this->putJson("/api/stores/{$store->id}/settings", $colors)
            ->assertOk()
            ->assertJson($colors);

        $this->assertDatabaseHas('checkout_settings', [
            'store_id' => $store->id,
            ...$colors,
        ]);
    }
}
