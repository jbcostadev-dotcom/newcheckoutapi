<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\TaboolaConversionEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Sends Taboola's documented S2S postback while keeping browser credentials private. */
class TaboolaPostbackService
{
    private const ALLOWED_EVENTS = [
        'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase',
    ];

    private const EVENT_FIELDS = [
        'PageView' => 'page_view_event_name',
        'ViewContent' => 'view_content_event_name',
        'AddToCart' => 'add_to_cart_event_name',
        'InitiateCheckout' => 'initiate_checkout_event_name',
        'AddPaymentInfo' => 'add_payment_info_event_name',
        'Purchase' => 'purchase_event_name',
    ];

    public function dispatch(
        Store $store,
        string $semanticEvent,
        string $eventId,
        array $event = [],
        ?Order $order = null,
        ?int $eventTime = null,
    ): bool {
        $setting = $store->taboolaPixelSetting;
        if (!$setting || !$setting->isS2sActive() || !in_array($semanticEvent, self::ALLOWED_EVENTS, true)) return false;

        $clickId = trim((string) ($event['click_id'] ?? data_get($order?->tracking_parameters, 'tblci', '')));
        if ($clickId === '' || trim($eventId) === '') return false;
        if ($setting->require_consent && !($event['consent'] ?? data_get($order?->tracking_parameters, 'taboola_consent', false))) return false;
        if ($semanticEvent === 'Purchase') {
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
        $record = TaboolaConversionEvent::firstOrCreate(
            ['store_id' => $store->id, 'event_name' => $semanticEvent, 'event_id' => $eventId],
            ['order_id' => $order?->id, 'event_time' => $eventTime, 'status' => 'pending']
        );
        if ($record->status === 'sent') return true;

        $custom = (array) ($event['custom_data'] ?? []);
        if ($order) {
            $custom = array_merge($custom, [
                'value' => (float) $order->amount,
                'currency' => $custom['currency'] ?? 'BRL',
                'order_id' => (string) $order->id,
                'quantity' => (int) $order->items->sum('qty'),
            ]);
        }
        $nameField = self::EVENT_FIELDS[$semanticEvent];
        $fallbackNames = [
            'PageView' => 'page_view', 'ViewContent' => 'PRODUCT_VIEW', 'AddToCart' => 'ADD_TO_CART',
            'InitiateCheckout' => 'CHECKOUT', 'AddPaymentInfo' => 'ADD_PAYMENT_INFO', 'Purchase' => 'PURCHASE',
        ];
        $name = trim((string) ($setting->{$nameField} ?: ($fallbackNames[$semanticEvent] ?? strtoupper($semanticEvent))));
        $query = [
            'click-id' => Str::limit($clickId, 500, ''),
            'name' => Str::limit($name, 80, ''),
        ];
        foreach ([
            'revenue' => $custom['value'] ?? null,
            'currency' => $custom['currency'] ?? null,
            'quantity' => $custom['quantity'] ?? null,
            'orderid' => $custom['order_id'] ?? null,
        ] as $key => $value) {
            if ($value !== null && $value !== '') $query[$key] = $value;
        }

        try {
            $response = Http::retry(2, 200)
                ->timeout((int) config('services.taboola.timeout', 8))
                ->acceptJson()
                ->get($setting->postbackEndpoint(), $query);
            $accepted = $response->successful();
            $record->update([
                'status' => $accepted ? 'sent' : 'failed',
                'response_code' => $response->status(),
                'error_message' => $accepted ? null : Str::limit($response->body(), 2000),
            ]);
            if (!$accepted) Log::warning('Taboola postback failed', [
                'store_id' => $store->id, 'event_name' => $semanticEvent, 'event_id' => $eventId,
                'status' => $response->status(),
            ]);
            return $accepted;
        } catch (\Throwable $exception) {
            $record->update(['status' => 'failed', 'error_message' => Str::limit($exception->getMessage(), 2000)]);
            Log::warning('Taboola postback exception', [
                'store_id' => $store->id, 'event_name' => $semanticEvent, 'event_id' => $eventId,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    public function dispatchPurchase(Order $order): bool
    {
        $order->loadMissing(['store.taboolaPixelSetting', 'items.product']);
        $tracking = (array) ($order->tracking_parameters ?? []);
        $event = [
            'click_id' => $tracking['tblci'] ?? $tracking['taboola_click_id'] ?? null,
            'consent' => $tracking['taboola_consent'] ?? false,
            'custom_data' => [
                'value' => (float) $order->amount,
                'currency' => 'BRL',
                'order_id' => (string) $order->id,
                'quantity' => (int) $order->items->sum('qty'),
                'content_ids' => $order->items->pluck('product_id')->map(fn ($id) => (string) $id)->values()->all(),
            ],
        ];
        return $this->dispatch($order->store, 'Purchase', 'purchase_'.$order->id, $event, $order, $order->updated_at?->timestamp);
    }

    private function hasProductFilter($setting): bool { return count($this->selectedProductIds($setting)) > 0; }

    private function selectedProductIds($setting): array
    {
        return is_array($setting->selected_product_ids) ? array_map('intval', $setting->selected_product_ids) : [];
    }
}
