<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAndCartFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_can_be_filtered_by_an_exact_browser_date_range(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $inside = $this->createOrder($store, 'Dentro', '2026-08-17 14:30:00');
        $this->createOrder($store, 'Antes', '2026-08-16 23:59:59');
        $this->createOrder($store, 'Depois', '2026-08-18 00:00:00');

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/orders?start_at=2026-08-17T00%3A00%3A00.000Z&end_at=2026-08-18T00%3A00%3A00.000Z",
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_abandoned_carts_use_last_activity_with_created_at_as_fallback(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $inside = $this->createCart($store, 'Dentro', '2026-08-17 12:00:00');
        $this->createCart($store, 'Fora', '2026-08-16 12:00:00');
        $fallback = $this->createCart($store, 'Sem atividade', null, '2026-08-17 08:00:00');

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/abandoned-carts?start_at=2026-08-17T00%3A00%3A00.000Z&end_at=2026-08-18T00%3A00%3A00.000Z",
        );

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inside->id));
        $this->assertTrue($ids->contains($fallback->id));
    }

    public function test_csv_download_uses_the_active_filters_and_escapes_formulas(): void
    {
        [$user, $store] = $this->createUserAndStore();
        $included = $this->createOrder($store, '=Cliente', '2026-08-17 14:30:00', 'paid');
        $included->items()->create([
            'name' => 'Produto principal',
            'qty' => 2,
            'unit_price' => 49.95,
        ]);
        $this->createOrder($store, 'Pendente', '2026-08-17 15:00:00', 'pending');
        $this->createOrder($store, 'Outro dia', '2026-08-16 14:30:00', 'paid');

        $response = $this->actingAs($user, 'sanctum')->get(
            "/api/stores/{$store->id}/orders/export?status=paid&start_at=2026-08-17T00%3A00%3A00.000Z&end_at=2026-08-18T00%3A00%3A00.000Z",
        );

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=" . 'Cliente', $csv);
        $this->assertStringContainsString('Produto principal', $csv);
        $this->assertStringContainsString(';Pago;', $csv);
        $this->assertStringNotContainsString('Pendente', $csv);
        $this->assertStringNotContainsString('Outro dia', $csv);
    }

    private function createUserAndStore(): array
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Filtros',
            'subdomain' => 'loja-filtros',
        ]);

        return [$user, $store];
    }

    private function createOrder(
        Store $store,
        string $customerName,
        string $createdAt,
        string $status = 'paid',
    ): Order {
        $order = $store->orders()->create([
            'customer_name' => $customerName,
            'customer_email' => strtolower(str_replace(' ', '.', ltrim($customerName, '='))) . '@example.com',
            'amount' => 99.90,
            'payment_method' => 'pix',
            'status' => $status,
        ]);

        $order->forceFill([
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($createdAt),
        ])->save();

        return $order;
    }

    private function createCart(
        Store $store,
        string $customerName,
        ?string $lastActivityAt,
        ?string $createdAt = null,
    ): AbandonedCart {
        $cart = AbandonedCart::create([
            'store_id' => $store->id,
            'customer_name' => $customerName,
            'customer_email' => strtolower(str_replace(' ', '.', $customerName)) . '@example.com',
            'items' => [],
            'subtotal' => 0,
            'total' => 0,
            'step_reached' => 'dados',
            'status' => 'open',
            'last_activity_at' => $lastActivityAt ? Carbon::parse($lastActivityAt) : null,
        ]);

        if ($createdAt) {
            $cart->forceFill([
                'created_at' => Carbon::parse($createdAt),
                'updated_at' => Carbon::parse($createdAt),
            ])->save();
        }

        return $cart;
    }
}
