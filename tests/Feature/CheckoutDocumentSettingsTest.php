<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutDocumentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_settings_default_to_cpf_only_and_require_one_active_type(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Documentos',
            'subdomain' => 'loja-documentos',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/settings")
            ->assertOk()
            ->assertJsonPath('accept_cpf', true)
            ->assertJsonPath('accept_cnpj', false);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/stores/{$store->id}/settings", [
                'accept_cpf' => false,
                'accept_cnpj' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_cpf');
    }

    public function test_cnpj_customer_is_allowed_only_when_enabled(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja B2B',
            'subdomain' => 'loja-b2b',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/settings")
            ->assertOk();

        $payload = [
            'store_id' => $store->id,
            'name' => 'Empresa Exemplo Ltda',
            'email' => 'financeiro@empresa.test',
            'phone' => '11999999999',
            'document' => '11.222.333/0001-81',
            'person_type' => 'company',
            'state_registration' => '123.456.789.000',
            'state_registration_exempt' => false,
        ];

        $this->postJson('/api/checkout/customer', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/stores/{$store->id}/settings", [
                'accept_cpf' => true,
                'accept_cnpj' => true,
            ])
            ->assertOk();

        $this->postJson('/api/checkout/customer', $payload)->assertOk();

        $this->assertDatabaseHas('customers', [
            'store_id' => $store->id,
            'email' => 'financeiro@empresa.test',
            'document' => '11222333000181',
            'person_type' => 'company',
            'state_registration' => '123.456.789.000',
            'state_registration_exempt' => false,
        ]);
    }
}
