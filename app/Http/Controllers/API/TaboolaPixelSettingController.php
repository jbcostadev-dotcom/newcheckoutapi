<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TaboolaPixelSettingController extends Controller
{
    public function show(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $setting = $store->taboolaPixelSetting()->first();

        return response()->json([
            'enabled' => (bool) $setting?->enabled,
            'has_account_id' => filled($setting?->account_id),
            'has_postback_url' => filled($setting?->postback_url),
            'values' => $setting ? $this->valuesToArray($setting) : $this->defaultValues(),
        ]);
    }

    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'pixel_name' => 'nullable|string|max:255',
            'account_id' => 'nullable|string|max:100',
            'postback_url' => 'nullable|url|max:2000',
            'browser_enabled' => 'sometimes|boolean',
            's2s_enabled' => 'sometimes|boolean',
            'only_paid_sales' => 'sometimes|boolean',
            'only_selected_products' => 'sometimes|boolean',
            'selected_product_ids' => 'nullable|array',
            'selected_product_ids.*' => 'integer',
            'require_consent' => 'sometimes|boolean',
            'page_view_event_name' => 'nullable|string|max:80',
            'view_content_event_name' => 'nullable|string|max:80',
            'add_to_cart_event_name' => 'nullable|string|max:80',
            'initiate_checkout_event_name' => 'nullable|string|max:80',
            'add_payment_info_event_name' => 'nullable|string|max:80',
            'purchase_event_name' => 'nullable|string|max:80',
            'clear_account_id' => 'sometimes|boolean',
            'clear_postback_url' => 'sometimes|boolean',
        ]);
        $setting = $store->taboolaPixelSetting()->firstOrCreate([], $this->defaultModelValues());
        $update = [];
        foreach (['enabled', 'browser_enabled', 's2s_enabled', 'only_paid_sales', 'only_selected_products', 'require_consent'] as $key) {
            if (array_key_exists($key, $validated)) $update[$key] = (bool) $validated[$key];
        }
        foreach (['pixel_name', 'page_view_event_name', 'view_content_event_name', 'add_to_cart_event_name', 'initiate_checkout_event_name', 'add_payment_info_event_name', 'purchase_event_name'] as $key) {
            if (array_key_exists($key, $validated)) $update[$key] = $validated[$key] === null ? null : trim((string) $validated[$key]);
        }
        foreach ([['account_id', 'clear_account_id'], ['postback_url', 'clear_postback_url']] as [$key, $clearKey]) {
            if (($validated[$clearKey] ?? false) || (array_key_exists($key, $validated) && trim((string) $validated[$key]) === '')) {
                $update[$key] = null;
            } elseif (array_key_exists($key, $validated)) {
                $update[$key] = trim((string) $validated[$key]);
            }
        }
        if (array_key_exists('selected_product_ids', $validated)) {
            $update['selected_product_ids'] = array_values(array_unique(array_map('intval', $validated['selected_product_ids'] ?? [])));
        }
        $effective = array_merge($setting->toArray(), $update);
        $enabled = (bool) ($effective['enabled'] ?? false);
        $hasAccount = filled($effective['account_id'] ?? null);
        $browserOn = (bool) ($effective['browser_enabled'] ?? true);
        $s2sOn = (bool) ($effective['s2s_enabled'] ?? true);
        if ($enabled && (($browserOn || $s2sOn) && !$hasAccount)) {
            return response()->json(['message' => 'Informe o Account ID do Taboola para ativar a integração.'], 422);
        }
        if (!empty($update)) $setting->update($update);
        return response()->json($this->responseFor($setting->fresh()));
    }

    private function defaultValues(): array
    {
        return [
            'pixel_name' => '', 'account_id' => '', 'browser_enabled' => true, 's2s_enabled' => true,
            'only_paid_sales' => true, 'only_selected_products' => false, 'selected_product_ids' => [],
            'require_consent' => false, 'page_view_event_name' => 'page_view',
            'view_content_event_name' => 'PRODUCT_VIEW', 'add_to_cart_event_name' => 'ADD_TO_CART',
            'initiate_checkout_event_name' => 'CHECKOUT', 'add_payment_info_event_name' => 'ADD_PAYMENT_INFO',
            'purchase_event_name' => 'PURCHASE',
        ];
    }

    private function defaultModelValues(): array
    {
        return array_merge($this->defaultValues(), [
            'account_id' => null, 'postback_url' => null, 'enabled' => false, 'selected_product_ids' => null,
        ]);
    }

    private function valuesToArray($setting): array
    {
        return array_merge($this->defaultValues(), [
            'pixel_name' => $setting->pixel_name ?? '', 'account_id' => $setting->account_id ?? '',
            'browser_enabled' => (bool) $setting->browser_enabled, 's2s_enabled' => (bool) $setting->s2s_enabled,
            'only_paid_sales' => (bool) $setting->only_paid_sales, 'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => is_array($setting->selected_product_ids) ? array_values(array_unique(array_map('intval', $setting->selected_product_ids))) : [],
            'require_consent' => (bool) $setting->require_consent,
            'page_view_event_name' => $setting->page_view_event_name ?: 'page_view',
            'view_content_event_name' => $setting->view_content_event_name ?: 'PRODUCT_VIEW',
            'add_to_cart_event_name' => $setting->add_to_cart_event_name ?: 'ADD_TO_CART',
            'initiate_checkout_event_name' => $setting->initiate_checkout_event_name ?: 'CHECKOUT',
            'add_payment_info_event_name' => $setting->add_payment_info_event_name ?: 'ADD_PAYMENT_INFO',
            'purchase_event_name' => $setting->purchase_event_name ?: 'PURCHASE',
        ]);
    }

    private function responseFor($setting): array
    {
        return [
            'enabled' => (bool) $setting->enabled,
            'has_account_id' => filled($setting->account_id),
            'has_postback_url' => filled($setting->postback_url),
            'values' => $this->valuesToArray($setting),
        ];
    }
}
