<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\MetaConversionsApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MetaConversionController extends Controller
{
    public function event(Request $request, MetaConversionsApiService $service)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'event_name' => 'required|in:PageView,ViewContent,AddToCart,InitiateCheckout,AddPaymentInfo,Purchase',
            'event_id' => 'required|string|max:180',
            'event_time' => 'nullable|integer|min:1',
            'event_source_url' => 'nullable|url|max:2000',
            'consent' => 'nullable|boolean',
            'user_data' => 'nullable|array',
            'user_data.email' => 'nullable|email|max:255',
            'user_data.phone' => 'nullable|string|max:30',
            'user_data.name' => 'nullable|string|max:180',
            'user_data.first_name' => 'nullable|string|max:100',
            'user_data.last_name' => 'nullable|string|max:100',
            'user_data.city' => 'nullable|string|max:120',
            'user_data.state' => 'nullable|string|max:10',
            'user_data.zip' => 'nullable|string|max:20',
            'user_data.country' => 'nullable|string|max:3',
            'user_data.external_id' => 'nullable|string|max:255',
            'fbp' => 'nullable|string|max:255',
            'fbc' => 'nullable|string|max:255',
            'custom_data' => 'nullable|array',
            'custom_data.value' => 'nullable|numeric|min:0',
            'custom_data.currency' => 'nullable|string|size:3',
            'custom_data.content_ids' => 'nullable|array',
            'custom_data.contents' => 'nullable|array',
            'custom_data.contents.*.id' => 'nullable|string|max:100',
            'custom_data.contents.*.quantity' => 'nullable|integer|min:1|max:1000',
            'custom_data.contents.*.item_price' => 'nullable|numeric|min:0',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
        $setting = $store?->metaPixelSetting;

        if (!$store || !$setting || !$setting->isCapiActive()) {
            return response()->json(['ok' => true, 'skipped' => true], 202);
        }

        if ($setting->require_consent && !($validated['consent'] ?? false)) {
            return response()->json(['ok' => true, 'skipped' => true], 202);
        }

        $event = $validated;
        $event['client_ip_address'] = $request->ip();
        $event['client_user_agent'] = Str::limit($request->userAgent() ?? '', 500, '');

        $service->dispatch(
            $store,
            $validated['event_name'],
            $validated['event_id'],
            $event,
            null,
            $validated['event_time'] ?? now()->timestamp,
        );

        return response()->json(['ok' => true], 202);
    }
}
