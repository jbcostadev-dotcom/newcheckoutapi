<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GiftController extends Controller
{
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        return response()->json(
            $store->gifts()
                ->with([
                    'products:id,name,parent_title,attributes,price,image_url,stock_quantity,shopify_product_id',
                    'targetProducts:id,name,parent_title,attributes,image_url,shopify_product_id',
                ])
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $validated = $this->validateGift($request);
        [$productIds, $targetProductIds] = $this->ownedProductIds($store, $validated);

        $gift = $store->gifts()->create($this->giftAttributes($validated));
        $gift->products()->sync($productIds);
        $gift->targetProducts()->sync($targetProductIds);
        $gift->touch();

        return response()->json($this->loadGift($gift), 201);
    }

    public function update(Request $request, string $storeId, string $giftId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $gift = $store->gifts()->findOrFail($giftId);
        $validated = $this->validateGift($request);
        [$productIds, $targetProductIds] = $this->ownedProductIds($store, $validated);

        $gift->update($this->giftAttributes($validated));
        $gift->products()->sync($productIds);
        $gift->targetProducts()->sync($targetProductIds);
        $gift->touch();

        return response()->json($this->loadGift($gift));
    }

    public function destroy(Request $request, string $storeId, string $giftId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $gift = $store->gifts()->findOrFail($giftId);
        $gift->delete();

        return response()->json(null, 204);
    }

    private function validateGift(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer|distinct|exists:products,id',
            'starts_at' => 'required|date',
            'expires_at' => 'required|date|after_or_equal:starts_at',
            'rule_type' => ['required', Rule::in(['always', 'min_quantity', 'min_value'])],
            'min_quantity' => 'nullable|required_if:rule_type,min_quantity|integer|min:1',
            'min_value' => 'nullable|required_if:rule_type,min_value|numeric|min:0.01',
            'scope' => ['required', Rule::in(['any', 'specific'])],
            'target_product_ids' => 'nullable|required_if:scope,specific|array|min:1',
            'target_product_ids.*' => 'integer|distinct|exists:products,id',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function ownedProductIds($store, array $validated): array
    {
        $productIds = $store->products()
            ->whereIn('id', $validated['product_ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (count($productIds) !== count($validated['product_ids'])) {
            abort(response()->json(['error' => 'Um dos produtos do brinde não pertence a esta loja.'], 422));
        }

        $targetProductIds = [];
        if ($validated['scope'] === 'specific') {
            $targetProductIds = $store->products()
                ->whereIn('id', $validated['target_product_ids'] ?? [])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (count($targetProductIds) !== count($validated['target_product_ids'] ?? [])) {
                abort(response()->json(['error' => 'Um dos produtos de escopo não pertence a esta loja.'], 422));
            }
        }

        return [$productIds, $targetProductIds];
    }

    private function giftAttributes(array $validated): array
    {
        return [
            'name' => trim($validated['name']),
            'rule_type' => $validated['rule_type'],
            'min_quantity' => $validated['rule_type'] === 'min_quantity' ? $validated['min_quantity'] : null,
            'min_value' => $validated['rule_type'] === 'min_value' ? $validated['min_value'] : null,
            'scope' => $validated['scope'],
            'starts_at' => Carbon::parse($validated['starts_at'])->startOfDay(),
            'expires_at' => Carbon::parse($validated['expires_at'])->endOfDay(),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    private function loadGift(Gift $gift): Gift
    {
        return $gift->load([
            'products:id,name,parent_title,attributes,price,image_url,stock_quantity,shopify_product_id',
            'targetProducts:id,name,parent_title,attributes,image_url,shopify_product_id',
        ]);
    }
}
