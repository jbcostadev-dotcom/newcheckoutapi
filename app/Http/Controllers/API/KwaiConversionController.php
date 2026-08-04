<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\KwaiEventsApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KwaiConversionController extends Controller
{
    public function event(Request $request, KwaiEventsApiService $service)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain', 'domain' => 'nullable|string|max:255|required_without:store_id',
            'event' => 'required|in:PageView,ViewContent,AddToCart,InitiateCheckout,AddPaymentInfo,Purchase',
            'event_id' => 'required|string|max:180', 'event_time' => 'nullable|integer|min:1',
            'event_source_url' => 'nullable|url|max:2000', 'page_referrer' => 'nullable|url|max:2000',
            'consent' => 'nullable|boolean', 'click_id' => 'nullable|string|max:500',
            'user_data' => 'nullable|array', 'user_data.email' => 'nullable|email|max:255',
            'user_data.phone' => 'nullable|string|max:30', 'user_data.external_id' => 'nullable|string|max:255',
            'custom_data' => 'nullable|array', 'custom_data.value' => 'nullable|numeric|min:0',
            'custom_data.currency' => 'nullable|string|size:3', 'custom_data.content_ids' => 'nullable|array',
            'custom_data.contents' => 'nullable|array', 'custom_data.contents.*.content_id' => 'nullable|string|max:100',
            'custom_data.contents.*.content_name' => 'nullable|string|max:255', 'custom_data.contents.*.content_category' => 'nullable|string|max:255',
            'custom_data.contents.*.brand' => 'nullable|string|max:255', 'custom_data.contents.*.sku' => 'nullable|string|max:100',
            'custom_data.contents.*.quantity' => 'nullable|integer|min:1|max:1000', 'custom_data.contents.*.price' => 'nullable|numeric|min:0',
            'custom_data.order_id' => 'nullable|string|max:180', 'custom_data.payment_method' => 'nullable|string|max:50',
            'custom_data.installments' => 'nullable|integer|min:1|max:100', 'custom_data.shipping_price' => 'nullable|numeric|min:0',
            'custom_data.coupon' => 'nullable|string|max:120', 'custom_data.utm_source' => 'nullable|string|max:500',
            'custom_data.utm_campaign' => 'nullable|string|max:500', 'custom_data.utm_medium' => 'nullable|string|max:500',
            'custom_data.utm_content' => 'nullable|string|max:500', 'custom_data.utm_term' => 'nullable|string|max:500',
        ]);
        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
        $setting = $store?->kwaiPixelSetting;
        if (!$store || !$setting || !$setting->isEventsApiActive() || ($setting->require_consent && !($validated['consent'] ?? false))) {
            return response()->json(['ok' => true, 'skipped' => true], 202);
        }
        $event = $validated;
        $event['client_ip_address'] = $request->ip();
        $event['client_user_agent'] = Str::limit($request->userAgent() ?? '', 500, '');
        $service->dispatch($store, $validated['event'], $validated['event_id'], $event, null, $validated['event_time'] ?? now()->timestamp);
        return response()->json(['ok' => true], 202);
    }
}
