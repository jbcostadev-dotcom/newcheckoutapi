<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_can_have_only_one_custom_domain(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
        ]);

        $store->domains()->create([
            'domain' => 'existente.com.br',
            'status' => 'pending',
            'ssl_status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/stores/{$store->id}/domains",
            ['domain' => 'novo.com.br'],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta loja ja possui um dominio personalizado. Remova o dominio atual para cadastrar outro.');

        $this->assertDatabaseCount('domains', 1);
        $this->assertDatabaseMissing('domains', ['domain' => 'novo.com.br']);
    }
}
