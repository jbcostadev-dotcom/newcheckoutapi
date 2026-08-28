<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentIdempotency;
use App\Models\Product;
use App\Models\Store;
use App\Models\Upsell;
use App\Models\User;
use App\Services\PaymentIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DownsellTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Store $store;
    private Product $product;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'payment_idempotency.store' => 'array',
            'payment_idempotency.secret' => 'downsell-test-secret',
            'payment_idempotency.wait_milliseconds' => 0,
            'payment_idempotency.required' => true,
        ]);

        $this->owner = User::factory()->create();
        $this->store = $this->owner->stores()->create([
            'name' => 'Loja de ofertas',
            'subdomain' => 'loja-ofertas',
        ]);
        $this->product = $this->store->products()->create([
            'name' => 'Produto adicional',
            'price' => 50,
            'is_active' => true,
        ]);
        $this->order = Order::create([
            'store_id' => $this->store->id,
            'customer_name' => 'Cliente de teste',
            'customer_email' => 'cliente@example.test',
            'amount' => 100,
            'payment_method' => 'credit_card',
            'status' => Order::STATUS_PAID,
            'card_token' => 'test-token',
            'gateway_transaction_id' => 'test-original-payment',
        ]);
        $this->order->items()->create([
            'product_id' => $this->product->id,
            'name' => 'Compra original',
            'qty' => 2,
            'unit_price' => 50,
        ]);
    }

    public function test_owner_can_manage_both_offer_types_and_legacy_creates_default_to_upsell(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $payload = [
            'name' => 'Oferta',
            'product_id' => $this->product->id,
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'scope' => 'any',
        ];
        $this->postJson("/api/stores/{$this->store->id}/upsells", $payload)
            ->assertCreated()->assertJsonPath('offer_type', 'upsell');
        $created = $this->postJson("/api/stores/{$this->store->id}/upsells", [
            ...$payload, 'offer_type' => 'downsell', 'offer_title' => 'Outra opção',
        ])->assertCreated()->assertJsonPath('offer_type', 'downsell');

        $id = $created->json('id');
        $this->putJson("/api/stores/{$this->store->id}/upsells/{$id}", [
            'discount_value' => 20, 'button_label' => 'Aceitar alternativa',
        ])->assertOk()->assertJsonPath('offer_type', 'downsell')
            ->assertJsonPath('button_label', 'Aceitar alternativa');
        $this->getJson("/api/stores/{$this->store->id}/upsells")->assertOk()->assertJsonCount(2);
        $this->deleteJson("/api/stores/{$this->store->id}/upsells/{$id}")->assertNoContent();
        $this->assertDatabaseCount('upsells', 1);
    }

    public function test_editor_persists_independent_downsell_colors(): void
    {
        $colors = [
            'upsell_bg_color' => '#FFFFFF',
            'downsell_bg_color' => '#102030',
            'downsell_border_color' => '#203040',
            'downsell_text_color' => '#F0F0F0',
            'downsell_button_color' => '#304050',
            'downsell_button_text_color' => '#FFFFFF',
        ];
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/stores/{$this->store->id}/settings", $colors)->assertOk();
        $response = $this->getJson("/api/stores/{$this->store->id}/settings")->assertOk();
        foreach ($colors as $field => $value) {
            $response->assertJsonPath($field, $value);
        }
        $this->offer('upsell');
        $this->getJson($this->offerUrl())->assertOk()
            ->assertJsonPath('settings.downsell_bg_color', '#102030')
            ->assertJsonPath('settings.upsell_bg_color', '#FFFFFF');
    }

    public function test_downsell_is_offered_only_after_declining_upsell_and_stops_after_decline(): void
    {
        $upsell = $this->offer('upsell');
        $downsell = $this->offer('downsell');
        $this->getJson($this->offerUrl())->assertOk()
            ->assertJsonPath('offer_type', 'upsell')->assertJsonPath('upsell.id', $upsell->id);

        $this->decline('upsell')->assertOk();
        $this->getJson($this->offerUrl())->assertOk()
            ->assertJsonPath('has_downsell', true)
            ->assertJsonPath('offer_type', 'downsell')->assertJsonPath('upsell.id', $downsell->id);
        // Retrying the first refusal does not dismiss the second offer.
        $this->decline('upsell')->assertOk();
        $this->assertNull($this->order->fresh()->downsell_status);

        $this->decline('downsell')->assertOk();
        $this->getJson($this->offerUrl())->assertOk()->assertJsonPath('has_upsell', false);
        $this->assertSame('100.00', $this->order->fresh()->amount);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_downsell_alone_does_not_start_the_post_purchase_flow(): void
    {
        $this->offer('downsell');
        $this->getJson($this->offerUrl())->assertOk()->assertJsonPath('has_upsell', false);
        $this->getJson("/api/checkout/order/{$this->order->id}/pix")
            ->assertOk()->assertJsonPath('has_upsell', false);
        $this->decline('upsell')->assertUnprocessable();
    }

    public function test_accepting_upsell_never_opens_downsell(): void
    {
        $upsell = $this->offer('upsell');
        $downsell = $this->offer('downsell');
        $this->charge($upsell)->assertOk()->assertJsonPath('success', true);
        $this->getJson($this->offerUrl())->assertOk()->assertJsonPath('has_upsell', false);
        $this->decline('upsell')->assertOk();
        $this->assertSame('accepted', $this->order->fresh()->upsell_status);
        $this->charge($downsell)->assertUnprocessable();
        $this->assertNull($this->order->fresh()->downsell_status);
    }

    public function test_downsell_cannot_be_charged_or_declined_before_upsell_refusal(): void
    {
        $downsell = $this->offer('downsell');
        $this->charge($downsell)->assertUnprocessable();
        $this->decline('downsell')->assertUnprocessable();
        $this->assertSame('100.00', $this->order->fresh()->amount);
    }

    public function test_downsell_charge_is_idempotent_and_does_not_overwrite_upsell_decision(): void
    {
        $this->offer('upsell');
        $downsell = $this->offer('downsell');
        $this->decline('upsell')->assertOk();
        $key = '11111111-1111-4111-8111-111111111111';
        $this->charge($downsell, $key)->assertOk()->assertJsonPath('success', true);
        $this->charge($downsell, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->charge($downsell, '22222222-2222-4222-8222-222222222222')->assertOk();

        $order = $this->order->fresh();
        $this->assertSame('declined', $order->upsell_status);
        $this->assertSame('accepted', $order->downsell_status);
        $this->assertSame($downsell->id, $order->downsell_id);
        $this->assertSame('40.00', $order->downsell_amount);
        $this->assertSame('140.00', $order->amount);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('payment_idempotencies', [
            'scope' => 'downsell', 'order_id' => $order->id, 'state' => 'completed',
        ]);
        $this->getJson($this->offerUrl())->assertOk()->assertJsonPath('has_upsell', false);
    }

    public function test_in_flight_charges_block_refusal_and_duplicate_charge(): void
    {
        $upsell = $this->offer('upsell');
        $downsell = $this->offer('downsell');
        $this->order->update(['upsell_status' => 'processing']);
        $this->decline('upsell')->assertConflict();
        $this->charge($upsell)->assertStatus(202);
        $this->charge($downsell)->assertUnprocessable();

        $this->order->update(['upsell_status' => 'declined', 'downsell_status' => 'processing']);
        $this->decline('downsell')->assertConflict();
        $this->charge($downsell, '33333333-3333-4333-8333-333333333333')->assertStatus(202);
        $this->assertSame('100.00', $this->order->fresh()->amount);
    }

    public function test_offer_type_and_store_boundaries_are_enforced(): void
    {
        $upsell = $this->offer('upsell');
        $downsell = $this->offer('downsell');
        $this->charge($downsell, null, 'upsell')->assertNotFound();
        $this->decline('upsell')->assertOk();
        $this->charge($upsell, null, 'downsell')->assertNotFound();

        $otherStore = $this->owner->stores()->create(['name' => 'Outra loja', 'subdomain' => 'outra-loja']);
        $this->getJson("/api/checkout/upsell?store_id={$otherStore->id}&order_id={$this->order->id}")
            ->assertNotFound();
    }

    public function test_only_active_matching_downsell_is_returned(): void
    {
        $this->offer('upsell');
        $this->offer('downsell', ['is_active' => false]);
        $this->offer('downsell', ['show_credit_card' => false]);
        $otherProduct = $this->store->products()->create(['name' => 'Outro', 'price' => 10]);
        $this->offer('downsell', ['scope' => 'specific', 'target_product_id' => $otherProduct->id]);
        $matching = $this->offer('downsell', ['scope' => 'specific', 'target_product_id' => $this->product->id]);
        $this->decline('upsell')->assertOk();
        $this->getJson($this->offerUrl())->assertOk()->assertJsonPath('upsell.id', $matching->id);
    }

    public function test_unpaid_orders_cannot_open_the_downsell_flow(): void
    {
        $this->offer('upsell');
        $this->order->update(['status' => Order::STATUS_WAITING_PAYMENT]);
        $this->getJson($this->offerUrl())->assertStatus(400);
        $this->decline('upsell')->assertStatus(400);
        $this->assertNull($this->order->fresh()->upsell_status);
    }

    public function test_idempotency_resolution_uses_the_correct_offer_status(): void
    {
        $this->order->update(['upsell_status' => 'declined', 'downsell_status' => 'processing']);
        foreach (['upsell', 'downsell'] as $scope) {
            PaymentIdempotency::create([
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
                'scope' => $scope,
                'key_hash' => hash('sha256', $scope),
                'request_hash' => hash('sha256', $scope),
                'state' => PaymentIdempotency::STATE_PROCESSING,
            ]);
        }
        app(PaymentIdempotencyService::class)->resolveFromOrder($this->order->fresh());
        $this->assertDatabaseHas('payment_idempotencies', ['scope' => 'upsell', 'state' => 'failed']);
        $this->assertDatabaseHas('payment_idempotencies', ['scope' => 'downsell', 'state' => 'processing']);

        $this->order->update(['downsell_status' => 'accepted']);
        app(PaymentIdempotencyService::class)->resolveFromOrder($this->order->fresh());
        $this->assertDatabaseHas('payment_idempotencies', ['scope' => 'downsell', 'state' => 'completed']);
    }

    public function test_downsell_pix_is_added_only_after_its_own_payment_confirmation(): void
    {
        $downsell = $this->preparePixDownsell();
        $this->charge($downsell)->assertOk()->assertJsonPath('pix_copia_cola', 'pix-extra-code');
        $this->assertSame('100.00', $this->order->fresh()->amount);
        $this->assertSame(Order::STATUS_PAID, $this->order->fresh()->status);
        $this->assertSame('test-original-payment', $this->order->fresh()->gateway_transaction_id);
        $this->assertDatabaseCount('order_items', 1);
        $this->getJson("/api/checkout/order/{$this->order->id}/pix")->assertOk()
            ->assertJsonPath('status', Order::STATUS_WAITING_PAYMENT)
            ->assertJsonPath('pix_copia_cola', 'pix-extra-code');

        $this->postJson('/api/webhook/unipay', ['data' => ['id' => 'extra-pix', 'status' => 'PAID']])->assertOk();
        $this->postJson('/api/webhook/unipay', ['data' => ['id' => 'extra-pix', 'status' => 'PAID']])->assertOk();
        $this->postJson('/api/webhook/unipay', ['data' => ['id' => 'extra-pix', 'status' => 'WAITING_PAYMENT']])->assertOk();
        $this->assertSame('140.00', $this->order->fresh()->amount);
        $this->assertSame('40.00', $this->order->fresh()->downsell_amount);
        $this->assertSame('declined', $this->order->fresh()->upsell_status);
        $this->assertDatabaseCount('order_items', 2);
        $this->getJson("/api/checkout/order/{$this->order->id}/pix")->assertOk()
            ->assertJsonPath('status', Order::STATUS_PAID)->assertJsonPath('has_upsell', false);
        Http::assertSentCount(1);
    }

    public function test_canceling_extra_pix_does_not_cancel_the_original_order(): void
    {
        $this->charge($this->preparePixDownsell())->assertOk();
        $this->postJson('/api/webhook/unipay', ['data' => ['id' => 'extra-pix', 'status' => 'CANCELED']])->assertOk();
        $this->assertSame(Order::STATUS_PAID, $this->order->fresh()->status);
        $this->assertSame('100.00', $this->order->fresh()->amount);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_indeterminate_pix_charge_keeps_downsell_locked(): void
    {
        $downsell = $this->preparePixDownsell(gatewayFails: true);
        $this->charge($downsell)->assertStatus(202);
        $this->assertSame('processing', $this->order->fresh()->downsell_status);
        $this->decline('downsell')->assertConflict();
        $this->charge($downsell)->assertStatus(202);
        Http::assertSentCount(1);
    }

    private function preparePixDownsell(bool $gatewayFails = false): Upsell
    {
        config(['services.unipay.webhook_secret' => null, 'services.unipay.webhook_ips' => []]);
        $this->store->gateways()->create([
            'name' => 'Gateway de teste', 'provider' => 'unipay',
            'secret_key' => 'test-secret', 'is_active' => true,
        ]);
        $this->order->update(['payment_method' => 'pix', 'upsell_status' => 'declined']);
        Http::fake(['*' => $gatewayFails ? Http::response(['message' => 'Gateway unavailable'], 503) : Http::response([
            'data' => [
                'id' => 'extra-pix', 'status' => 'WAITING_PAYMENT',
                'pix' => ['qrcode' => 'pix-extra-code', 'expirationDate' => now()->addHour()->toISOString()],
            ],
        ], 200)]);

        return $this->offer('downsell');
    }

    private function offer(string $type, array $attributes = []): Upsell
    {
        return $this->store->upsells()->create([
            'offer_type' => $type,
            'name' => ucfirst($type),
            'product_id' => $this->product->id,
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'scope' => 'any',
            'show_credit_card' => true,
            'show_pix' => true,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function offerUrl(): string
    {
        return "/api/checkout/upsell?store_id={$this->store->id}&order_id={$this->order->id}";
    }

    private function decline(string $type)
    {
        return $this->postJson('/api/checkout/upsell/decline', [
            'store_id' => $this->store->id, 'order_id' => $this->order->id, 'offer_type' => $type,
        ]);
    }

    private function charge(Upsell $offer, ?string $key = null, ?string $endpointType = null)
    {
        $type = $endpointType ?? $offer->offer_type;
        return $this->postJson("/api/checkout/{$type}/charge", [
            'store_id' => $this->store->id, 'order_id' => $this->order->id, 'upsell_id' => $offer->id,
        ], ['Idempotency-Key' => $key ?? (string) \Illuminate\Support\Str::uuid()]);
    }
}
