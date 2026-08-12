<?php

namespace Tests\Feature;

use App\Models\CheckoutFunnelSession;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_return_period_totals_and_real_sales_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00'));

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Dashboard',
            'subdomain' => 'loja-dashboard',
        ]);

        $this->createOrder($store, 'paid', 100, '2026-08-11 09:00:00', 'pix');
        $this->createOrder($store, 'paid', 250, '2026-08-10 16:00:00', 'credit_card');
        $this->createOrder($store, 'pending', 80, '2026-08-11 10:00:00');
        $this->createOrder($store, 'failed', 60, '2026-08-09 18:00:00');

        $this->createFunnelSession($store, 'session-1', 'delivery', true);
        $this->createFunnelSession($store, 'session-2', 'personal_data');
        $this->createFunnelSession($store, 'session-3', 'entered');

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/stores/{$store->id}/metrics?period=week",
        );

        $response
            ->assertOk()
            ->assertJsonPath('period', 'week')
            ->assertJsonPath('revenue_total', 350)
            ->assertJsonPath('orders_paid', 2)
            ->assertJsonPath('orders_total', 4)
            ->assertJsonPath('orders_pending', 1)
            ->assertJsonPath('orders_failed', 1)
            ->assertJsonCount(7, 'sales_series')
            ->assertJsonPath('sales_series.5.label', '10/08')
            ->assertJsonPath('sales_series.5.value', 250)
            ->assertJsonPath('sales_series.6.label', '11/08')
            ->assertJsonPath('sales_series.6.value', 100)
            ->assertJsonPath('checkout_funnel.conversion', 33.3)
            ->assertJsonPath('checkout_funnel.stages.0.count', 3)
            ->assertJsonPath('checkout_funnel.stages.1.count', 2)
            ->assertJsonPath('checkout_funnel.stages.2.count', 1)
            ->assertJsonPath('checkout_funnel.stages.3.count', 1)
            ->assertJsonPath('payment_methods.0.method', 'credit_card')
            ->assertJsonPath('payment_methods.0.percentage', 50)
            ->assertJsonPath('payment_methods.1.method', 'pix')
            ->assertJsonPath('payment_methods.1.percentage', 50)
            ->assertJsonPath('payment_methods.2.percentage', 0);

        Carbon::setTestNow();
    }

    private function createOrder(
        Store $store,
        string $status,
        float $amount,
        string $createdAt,
        string $paymentMethod = 'pix',
    ): void
    {
        $order = $store->orders()->create([
            'customer_name' => 'Cliente Teste',
            'customer_email' => 'cliente@example.com',
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'status' => $status,
        ]);

        $order->forceFill([
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($createdAt),
        ])->save();
    }

    private function createFunnelSession(
        Store $store,
        string $sessionId,
        string $stage,
        bool $approved = false,
    ): void
    {
        CheckoutFunnelSession::create([
            'store_id' => $store->id,
            'session_id' => $sessionId,
            'furthest_stage' => $stage,
            'payment_approved' => $approved,
            'approved_at' => $approved ? now() : null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
