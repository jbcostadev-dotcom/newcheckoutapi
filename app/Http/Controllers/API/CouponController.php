<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    /**
     * Listar cupons da loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $coupons = $store->coupons()
            ->withCount('products')
            ->latest()
            ->get();

        return response()->json($coupons);
    }

    /**
     * Criar novo cupom.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $this->validateCoupon($request, $store);

        if (! empty($validated['auto_apply'])) {
            $conflict = $store->coupons()
                ->where('status', 'active')
                ->where('auto_apply', true)
                ->exists();

            if ($conflict) {
                return response()->json([
                    'error' => 'Já existe um cupom com aplicação automática ativa. Desative-o antes de ativar este.',
                ], 422);
            }
        }

        $coupon = $store->coupons()->create($validated);

        if (! $coupon->applies_to_all_products && ! empty($validated['product_ids'])) {
            $this->attachProducts($store, $coupon, $validated['product_ids']);
        }

        $coupon->load(['products:id,name,price,image_url']);

        return response()->json($coupon, 201);
    }

    /**
     * Atualizar cupom.
     */
    public function update(Request $request, string $storeId, string $couponId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $coupon = $store->coupons()->findOrFail($couponId);

        $validated = $this->validateCoupon($request, $store, $coupon->id);

        if (! empty($validated['auto_apply']) && $coupon->status !== 'active') {
            $validated['status'] = 'active';
        }

        if (! empty($validated['auto_apply'])) {
            $conflict = $store->coupons()
                ->where('status', 'active')
                ->where('auto_apply', true)
                ->where('id', '!=', $coupon->id)
                ->exists();

            if ($conflict) {
                return response()->json([
                    'error' => 'Já existe um cupom com aplicação automática ativa. Desative-o antes de ativar este.',
                ], 422);
            }
        }

        $coupon->update($validated);

        if (isset($validated['product_ids'])) {
            if ($coupon->applies_to_all_products) {
                $coupon->products()->detach();
            } else {
                $this->attachProducts($store, $coupon, $validated['product_ids']);
            }
        }

        $coupon->load(['products:id,name,price,image_url']);

        return response()->json($coupon);
    }

    /**
     * Remover cupom.
     */
    public function destroy(Request $request, string $storeId, string $couponId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $coupon = $store->coupons()->findOrFail($couponId);
        $coupon->delete();

        return response()->json(null, 204);
    }

    /**
     * Regras de validação comuns.
     */
    private function validateCoupon(Request $request, $store, ?int $ignoreId = null): array
    {
        $rules = [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('coupons')->where(function ($query) use ($store, $ignoreId) {
                    $query->where('store_id', $store->id);
                    if ($ignoreId) {
                        $query->where('id', '!=', $ignoreId);
                    }
                    return $query;
                }),
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'status' => 'required|in:active,inactive',
            'max_uses' => 'required|integer|min:0',
            'discount_value' => 'required|numeric|min:0',
            'discount_type' => 'required|in:fixed,percent',
            'auto_apply' => 'boolean',
            'first_purchase_only' => 'boolean',
            'accumulate_with_promos' => 'boolean',
            'free_shipping' => 'boolean',
            'min_purchase_value' => 'nullable|numeric|min:0',
            'min_items_required' => 'boolean',
            'min_items_quantity' => 'nullable|integer|min:1',
            'starts_at' => 'required|date',
            'expires_at' => 'required|date|after:starts_at',
            'applies_to_all_products' => 'boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ];

        $validated = $request->validate($rules);

        $validated['auto_apply'] = $request->boolean('auto_apply');
        $validated['first_purchase_only'] = $request->boolean('first_purchase_only');
        $validated['accumulate_with_promos'] = $request->boolean('accumulate_with_promos');
        $validated['free_shipping'] = $request->boolean('free_shipping');
        $validated['min_items_required'] = $request->boolean('min_items_required');
        $validated['applies_to_all_products'] = $request->boolean('applies_to_all_products', true);

        if ($validated['discount_type'] === 'percent') {
            $validated['discount_value'] = min(100, $validated['discount_value']);
        }

        if ($validated['applies_to_all_products']) {
            $validated['product_ids'] = [];
        }

        return $validated;
    }

    /**
     * Vincula produtos permitidos ao cupom, garantindo que pertençam à loja.
     */
    private function attachProducts($store, Coupon $coupon, array $productIds): void
    {
        $allowedIds = $store->products()
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->toArray();

        $coupon->products()->sync($allowedIds);
    }
}
