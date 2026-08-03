<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\TikTokConversionEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TikTokEventsApiService
{
    private const ALLOWED_EVENTS = [
        'PageView',
        'ViewContent',
        'AddToCart',
        'InitiateCheckout',
        'AddPaymentInfo',
        'Purchase',
    ];

    /**
     * Envia um evento web para a TikTok Events API. O payload não é persistido;
     * apenas o estado operacional e a chave de idempotência são registrados.
     */
    public function dispatch(
        Store $store,
        string $eventName,
        string $eventId,
        array $event = [],
        ?Order $order = null,
        ?int $eventTime = null,
    ): bool {
        $setting = $store->tiktokPixelSetting;
        if (!$setting || !$setting->isEventsApiActive()) {
            return false;
        }

        if ($setting->require_consent
            && !($event['consent'] ?? data_get($order?->tracking_parameters, 'tiktok_consent', false))) {
            return false;
        }

        if (!in_array($eventName, self::ALLOWED_EVENTS, true) || trim($eventId) === '') {
            return false;
        }

        if ($eventName === 'Purchase') {
            if (!$order || !in_array($order->status, [Order::STATUS_PAID, Order::STATUS_AUTHORIZED], true)) {
                return false;
            }
            if ($setting->only_paid_sales && !$order->isPaid()) {
                return false;
            }
        }

        if ($setting->only_selected_products && $this->hasProductFilter($setting)) {
            $productIds = $this->productIds($event, $order);
            if (empty(array_intersect($productIds, $this->selectedProductIds($setting)))) {
                return false;
            }
        }

        $eventId = Str::limit(trim($eventId), 180, '');
        $eventTime = $eventTime ?? now()->timestamp;
        $event['custom_data'] = array_merge(
            (array) ($event['custom_data'] ?? []),
            ['shop_id' => (string) $store->id]
        );

        $record = TikTokConversionEvent::firstOrCreate(
            [
                'store_id' => $store->id,
                'event_name' => $eventName,
                'event_id' => $eventId,
            ],
            [
                'order_id' => $order?->id,
                'event_time' => $eventTime,
                'status' => 'pending',
            ]
        );

        if ($record->status === 'sent') {
            return true;
        }

        $payload = [
            'event_source' => 'web',
            'event_source_id' => (string) $setting->pixel_code,
            'data' => [[
                'event' => $eventName,
                'event_time' => $eventTime,
                'event_id' => $eventId,
                'user' => $this->buildUserData($event, $order),
                'page' => $this->buildPage($event),
                'properties' => $this->buildProperties($event, $order),
            ]],
        ];

        $testEventCode = trim((string) ($setting->test_event_code ?? ''));
        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        try {
            $response = Http::retry(2, 200)
                ->timeout((int) config('services.tiktok.timeout', 8))
                ->withHeaders(['Access-Token' => $setting->access_token])
                ->acceptJson()
                ->post(
                    rtrim((string) config('services.tiktok.events_api_url', 'https://business-api.tiktok.com/open_api/v1.3/event/track/'), '/').'/',
                    $payload
                );

            $accepted = $response->successful() && (int) $response->json('code', -1) === 0;
            $record->update([
                'status' => $accepted ? 'sent' : 'failed',
                'response_code' => $response->status(),
                'error_message' => $accepted ? null : Str::limit($response->body(), 2000),
            ]);

            if (!$accepted) {
                Log::warning('TikTok Events API request failed', [
                    'store_id' => $store->id,
                    'event_name' => $eventName,
                    'event_id' => $eventId,
                    'status' => $response->status(),
                    'api_code' => $response->json('code'),
                ]);
            }

            return $accepted;
        } catch (\Throwable $exception) {
            $record->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000),
            ]);
            Log::warning('TikTok Events API dispatch failed', [
                'store_id' => $store->id,
                'event_name' => $eventName,
                'event_id' => $eventId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function dispatchPurchase(Order $order, ?string $sourceUrl = null): bool
    {
        $order->loadMissing(['store.tiktokPixelSetting', 'items.product']);
        $tracking = (array) ($order->tracking_parameters ?? []);
        $event = [
            'event_source_url' => $sourceUrl ?? ($tracking['landing_page'] ?? null),
            'page_referrer' => $tracking['referrer'] ?? null,
            'ttclid' => $tracking['ttclid'] ?? null,
            'ttp' => $tracking['ttp'] ?? null,
            'client_ip_address' => $tracking['client_ip_address'] ?? null,
            'client_user_agent' => $tracking['client_user_agent'] ?? null,
            'user_data' => [
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'external_id' => 'customer_'.($order->customer_id ?: $order->id),
            ],
            'custom_data' => [
                'currency' => 'BRL',
                'value' => (float) $order->amount,
                'order_id' => (string) $order->id,
                'content_type' => 'product',
                'contents' => $order->items->map(fn ($item) => [
                    'content_id' => (string) $item->product_id,
                    'content_name' => $item->name,
                    'content_category' => $item->product?->product_type ?: $item->product?->parent_title,
                    'brand' => $item->product?->vendor,
                    'sku' => $item->product?->sku,
                    'quantity' => (int) $item->qty,
                    'price' => (float) $item->unit_price,
                ])->values()->all(),
                'content_ids' => $order->items->pluck('product_id')->map(fn ($id) => (string) $id)->values()->all(),
                'num_items' => (int) $order->items->sum('qty'),
                'payment_method' => $order->payment_method,
                'shipping_price' => (float) ($order->shipping_price ?? 0),
                'installments' => (int) ($order->installments ?? 1),
                'upsell_amount' => (float) ($order->upsell_amount ?? 0),
                'coupon' => $tracking['coupon'] ?? null,
                'src' => $tracking['src'] ?? null,
                'sck' => $tracking['sck'] ?? null,
                'utm_source' => $tracking['utm_source'] ?? null,
                'utm_campaign' => $tracking['utm_campaign'] ?? null,
                'utm_medium' => $tracking['utm_medium'] ?? null,
                'utm_content' => $tracking['utm_content'] ?? null,
                'utm_term' => $tracking['utm_term'] ?? null,
            ],
        ];

        return $this->dispatch(
            $order->store,
            'Purchase',
            'purchase_'.$order->id,
            $event,
            $order,
            $order->updated_at?->timestamp ?? now()->timestamp,
        );
    }

    private function buildUserData(array $event, ?Order $order): array
    {
        $tracking = (array) ($order?->tracking_parameters ?? []);
        $raw = array_merge(
            (array) ($event['user_data'] ?? []),
            $order ? [
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'external_id' => 'customer_'.($order->customer_id ?: $order->id),
            ] : []
        );

        $user = [];
        foreach (['email' => 'email', 'phone' => 'phone', 'external_id' => 'external_id'] as $source => $target) {
            $normalized = $this->normalize($source, $raw[$source] ?? null);
            if ($normalized !== null) {
                $user[$target] = [hash('sha256', $normalized)];
            }
        }

        foreach (['ttp', 'ttclid'] as $key) {
            $value = $event[$key] ?? ($raw[$key] ?? ($tracking[$key] ?? null));
            if ($value !== null && trim((string) $value) !== '') {
                $user[$key] = Str::limit(trim((string) $value), 500, '');
            }
        }

        $ip = $event['client_ip_address'] ?? ($tracking['client_ip_address'] ?? null);
        $ua = $event['client_user_agent'] ?? ($tracking['client_user_agent'] ?? null);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) $user['ip'] = $ip;
        if ($ua) $user['user_agent'] = Str::limit((string) $ua, 500, '');

        return $user;
    }

    private function buildPage(array $event): array
    {
        $url = $this->sourceUrl($event);
        $page = ['url' => $url];
        $referrer = $event['page_referrer'] ?? $event['referrer'] ?? null;
        if (is_string($referrer) && filter_var($referrer, FILTER_VALIDATE_URL)) {
            $page['referrer'] = Str::limit($referrer, 2000, '');
        }
        return $page;
    }

    private function buildProperties(array $event, ?Order $order): array
    {
        $custom = (array) ($event['custom_data'] ?? []);
        if ($order) {
            $custom = array_merge($custom, [
                'currency' => 'BRL',
                'value' => (float) $order->amount,
                'order_id' => (string) $order->id,
            ]);
        }

        $allowed = [
            'currency', 'value', 'order_id', 'shop_id', 'content_id', 'content_type', 'content_ids', 'contents',
            'num_items', 'description', 'payment_method', 'shipping_price',
            'installments', 'upsell_amount', 'quantity', 'coupon', 'event_channel',
            'src', 'sck', 'utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term',
        ];
        $properties = array_intersect_key($custom, array_flip($allowed));
        $properties = array_filter($properties, fn ($value) => $value !== null);
        if (isset($properties['contents']) && is_array($properties['contents'])) {
            $properties['contents'] = $this->normalizeContents($properties['contents']);
        }
        if (isset($properties['content_ids']) && is_array($properties['content_ids'])) {
            $properties['content_ids'] = array_values(array_map('strval', $properties['content_ids']));
        }
        return $properties;
    }

    private function normalizeContents(array $contents): array
    {
        return array_values(array_filter(array_map(function ($item) {
            if (!is_array($item)) return null;
            $id = $item['content_id'] ?? $item['id'] ?? null;
            if ($id === null || trim((string) $id) === '') return null;
            return array_filter([
                'content_id' => (string) $id,
                'content_name' => isset($item['content_name']) ? Str::limit((string) $item['content_name'], 255, '') : null,
                'content_category' => isset($item['content_category']) ? Str::limit((string) $item['content_category'], 255, '') : null,
                'content_type' => 'product',
                'brand' => isset($item['brand']) ? Str::limit((string) $item['brand'], 255, '') : null,
                'sku' => isset($item['sku']) ? Str::limit((string) $item['sku'], 100, '') : null,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price' => isset($item['price']) ? (float) $item['price'] : (isset($item['item_price']) ? (float) $item['item_price'] : null),
            ], fn ($value) => $value !== null);
        }, $contents)));
    }

    private function sourceUrl(array $event): string
    {
        $url = $event['event_source_url'] ?? $event['landing_page'] ?? null;
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return Str::limit($url, 2000, '');
        }
        return rtrim((string) config('app.url', 'https://localhost'), '/').'/';
    }

    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $value = mb_strtolower(trim((string) $value));
        if ($field === 'email') return $value;
        if ($field === 'phone') return preg_replace('/\D+/', '', $value) ?: null;
        return preg_replace('/\s+/', ' ', $value) ?: null;
    }

    private function hasProductFilter($setting): bool
    {
        return count($this->selectedProductIds($setting)) > 0;
    }

    private function selectedProductIds($setting): array
    {
        return is_array($setting->selected_product_ids)
            ? array_map('intval', $setting->selected_product_ids)
            : [];
    }

    private function productIds(array $event, ?Order $order): array
    {
        if ($order) return $order->items->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        return collect($event['custom_data']['content_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
