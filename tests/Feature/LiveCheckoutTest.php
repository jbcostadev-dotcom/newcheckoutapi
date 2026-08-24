<?php

namespace Tests\Feature;

use App\Models\CheckoutFunnelSession;
use App\Models\User;
use App\Services\LiveCheckoutRedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class LiveCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_accepts_store_id_without_domain(): void
    {
        $user = User::factory()->create();
        $store = $user->stores()->create([
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
        ]);

        $liveCheckout = Mockery::mock(LiveCheckoutRedisStore::class);
        $liveCheckout->shouldReceive('heartbeat')
            ->once()
            ->with(
                (int) $store->id,
                'session-store-id',
                Mockery::on(fn (array $session) => $session['domain'] === 'loja-teste'
                    && $session['step'] === 'dados'
                    && $session['session_id'] === 'session-store-id'),
            )
            ->andReturn(null);
        $this->app->instance(LiveCheckoutRedisStore::class, $liveCheckout);

        $response = $this->postJson('/api/checkout/live/heartbeat', [
            'store_id' => $store->id,
            'session_id' => 'session-store-id',
            'step' => 'dados',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('checkout_funnel_sessions', [
            'store_id' => $store->id,
            'session_id' => 'session-store-id',
            'furthest_stage' => CheckoutFunnelSession::STAGE_ENTERED,
        ]);
    }

    public function test_repeated_heartbeat_in_same_step_does_not_write_to_database(): void
    {
        $user = User::factory()->create();
        $store = $user->stores()->create([
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
        ]);

        $funnelSession = CheckoutFunnelSession::create([
            'store_id' => $store->id,
            'session_id' => 'same-step-session',
            'furthest_stage' => CheckoutFunnelSession::STAGE_ENTERED,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        $lastSeenAt = $funnelSession->last_seen_at->toDateTimeString();

        $liveCheckout = Mockery::mock(LiveCheckoutRedisStore::class);
        $liveCheckout->shouldReceive('heartbeat')
            ->once()
            ->andReturn('dados');
        $this->app->instance(LiveCheckoutRedisStore::class, $liveCheckout);

        $this->postJson('/api/checkout/live/heartbeat', [
            'store_id' => $store->id,
            'session_id' => 'same-step-session',
            'step' => 'dados',
        ])->assertOk();

        $this->assertSame($lastSeenAt, $funnelSession->fresh()->last_seen_at->toDateTimeString());
    }

    public function test_index_returns_sessions_from_redis_store(): void
    {
        $user = User::factory()->create();
        $store = $user->stores()->create([
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
        ]);

        Sanctum::actingAs($user);

        $sessions = [
            [
                'store_id' => $store->id,
                'session_id' => 'active-session',
                'step' => 'pagamento',
                'last_seen_at' => now()->toDateTimeString(),
            ],
        ];

        $liveCheckout = Mockery::mock(LiveCheckoutRedisStore::class);
        $liveCheckout->shouldReceive('activeSessions')
            ->once()
            ->with((int) $store->id)
            ->andReturn($sessions);
        $this->app->instance(LiveCheckoutRedisStore::class, $liveCheckout);

        $this->getJson("/api/stores/{$store->id}/live-checkout")
            ->assertOk()
            ->assertJson([
                'sessions' => $sessions,
                'count' => 1,
            ]);
    }
}
