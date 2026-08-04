<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\TaboolaPostbackService;
use Illuminate\Http\Request;

class TaboolaConversionController extends Controller
{
    public function event(Request $request, TaboolaPostbackService $service)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'event' => 'required|in:PageView,ViewContent,AddToCart,InitiateCheckout,AddPaymentInfo,Purchase',
            'event_id' => 'required|string|max:180',
            'event_time' => 'nullable|integer|min:1',
            'consent' => 'nullable|boolean',
            'click_id' => 'nullable|string|max:500',
            'custom_data' => 'nullable|array',
            'custom_data.value' => 'nullable|numeric|min:0',
            'custom_data.currency' => 'nullable|string|size:3',
            'custom_data.quantity' => 'nullable|integer|min:1|max:100000',
            'custom_data.order_id' => 'nullable|string|max:180',
            'custom_data.content_ids' => 'nullable|array',
        ]);
        $store = Store::resolveByIdentifier((string) ($validated['store_id'] ?? $validated['domain']));
        $setting = $store?->taboolaPixelSetting;
        if (!$store || !$setting || !$setting->isS2sActive()) return response()->json(['ok' => true, 'skipped' => true], 202);
        if ($setting->require_consent && !($validated['consent'] ?? false)) return response()->json(['ok' => true, 'skipped' => true], 202);
        $service->dispatch(
            $store,
            $validated['event'],
            $validated['event_id'],
            $validated,
            null,
            $validated['event_time'] ?? now()->timestamp,
        );
        return response()->json(['ok' => true], 202);
    }
}
