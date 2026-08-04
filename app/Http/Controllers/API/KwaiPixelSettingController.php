<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KwaiPixelSettingController extends Controller
{
    public function show(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $setting = $store->kwaiPixelSetting()->first();
        return response()->json([
            'enabled' => (bool) $setting?->enabled,
            'has_pixel' => filled($setting?->pixel_code),
            'has_access_token' => filled($setting?->access_token),
            'has_test_event_code' => filled($setting?->test_event_code),
            'events_api_available' => filled(config('services.kwai.events_api_url')),
            'values' => $setting ? $this->valuesToArray($setting) : $this->defaultValues(),
        ]);
    }

    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $validated = $request->validate([
            'enabled' => 'sometimes|boolean', 'pixel_name' => 'nullable|string|max:255',
            'pixel_code' => 'nullable|string|max:100', 'access_token' => 'nullable|string|max:2000',
            'test_event_code' => 'nullable|string|max:255', 'browser_enabled' => 'sometimes|boolean',
            'events_api_enabled' => 'sometimes|boolean', 'only_paid_sales' => 'sometimes|boolean',
            'only_selected_products' => 'sometimes|boolean', 'selected_product_ids' => 'nullable|array',
            'selected_product_ids.*' => 'integer', 'require_consent' => 'sometimes|boolean',
            'clear_pixel_code' => 'sometimes|boolean', 'clear_access_token' => 'sometimes|boolean',
            'clear_test_event_code' => 'sometimes|boolean',
        ]);
        $setting = $store->kwaiPixelSetting()->firstOrCreate([], $this->defaultModelValues());
        $update = [];
        foreach (['enabled', 'pixel_name', 'browser_enabled', 'events_api_enabled', 'only_paid_sales', 'only_selected_products', 'require_consent'] as $key) {
            if (array_key_exists($key, $validated)) $update[$key] = $validated[$key];
        }
        if (array_key_exists('pixel_code', $validated)) {
            $code = trim((string) ($validated['pixel_code'] ?? ''));
            $update['pixel_code'] = ($validated['clear_pixel_code'] ?? false) || $code === '' ? null : $code;
        }
        foreach (['access_token', 'test_event_code'] as $credential) {
            $clear = 'clear_'.$credential;
            if (($validated[$clear] ?? false) || (array_key_exists($credential, $validated) && trim((string) $validated[$credential]) === '')) {
                $update[$credential] = null;
            } elseif (array_key_exists($credential, $validated)) {
                $update[$credential] = trim((string) $validated[$credential]);
            }
        }
        if (array_key_exists('selected_product_ids', $validated)) {
            $update['selected_product_ids'] = array_values(array_unique(array_map('intval', $validated['selected_product_ids'] ?? [])));
        }
        $effective = array_merge($setting->toArray(), $update);
        if (($effective['enabled'] ?? false) && ($effective['events_api_enabled'] ?? false)
            && (!filled($effective['pixel_code'] ?? null) || !filled($update['access_token'] ?? $setting->access_token))) {
            return response()->json(['message' => 'Para ativar a API server-side do Kwai, informe o Pixel ID e o Access Token.'], 422);
        }
        if (!empty($update)) $setting->update($update);
        return response()->json($this->responseFor($setting->fresh()));
    }

    private function defaultValues(): array
    {
        return ['pixel_name' => '', 'pixel_code' => '', 'browser_enabled' => true, 'events_api_enabled' => false,
            'only_paid_sales' => true, 'only_selected_products' => false, 'selected_product_ids' => [], 'require_consent' => false];
    }

    private function defaultModelValues(): array
    {
        return ['pixel_name' => null, 'pixel_code' => null, 'access_token' => null, 'test_event_code' => null,
            'browser_enabled' => true, 'events_api_enabled' => false, 'only_paid_sales' => true,
            'only_selected_products' => false, 'selected_product_ids' => null, 'require_consent' => false, 'enabled' => false];
    }

    private function valuesToArray($setting): array
    {
        return ['pixel_name' => $setting->pixel_name ?? '', 'pixel_code' => $setting->pixel_code ?? '',
            'browser_enabled' => (bool) $setting->browser_enabled, 'events_api_enabled' => (bool) $setting->events_api_enabled,
            'only_paid_sales' => (bool) $setting->only_paid_sales, 'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => is_array($setting->selected_product_ids) ? array_values(array_unique(array_map('intval', $setting->selected_product_ids))) : [],
            'require_consent' => (bool) $setting->require_consent];
    }

    private function responseFor($setting): array
    {
        return ['enabled' => (bool) $setting->enabled, 'has_pixel' => filled($setting->pixel_code),
            'has_access_token' => filled($setting->access_token), 'has_test_event_code' => filled($setting->test_event_code),
            'events_api_available' => filled(config('services.kwai.events_api_url')), 'values' => $this->valuesToArray($setting)];
    }
}
