<?php

namespace App\Services;

use App\Models\MetaConversionEvent;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaConversionsApiService
{
    private const ALLOWED_EVENTS = [
        'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout',
        'AddPaymentInfo', 'Purchase',
    ];

    /**
     * Envia um evento ao endpoint /events da Meta. O payload nunca é persistido
     * para evitar deixar dados pessoais em logs; apenas o resultado operacional
     * e a chave de idempotência ficam registrados.
     */
    public function dispatch(
        Store $store,
        string $eventName,
        string $eventId,
        array $event = [],
        ?Order $order = null,
        ?int $eventTime = null,
    ): bool {
        $setting = $store->metaPixelSetting;
        if (!$setting || !$setting->isCapiActive()) {
            return false;
        }

        if ($setting->require_consent
            && !($event['consent'] ?? data_get($order?->tracking_parameters, 'meta_consent', false))) {
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

        $record = MetaConversionEvent::firstOrCreate(
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
            'data' => [[
                'event_name' => $eventName,
                'event_time' => $eventTime,
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => $this->sourceUrl($event),
                'user_data' => $this->buildUserData($event, $order),
                'custom_data' => $this->buildCustomData($event, $order),
            ]],
        ];

        $testEventCode = trim((string) ($setting->test_event_code ?? ''));
        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        try {
            $url = sprintf(
                '%s/%s/%s/events',
                rtrim((string) config('services.meta.graph_url', 'https://graph.facebook.com'), '/'),
                trim((string) config('services.meta.graph_api_version', 'v23.0'), '/'),
                rawurlencode((string) $setting->pixel_id)
            );

            $response = Http::timeout((int) config('services.meta.timeout', 8))
                ->acceptJson()
                ->post($url, array_merge($payload, [
                    'access_token' => $setting->access_token,
                ]));

            $record->update([
                'status' => $response->successful() ? 'sent' : 'failed',
                'response_code' => $response->status(),
                'error_message' => $response->successful() ? null : Str::limit($response->body(), 2000),
            ]);

            if (!$response->successful()) {
                Log::warning('Meta CAPI request failed', [
                    'store_id' => $store->id,
                    'event_name' => $eventName,
                    'event_id' => $eventId,
                    'status' => $response->status(),
                ]);
            }

            return $response->successful();
        } catch (\Throwable $exception) {
            $record->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000),
            ]);
            Log::warning('Meta CAPI dispatch failed', [
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
        $order->loadMissing(['store.metaPixelSetting', 'items']);
        $event = [
            'event_source_url' => $sourceUrl ?? data_get($order->tracking_parameters, 'landing_page'),
            'client_ip_address' => data_get($order->tracking_parameters, 'client_ip_address'),
            'client_user_agent' => data_get($order->tracking_parameters, 'client_user_agent'),
            'fbp' => data_get($order->tracking_parameters, 'fbp'),
            'fbc' => data_get($order->tracking_parameters, 'fbc'),
            'user_data' => [
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'name' => $order->customer_name,
                'document' => $order->customer_document,
                'city' => $order->shipping_cidade,
                'state' => $order->shipping_uf,
                'zip' => $order->shipping_cep,
                'country' => 'br',
                'external_id' => 'customer_'.$order->customer_id,
            ],
            'custom_data' => [
                'currency' => 'BRL',
                'value' => (float) $order->amount,
                'order_id' => (string) $order->id,
                'content_type' => 'product',
                'contents' => $order->items->map(fn ($item) => [
                    'id' => (string) $item->product_id,
                    'quantity' => (int) $item->qty,
                    'item_price' => (float) $item->unit_price,
                ])->values()->all(),
                'content_ids' => $order->items->pluck('product_id')->map(fn ($id) => (string) $id)->values()->all(),
                'num_items' => (int) $order->items->sum('qty'),
                'payment_method' => $order->payment_method,
                'shipping_price' => (float) ($order->shipping_price ?? 0),
                'installments' => (int) ($order->installments ?? 1),
                'upsell_amount' => (float) ($order->upsell_amount ?? 0),
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
        $raw = array_merge(
            (array) ($event['user_data'] ?? []),
            $order ? [
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'name' => $order->customer_name,
                'city' => $order->shipping_cidade,
                'state' => $order->shipping_uf,
                'zip' => $order->shipping_cep,
                'country' => 'br',
                'external_id' => 'customer_'.$order->customer_id,
            ] : []
        );

        $parts = preg_split('/\s+/', trim((string) ($raw['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $firstName = $raw['first_name'] ?? ($parts[0] ?? null);
        $lastName = $raw['last_name'] ?? (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null);

        $userData = [];
        foreach ([
            'email' => 'em', 'phone' => 'ph', 'external_id' => 'external_id',
            'first_name' => 'fn', 'last_name' => 'ln', 'city' => 'ct',
            'state' => 'st', 'zip' => 'zp', 'country' => 'country',
        ] as $source => $target) {
            $value = $source === 'first_name' ? $firstName : ($source === 'last_name' ? $lastName : ($raw[$source] ?? null));
            $normalized = $this->normalize($source, $value);
            if ($normalized !== null) {
                $userData[$target] = [hash('sha256', $normalized)];
            }
        }

        foreach (['fbp', 'fbc', 'client_ip_address', 'client_user_agent'] as $key) {
            $value = $event[$key] ?? data_get($event, 'user_data.'.$key);
            if ($value !== null && trim((string) $value) !== '') {
                $userData[$key] = trim((string) $value);
            }
        }

        return $userData;
    }

    private function buildCustomData(array $event, ?Order $order): array
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
            'currency', 'value', 'order_id', 'content_type', 'content_name',
            'content_ids', 'contents', 'num_items', 'delivery_category',
            'payment_method', 'shipping_price', 'installments', 'upsell_amount',
        ];
        return array_intersect_key($custom, array_flip($allowed));
    }

    private function sourceUrl(array $event): ?string
    {
        $url = $event['event_source_url'] ?? null;
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? Str::limit($url, 2000, '') : null;
    }

    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $value = mb_strtolower(trim((string) $value));
        if ($field === 'email') return $value;
        if ($field === 'phone' || $field === 'zip') return preg_replace('/\D+/', '', $value) ?: null;
        if ($field === 'country') return substr($value, 0, 2);
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
        return collect($event['custom_data']['content_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
    }
}
