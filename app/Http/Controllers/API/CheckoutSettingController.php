<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class CheckoutSettingController extends Controller
{
    public function show(string $storeId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $settings = $store->checkoutSettings()->firstOrCreate([]);
        
        return response()->json($settings);
    }

    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $settings = $store->checkoutSettings()->firstOrCreate([]);

        $validated = $request->validate([
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'logo_url' => 'nullable|string|url',
            'banner_url' => 'nullable|string|url',
            'banner_height' => 'nullable|string|in:sm,md,lg',
            'enable_order_bump' => 'boolean',
            'dark_mode' => 'boolean',
            'button_text' => 'nullable|string|max:50',
            'banner_message' => 'nullable|string|max:255',
            'header_store_name_visible' => 'boolean',
            'header_secure_badge' => 'boolean',
            'header_logo_alignment' => 'nullable|string|in:left,center,right',
            'header_bg_color' => 'nullable|string|max:20',
            'header_icon_color' => 'nullable|string|max:20',
            'announcement_bar_enabled' => 'boolean',
            'announcement_bar_bg' => 'nullable|string|max:7',
            'announcement_bar_text_color' => 'nullable|string|max:7',
            'summary_title' => 'nullable|string|max:100',
            'summary_show_discount' => 'boolean',
            'summary_coupon_enabled' => 'boolean',
            'step_title_font_size' => 'nullable|string|max:10',
            'scarcity_enabled' => 'boolean',
            'scarcity_type' => 'nullable|string|in:countdown,stock,visitors',
            'scarcity_text' => 'nullable|string|max:255',
            'scarcity_countdown_minutes' => 'nullable|integer|min:1|max:999',
            'pix_confirmation_title' => 'nullable|string|max:100',
            'pix_confirmation_message' => 'nullable|string|max:500',
            'pix_confirmation_logo' => 'nullable|string|url',
            'footer_text' => 'nullable|string|max:255',
            'footer_show_cnpj' => 'boolean',
            'footer_cnpj' => 'nullable|string|max:20',
            'font_family' => 'nullable|string|max:50',
            'font_size_base' => 'nullable|string|max:10',
        ]);

        $settings->update($validated);

        return response()->json($settings);
    }
}
