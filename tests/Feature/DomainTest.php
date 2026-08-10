<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => 'zone-test',
            'services.cloudflare.saas_cname_target' => 'customers.bersenker.shop',
        ]);
    }

    public function test_base_domain_is_provisioned_with_fixed_checkout_subdomain(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames?*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-1',
                    'hostname' => 'checkout.exemplo.com.br',
                    'status' => 'pending',
                    'ssl' => ['status' => 'pending_validation'],
                ],
            ], 201),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/stores/{$store->id}/domains",
            ['domain' => 'https://www.Exemplo.com.br/'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('domain.domain', 'checkout.exemplo.com.br')
            ->assertJsonPath('domain.cloudflare_custom_hostname_id', 'cf-hostname-1')
            ->assertJsonPath('domain.ssl_status', 'pending_validation')
            ->assertJsonPath('instructions.host', 'checkout')
            ->assertJsonPath('instructions.target', 'customers.bersenker.shop');

        $this->assertDatabaseHas('domains', [
            'store_id' => $store->id,
            'domain' => 'checkout.exemplo.com.br',
            'cloudflare_custom_hostname_id' => 'cf-hostname-1',
        ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames'
            && $request['hostname'] === 'checkout.exemplo.com.br'
            && $request['ssl']['method'] === 'http');
    }

    public function test_checkout_prefix_is_not_duplicated(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames?*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-hostname-2',
                    'hostname' => 'checkout.exemplo.com',
                    'status' => 'pending',
                    'ssl' => ['status' => 'pending_validation'],
                ],
            ], 201),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-prefixo',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/domains", ['domain' => 'checkout.exemplo.com'])
            ->assertCreated()
            ->assertJsonPath('domain.domain', 'checkout.exemplo.com');
    }

    public function test_domain_only_activates_after_hostname_and_ssl_are_active(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames/cf-active' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-active',
                    'hostname' => 'checkout.exemplo.com.br',
                    'status' => 'active',
                    'ssl' => ['status' => 'active'],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-ativa',
        ]);
        $domain = $store->domains()->create([
            'domain' => 'checkout.exemplo.com.br',
            'status' => 'pending',
            'ssl_status' => 'pending_validation',
            'cloudflare_custom_hostname_id' => 'cf-active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/domains/{$domain->id}/verify-dns")
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('hostname_status', 'active')
            ->assertJsonPath('ssl_status', 'active');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => 'active',
            'ssl_active' => true,
        ]);
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'custom_domain' => 'checkout.exemplo.com.br',
        ]);
    }

    public function test_domain_stays_pending_while_certificate_is_pending(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames/cf-pending' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-pending',
                    'hostname' => 'checkout.exemplo.com.br',
                    'status' => 'active',
                    'ssl' => ['status' => 'pending_issuance'],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-pendente',
        ]);
        $domain = $store->domains()->create([
            'domain' => 'checkout.exemplo.com.br',
            'status' => 'pending',
            'ssl_status' => 'pending',
            'cloudflare_custom_hostname_id' => 'cf-pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/domains/{$domain->id}/verify-dns")
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('ssl_status', 'pending_issuance');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => 'pending',
            'ssl_active' => false,
        ]);
        $this->assertNull($store->fresh()->custom_domain);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames/cf-pending');
    }

    public function test_active_redeploying_hostname_is_available_for_checkout(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-test/custom_hostnames/cf-redeploying' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cf-redeploying',
                    'hostname' => 'checkout.exemplo.com.br',
                    'status' => 'active_redeploying',
                    'ssl' => ['status' => 'active'],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-redeploying',
        ]);
        $domain = $store->domains()->create([
            'domain' => 'checkout.exemplo.com.br',
            'status' => 'pending',
            'ssl_status' => 'active',
            'cloudflare_hostname_status' => 'pending',
            'cloudflare_custom_hostname_id' => 'cf-redeploying',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/stores/{$store->id}/domains/{$domain->id}/verify-dns")
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('hostname_status', 'active_redeploying')
            ->assertJsonPath('ssl_status', 'active');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => 'active',
            'ssl_active' => true,
        ]);
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'custom_domain' => 'checkout.exemplo.com.br',
        ]);
    }

    public function test_custom_domain_cannot_be_set_through_store_update(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-sem-atalho',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/stores/{$store->id}", [
                'custom_domain' => 'checkout.nao-validado.com',
            ])
            ->assertOk()
            ->assertJsonPath('custom_domain', null);

        $this->assertNull($store->fresh()->custom_domain);
    }

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
