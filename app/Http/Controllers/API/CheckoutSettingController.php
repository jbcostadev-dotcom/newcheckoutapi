<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class CheckoutSettingController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(string $storeId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $settings = $store->checkoutSettings()->firstOrCreate([]);
        
        return response()->json($settings);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $settings = $store->checkoutSettings()->firstOrCreate([]);

        $validated = $request->validate([
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'logo_url' => 'nullable|string|url',
            'banner_url' => 'nullable|string|url',
            'enable_order_bump' => 'boolean',
            'dark_mode' => 'boolean',
            'button_text' => 'nullable|string|max:50',
            'banner_message' => 'nullable|string|max:255',
        ]);

        $settings->update($validated);

        return response()->json($settings);
    }
}
