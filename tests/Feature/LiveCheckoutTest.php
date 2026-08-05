<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $response = $this->postJson('/api/checkout/live/heartbeat', [
            'store_id' => $store->id,
            'session_id' => 'session-store-id',
            'step' => 'dados',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('loja-teste', Cache::get("live_checkout:{$store->id}:session-store-id")['domain']);
    }
}
