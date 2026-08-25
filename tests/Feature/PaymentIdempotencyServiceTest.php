<?php

namespace Tests\Feature;

use App\Models\PaymentIdempotency;
use App\Models\Store;
use App\Models\User;
use App\Services\PaymentIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment_idempotency.store' => 'array',
            'payment_idempotency.secret' => 'test-idempotency-secret',
            'payment_idempotency.wait_milliseconds' => 0,
        ]);

        $user = User::factory()->create();
        $this->store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Idempotente',
            'subdomain' => 'loja-idempotente',
        ]);
    }

    public function test_same_key_replays_the_original_response_without_running_operation_again(): void
    {
        $service = app(PaymentIdempotencyService::class);
        $request = Request::create('/api/checkout/process', 'POST');
        $calls = 0;
        $hash = $service->requestHash(PaymentIdempotency::SCOPE_CHECKOUT, $this->store, [
            'items' => [['product_id' => 10, 'qty' => 1]],
            'payment_method' => 'pix',
        ]);

        $operation = function () use (&$calls) {
            $calls++;

            return response()->json(['order_id' => 321, 'status' => 'waiting_payment'], 201);
        };

        $first = $service->execute(
            $request,
            $this->store,
            PaymentIdempotency::SCOPE_CHECKOUT,
            '11111111-1111-4111-8111-111111111111',
            $hash,
            $operation,
        );
        $second = $service->execute(
            Request::create('/api/checkout/process', 'POST'),
            $this->store,
            PaymentIdempotency::SCOPE_CHECKOUT,
            '11111111-1111-4111-8111-111111111111',
            $hash,
            $operation,
        );

        $this->assertSame(1, $calls);
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));
        $this->assertSame(321, json_decode($second->getContent(), true)['order_id']);
        $this->assertDatabaseCount('payment_idempotencies', 1);
        $record = PaymentIdempotency::firstOrFail();
        $this->assertSame(hash('sha256', '11111111-1111-4111-8111-111111111111'), $record->key_hash);
        $this->assertStringNotContainsString('11111111-1111-4111-8111-111111111111', json_encode($record->toArray()));
    }

    public function test_same_key_with_different_material_payload_returns_conflict(): void
    {
        $service = app(PaymentIdempotencyService::class);
        $key = '22222222-2222-4222-8222-222222222222';
        $calls = 0;
        $firstHash = $service->requestHash('checkout', $this->store, [
            'items' => [['product_id' => 10, 'qty' => 1]],
            'payment_method' => 'pix',
        ]);
        $secondHash = $service->requestHash('checkout', $this->store, [
            'items' => [['product_id' => 10, 'qty' => 2]],
            'payment_method' => 'pix',
        ]);

        $service->execute(
            Request::create('/', 'POST'),
            $this->store,
            'checkout',
            $key,
            $firstHash,
            function () use (&$calls) {
                $calls++;

                return response()->json(['order_id' => 100]);
            },
        );

        $response = $service->execute(
            Request::create('/', 'POST'),
            $this->store,
            'checkout',
            $key,
            $secondHash,
            function () use (&$calls) {
                $calls++;

                return response()->json(['order_id' => 200]);
            },
        );

        $this->assertSame(1, $calls);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('idempotency_key_reused', json_decode($response->getContent(), true)['error']);
    }

    public function test_pending_response_is_not_mistaken_for_a_completed_payment(): void
    {
        $service = app(PaymentIdempotencyService::class);
        $key = '33333333-3333-4333-8333-333333333333';
        $hash = $service->requestHash('upsell', $this->store, [
            'order_id' => 123,
            'upsell_id' => 9,
        ]);
        $calls = 0;
        $operation = function () use (&$calls) {
            $calls++;

            return response()->json([
                'order_id' => null,
                'idempotency_status' => 'processing',
                'retry_after_seconds' => 2,
            ], 202);
        };

        $first = $service->execute(Request::create('/', 'POST'), $this->store, 'upsell', $key, $hash, $operation);
        $second = $service->execute(Request::create('/', 'POST'), $this->store, 'upsell', $key, $hash, $operation);

        $this->assertSame(1, $calls);
        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame(202, $second->getStatusCode());
        $this->assertDatabaseHas('payment_idempotencies', [
            'store_id' => $this->store->id,
            'scope' => 'upsell',
            'state' => PaymentIdempotency::STATE_PROCESSING,
            'order_id' => null,
        ]);
    }

    public function test_request_hash_ignores_tracking_and_cvv_but_changes_with_card_material(): void
    {
        $service = app(PaymentIdempotencyService::class);
        $base = [
            'store_id' => $this->store->id,
            'items' => [['product_id' => 10, 'qty' => 1]],
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvv' => '123',
            'tracking_parameters' => ['utm_source' => 'one'],
        ];

        $sameMaterial = $base;
        $sameMaterial['card_cvv'] = '999';
        $sameMaterial['tracking_parameters'] = ['utm_source' => 'two'];
        $changedCard = $base;
        $changedCard['card_expiry'] = '11/30';

        $this->assertSame(
            $service->requestHash('checkout', $this->store, $base),
            $service->requestHash('checkout', $this->store, $sameMaterial),
        );
        $this->assertNotSame(
            $service->requestHash('checkout', $this->store, $base),
            $service->requestHash('checkout', $this->store, $changedCard),
        );
    }

    public function test_sensitive_response_fields_are_never_persisted_or_replayed(): void
    {
        $service = app(PaymentIdempotencyService::class);
        $hash = $service->requestHash('checkout', $this->store, [
            'items' => [['product_id' => 10, 'qty' => 1]],
            'payment_method' => 'credit_card',
            'card_number' => '4242424242424242',
            'card_expiry' => '12/30',
        ]);

        $response = $service->execute(
            Request::create('/', 'POST'),
            $this->store,
            'checkout',
            '44444444-4444-4444-8444-444444444444',
            $hash,
            fn () => response()->json([
                'order_id' => 77,
                'card_cvv' => '123',
                'card_token' => 'secret-token',
                'details' => ['gateway_response' => ['pan' => '4242424242424242']],
            ]),
        );

        $body = json_decode($response->getContent(), true);
        $persisted = json_encode(PaymentIdempotency::firstOrFail()->response_payload);

        $this->assertSame(['order_id' => 77], $body);
        $this->assertStringNotContainsString('123', $persisted);
        $this->assertStringNotContainsString('secret-token', $persisted);
        $this->assertStringNotContainsString('4242424242424242', $persisted);
    }
}
