<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutInstallmentSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_installment_summary_setting_can_be_disabled_and_persisted(): void
    {
        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Parcelamento',
            'subdomain' => 'loja-parcelamento',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/stores/{$store->id}/settings", [
                'summary_show_installments' => false,
            ])
            ->assertOk()
            ->assertJsonPath('summary_show_installments', false);

        $this->assertDatabaseHas('checkout_settings', [
            'store_id' => $store->id,
            'summary_show_installments' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/stores/{$store->id}/settings")
            ->assertOk()
            ->assertJsonPath('summary_show_installments', false);
    }
}
