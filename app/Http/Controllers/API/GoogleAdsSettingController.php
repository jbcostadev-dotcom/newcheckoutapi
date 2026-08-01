<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoogleAdsSettingController extends Controller
{
    /**
     * Retorna a configuração do Google Ads da loja.
     */
    public function show(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $setting = $store->googleAdsSetting()->first();

        return response()->json([
            'enabled' => (bool) $setting?->enabled,
            'has_pixel' => !empty($setting?->pixel_id),
            'values' => $setting ? $this->valuesToArray($setting) : $this->defaultValues(),
        ]);
    }

    /**
     * Atualiza a configuração do Google Ads.
     */
    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $rules = [
            'enabled' => 'boolean',
            'pixel_name' => 'nullable|string|max:255',
            'pixel_id' => 'nullable|string|max:100',
            'conversion_label' => 'nullable|string|max:100',
            'only_paid_sales' => 'boolean',
            'only_selected_products' => 'boolean',
            'selected_product_ids' => 'nullable|array',
            'selected_product_ids.*' => 'integer',
            'clear_pixel' => 'sometimes|boolean',
        ];

        $validated = $request->validate($rules);

        $setting = $store->googleAdsSetting()->firstOrCreate(
            [],
            $this->defaultModelValues()
        );

        $update = [];

        foreach (['enabled', 'pixel_name', 'only_paid_sales', 'only_selected_products'] as $key) {
            if (array_key_exists($key, $validated)) {
                $update[$key] = $validated[$key];
            }
        }

        if (array_key_exists('pixel_id', $validated)) {
            if (($validated['clear_pixel'] ?? false) || trim($validated['pixel_id']) === '') {
                $update['pixel_id'] = null;
            } else {
                $update['pixel_id'] = trim($validated['pixel_id']);
            }
        }

        if (array_key_exists('conversion_label', $validated)) {
            $label = trim($validated['conversion_label']);
            $update['conversion_label'] = $label !== '' ? $label : null;
        }

        if (array_key_exists('selected_product_ids', $validated)) {
            $ids = is_array($validated['selected_product_ids'])
                ? array_values(array_unique(array_map('intval', $validated['selected_product_ids'])))
                : [];
            $update['selected_product_ids'] = $ids;
        }

        if (!empty($update)) {
            $setting->update($update);
        }

        $fresh = $setting->fresh();

        return response()->json([
            'enabled' => (bool) $fresh->enabled,
            'has_pixel' => !empty($fresh->pixel_id),
            'values' => $this->valuesToArray($fresh),
        ]);
    }

    private function defaultValues(): array
    {
        return [
            'pixel_name' => '',
            'pixel_id' => '',
            'conversion_label' => '',
            'only_paid_sales' => true,
            'only_selected_products' => false,
            'selected_product_ids' => [],
        ];
    }

    private function defaultModelValues(): array
    {
        return [
            'pixel_name' => null,
            'pixel_id' => null,
            'conversion_label' => null,
            'only_paid_sales' => true,
            'only_selected_products' => false,
            'selected_product_ids' => null,
            'enabled' => false,
        ];
    }

    private function valuesToArray($setting): array
    {
        $ids = is_array($setting->selected_product_ids)
            ? array_values(array_unique(array_map('intval', $setting->selected_product_ids)))
            : [];

        return [
            'pixel_name' => $setting->pixel_name ?? '',
            'pixel_id' => $setting->pixel_id ?? '',
            'conversion_label' => $setting->conversion_label ?? '',
            'only_paid_sales' => (bool) $setting->only_paid_sales,
            'only_selected_products' => (bool) $setting->only_selected_products,
            'selected_product_ids' => $ids,
        ];
    }
}