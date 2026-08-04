<?php

namespace App\Services;

use App\Models\KwaiConversionEvent;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side Kwai adapter.
 *
 * Kwai enables the endpoint and payload for server events per account/region.
 * The URL is therefore deliberately configured through KWAI_EVENTS_API_URL;
 * no undocumented endpoint is hard-coded into the application.
 */
class KwaiEventsApiService
{
    private const ALLOWED_EVENTS = [
        'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase',
    ];

    public function dispatch(
        Store $store,
        string $eventName,
        string $eventId,
        array $event = [],
        ?Order $order = null,
        ?int $eventTime = null,
    ): bool {
        $setting = $store->kwaiPixelSetting;
        if (!$setting || !$setting->isEventsApiActive()) return false;
        if (!in_array($eventName, self::ALLOWED_EVENTS, true) || trim($eventId) === '') return false;
        if ($setting->require_consent && !($event['consent'] ?? data_get($order?->tracking_parameters, 'kwai_consent', false))) {
            return false;
        }
        if ($eventName === 'Purchase') {
            if (!$order || !in_array($order->status, [Order::STATUS_PAID, Order::STATUS_AUTHORIZED], true)) return false;
            if ($setting->only_paid_sales && !$order->isPaid()) return false;
        }
        if ($setting->only_selected_products && $this->hasProductFilter($setting)) {
            $ids = $order
                ? $order->items->pluck('product_id')->map(fn ($id) => (int) $id)->all()
                : collect(data_get($event, 'custom_data.content_ids', []))->map(fn ($id) => (int) $id)->all();
            if (!array_intersect($ids, $this->selectedProductIds($setting))) return false;
        }

        $eventId = Str::limit(trim($eventId), 180, '');
        $eventTime ??= now()->timestamp;
        $record = KwaiConversionEvent::firstOrCreate(
            ['store_id' => $store->id, 'event_name' => $eventName, 'event_id' => $eventId],
            ['order_id' => $order?->id, 'event_time' => $eventTime, 'status' => 'pending']
        );
        if ($record->status === 'sent') return true;

        $payload = [
            'pixel_id' => (string) $setting->pixel_code,
            'event_name' => $eventName,
            'event_id' => $eventId,
            'event_time' => $eventTime,
            'click_id' => $event['click_id'] ?? data_get($order?->tracking_parameters, 'kwai_click_id'),
            'page' => [
                'url' => $this->sourceUrl($event),
                'referrer' => $event['page_referrer'] ?? $event['referrer'] ?? null,
            ],
            'user' => $this->buildUser($event, $order),
            'properties' => $this->buildProperties($event, $order),
        ];
        $testCode = trim((string) ($setting->test_event_code ?? ''));
        if ($testCode !== '') $payload['test_event_code'] = $testCode;
        $payload = array_filter($payload, fn ($value) => $value !== null && $value !== []);

        try {
            $response = Http::retry(2, 200)
                ->timeout((int) config('services.kwai.timeout', 8))
                ->withToken($setting->access_token)
                ->acceptJson()
                ->post((string) config('services.kwai.events_api_url'), $payload);
            $accepted = $response->successful();
            $record->update([
                'status' => $accepted ? 'sent' : 'failed',
                'response_code' => $response->status(),
                'error_message' => $accepted ? null : Str::limit($response->body(), 2000),
            ]);
            if (!$accepted) Log::warning('Kwai Events API request failed', [
                'store_id' => $store->id, 'event_name' => $eventName, 'event_id' => $eventId,
                'status' => $response->status(),
            ]);
            return $accepted;
        } catch (\Throwable $exception) {
            $record->update(['status' => 'failed', 'error_message' => Str::limit($exception->getMessage(), 2000)]);
            Log::warning('Kwai Events API dispatch failed', [
                'store_id' => $store->id, 'event_name' => $eventName, 'event_id' => $eventId,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    public function dispatchPurchase(Order $order, ?string $sourceUrl = null): bool
    {
        $order->loadMissing(['store.kwaiPixelSetting', 'items.product']);
        $tracking = (array) ($order->tracking_parameters ?? []);
        $event = [
            'event_source_url' => $sourceUrl ?? ($tracking['landing_page'] ?? null),
            'page_referrer' => $tracking['referrer'] ?? null,
            'click_id' => $tracking['kwai_click_id'] ?? null,
            'client_ip_address' => $tracking['client_ip_address'] ?? null,
            'client_user_agent' => $tracking['client_user_agent'] ?? null,
            'consent' => $tracking['kwai_consent'] ?? false,
            'user_data' => [
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'external_id' => 'customer_'.($order->customer_id ?: $order->id),
            ],
            'custom_data' => [
                'currency' => 'BRL', 'value' => (float) $order->amount,
                'order_id' => (string) $order->id, 'content_type' => 'product',
                'content_ids' => $order->items->pluck('product_id')->map(fn ($id) => (string) $id)->values()->all(),
                'contents' => $order->items->map(fn ($item) => [
                    'content_id' => (string) $item->product_id,
                    'content_name' => $item->name,
                    'content_category' => $item->product?->product_type ?: $item->product?->parent_title,
                    'brand' => $item->product?->vendor, 'sku' => $item->product?->sku,
                    'quantity' => (int) $item->qty, 'price' => (float) $item->unit_price,
                ])->values()->all(),
                'num_items' => (int) $order->items->sum('qty'),
                'payment_method' => $order->payment_method,
                'shipping_price' => (float) ($order->shipping_price ?? 0),
                'installments' => (int) ($order->installments ?? 1),
                'coupon' => $tracking['coupon'] ?? null,
                'utm_source' => $tracking['utm_source'] ?? null,
                'utm_campaign' => $tracking['utm_campaign'] ?? null,
                'utm_medium' => $tracking['utm_medium'] ?? null,
                'utm_content' => $tracking['utm_content'] ?? null,
                'utm_term' => $tracking['utm_term'] ?? null,
            ],
        ];
        return $this->dispatch($order->store, 'Purchase', 'purchase_'.$order->id, $event, $order, $order->updated_at?->timestamp);
    }

    private function buildUser(array $event, ?Order $order): array
    {
        $raw = array_merge((array) ($event['user_data'] ?? []), $order ? [
            'email' => $order->customer_email,
            'phone' => $order->customer_phone,
            'external_id' => 'customer_'.($order->customer_id ?: $order->id),
        ] : []);
        $user = [];
        foreach (['email', 'phone', 'external_id'] as $field) {
            $normalized = $this->normalize($field, $raw[$field] ?? null);
            if ($normalized !== null) $user[$field] = hash('sha256', $normalized);
        }
        $ip = $event['client_ip_address'] ?? null;
        $ua = $event['client_user_agent'] ?? null;
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) $user['ip'] = $ip;
        if ($ua) $user['user_agent'] = Str::limit((string) $ua, 500, '');
        return $user;
    }

    private function buildProperties(array $event, ?Order $order): array
    {
        $custom = (array) ($event['custom_data'] ?? []);
        if ($order) $custom = array_merge($custom, [
            'currency' => 'BRL', 'value' => (float) $order->amount, 'order_id' => (string) $order->id,
        ]);
        $allowed = [
            'currency', 'value', 'order_id', 'content_id', 'content_ids', 'content_type', 'contents',
            'num_items', 'quantity', 'description', 'payment_method', 'installments', 'shipping_price',
            'coupon', 'utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term',
        ];
        $properties = array_filter(array_intersect_key($custom, array_flip($allowed)), fn ($value) => $value !== null);
        if (isset($properties['contents']) && is_array($properties['contents'])) {
            $properties['contents'] = array_values(array_filter(array_map(function ($item) {
                if (!is_array($item) || empty($item['content_id'])) return null;
                return array_filter([
                    'content_id' => (string) $item['content_id'],
                    'content_name' => isset($item['content_name']) ? Str::limit((string) $item['content_name'], 255, '') : null,
                    'content_category' => isset($item['content_category']) ? Str::limit((string) $item['content_category'], 255, '') : null,
                    'brand' => isset($item['brand']) ? Str::limit((string) $item['brand'], 255, '') : null,
                    'sku' => isset($item['sku']) ? Str::limit((string) $item['sku'], 100, '') : null,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'price' => isset($item['price']) ? (float) $item['price'] : null,
                ], fn ($value) => $value !== null);
            }, $properties['contents'])));
        }
        return $properties;
    }

    private function sourceUrl(array $event): string
    {
        $url = $event['event_source_url'] ?? $event['landing_page'] ?? null;
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            ? Str::limit($url, 2000, '')
            : rtrim((string) config('app.url', 'https://localhost'), '/').'/';
    }

    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $value = mb_strtolower(trim((string) $value));
        return $field === 'phone' ? (preg_replace('/\D+/', '', $value) ?: null) : (preg_replace('/\s+/', ' ', $value) ?: null);
    }

    private function hasProductFilter($setting): bool { return count($this->selectedProductIds($setting)) > 0; }

    private function selectedProductIds($setting): array
    {
        return is_array($setting->selected_product_ids) ? array_map('intval', $setting->selected_product_ids) : [];
    }
}
