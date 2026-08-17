<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\AbandonedCart;
use App\Models\Store;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_rotate_and_delete_a_webhook(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user);

        $created = $this->actingAs($user, 'sanctum')->postJson(
            "/api/stores/{$store->id}/webhooks",
            [
                'name' => 'ERP de pedidos',
                'url' => 'https://example.com/hooks/checkout',
                'events' => [Webhook::EVENT_ORDER_CREATED, Webhook::EVENT_ORDER_PAID],
                'is_active' => true,
            ],
        );

        $created
            ->assertCreated()
            ->assertJsonPath('name', 'ERP de pedidos')
            ->assertJsonPath('events.1', Webhook::EVENT_ORDER_PAID)
            ->assertJsonPath('is_active', true);

        $webhookId = $created->json('id');
        $originalToken = $created->json('token');
        $this->assertNotEmpty($originalToken);
        $this->assertNotSame(
            $originalToken,
            DB::table('webhooks')->where('id', $webhookId)->value('token'),
        );

        $this->actingAs($user, 'sanctum')->putJson(
            "/api/stores/{$store->id}/webhooks/{$webhookId}",
            [
                'name' => 'ERP principal',
                'url' => 'https://example.com/hooks/orders',
                'events' => [Webhook::EVENT_ORDER_PAID],
                'is_active' => false,
            ],
        )->assertOk()->assertJsonPath('name', 'ERP principal');

        $rotated = $this->actingAs($user, 'sanctum')->postJson(
            "/api/stores/{$store->id}/webhooks/{$webhookId}/rotate-token",
        );
        $rotated->assertOk();
        $this->assertNotSame($originalToken, $rotated->json('token'));

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/stores/{$store->id}/webhooks/{$webhookId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('webhooks', ['id' => $webhookId]);
    }

    public function test_webhook_rejects_private_or_local_destinations(): void
    {
        $user = User::factory()->create();
        $store = $this->createStore($user);

        foreach (['http://127.0.0.1/hook', 'http://10.0.0.5/hook', 'http://localhost/hook'] as $url) {
            $this->actingAs($user, 'sanctum')->postJson(
                "/api/stores/{$store->id}/webhooks",
                [
                    'name' => 'Destino privado',
                    'url' => $url,
                    'events' => [Webhook::EVENT_ORDER_PAID],
                    'is_active' => true,
                ],
            )->assertUnprocessable()->assertJsonValidationErrors('url');
        }
    }

    public function test_a_user_cannot_manage_another_users_webhooks(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $store = $this->createStore($owner);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/stores/{$store->id}/webhooks")
            ->assertNotFound();
    }

    public function test_order_event_creates_one_delivery_and_dispatches_a_job(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $store = $this->createStore($user);
        $webhook = $store->webhooks()->create([
            'name' => 'Vendas',
            'url' => 'https://example.com/webhooks',
            'token' => 'secret-token',
            'events' => [Webhook::EVENT_ORDER_PAID],
            'is_active' => true,
        ]);
        $order = $store->orders()->create([
            'customer_name' => 'Marina Lopes',
            'customer_email' => 'marina@example.com',
            'amount' => 129.90,
            'payment_method' => 'pix',
            'status' => 'pending',
        ]);
        $order->items()->create([
            'name' => 'Curso completo',
            'qty' => 1,
            'unit_price' => 129.90,
        ]);

        $service = app(WebhookService::class);
        $this->assertSame(1, $service->dispatchOrderEvent($order, Webhook::EVENT_ORDER_PAID));
        $this->assertSame(0, $service->dispatchOrderEvent($order, Webhook::EVENT_ORDER_PAID));

        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame($webhook->id, $delivery->webhook_id);
        $this->assertSame(12990, $delivery->payload['commission']['totalPriceInCents']);
        $this->assertSame('Marina Lopes', $delivery->payload['customer']['name']);
        $this->assertCount(1, $delivery->payload['products']);
        Bus::assertDispatched(DeliverWebhook::class, 1);
    }

    public function test_delivery_sends_bearer_token_and_marks_success(): void
    {
        Http::fake(['https://example.com/*' => Http::response('', 204)]);
        $user = User::factory()->create();
        $store = $this->createStore($user);
        $webhook = $store->webhooks()->create([
            'name' => 'Destino',
            'url' => 'https://example.com/hooks/checkout',
            'token' => 'bearer-secret',
            'events' => [Webhook::EVENT_ORDER_CREATED],
            'is_active' => true,
        ]);
        $delivery = $webhook->deliveries()->create([
            'store_id' => $store->id,
            'event_type' => Webhook::EVENT_ORDER_CREATED,
            'resource_type' => 'order',
            'resource_id' => 10,
            'payload' => ['eventType' => Webhook::EVENT_ORDER_CREATED, 'orderId' => '10'],
        ]);

        (new DeliverWebhook($delivery->id))->handle();

        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempt_count);
        Http::assertSent(fn (Request $request) => $request->url() === $webhook->url
            && $request->hasHeader('Authorization', 'Bearer bearer-secret')
            && $request['eventType'] === Webhook::EVENT_ORDER_CREATED);
    }

    public function test_scheduler_emits_cart_abandoned_only_after_fifteen_minutes(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-08-17 10:00:00');
        $user = User::factory()->create();
        $store = $this->createStore($user);
        $store->webhooks()->create([
            'name' => 'Recuperação',
            'url' => 'https://example.com/cart',
            'token' => 'secret',
            'events' => [Webhook::EVENT_CART_ABANDONED],
            'is_active' => true,
        ]);

        Carbon::setTestNow('2026-08-17 10:01:00');
        AbandonedCart::create([
            'store_id' => $store->id,
            'customer_name' => 'Rafael Lima',
            'customer_email' => 'rafael@example.com',
            'items' => [],
            'subtotal' => 0,
            'total' => 0,
            'step_reached' => AbandonedCart::STEP_DADOS,
            'status' => AbandonedCart::STATUS_OPEN,
            'last_activity_at' => now(),
        ]);

        Carbon::setTestNow('2026-08-17 10:14:00');
        $this->assertSame(0, app(WebhookService::class)->dispatchScheduledEvents());

        Carbon::setTestNow('2026-08-17 10:17:00');
        $this->assertSame(1, app(WebhookService::class)->dispatchScheduledEvents());
        $this->assertDatabaseHas('webhook_deliveries', [
            'event_type' => Webhook::EVENT_CART_ABANDONED,
            'resource_type' => 'cart',
        ]);

        Carbon::setTestNow();
    }

    private function createStore(User $user): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Webhook',
            'subdomain' => 'loja-webhook-'.strtolower(fake()->unique()->lexify('????')),
        ]);
    }
}
