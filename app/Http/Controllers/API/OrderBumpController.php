<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OrderBump;
use Illuminate\Http\Request;

class OrderBumpController extends Controller
{
    /**
     * Listar order bumps da loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $bumps = $store->orderBumps()
            ->with(['product:id,name,price,image_url', 'targetProduct:id,name,price'])
            ->latest()
            ->get();

        return response()->json($bumps);
    }

    /**
     * Criar novo order bump.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer|exists:products,id',
            'discount_value' => 'required|numeric|min:0',
            'discount_type' => 'required|in:fixed,percent',
            'scope' => 'required|in:any,specific',
            'target_product_id' => 'nullable|integer|exists:products,id',
            'show_credit_card' => 'boolean',
            'show_pix' => 'boolean',
            'show_boleto' => 'boolean',
            'offer_title' => 'nullable|string|max:255',
            'offer_message' => 'nullable|string|max:2000',
            'bg_color' => 'nullable|string|max:20',
            'border_color' => 'nullable|string|max:20',
            'button_color' => 'nullable|string|max:20',
            'button_text_color' => 'nullable|string|max:20',
            'button_label' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        // Garante que o produto oferecido pertence à mesma loja.
        $ownsProduct = $store->products()->where('id', $validated['product_id'])->exists();
        if (! $ownsProduct) {
            return response()->json(['error' => 'Product does not belong to this store'], 422);
        }

        if (! empty($validated['target_product_id'])) {
            $ownsTarget = $store->products()->where('id', $validated['target_product_id'])->exists();
            if (! $ownsTarget) {
                return response()->json(['error' => 'Target product does not belong to this store'], 422);
            }
        }

        $bump = $store->orderBumps()->create($validated);
        $bump->load(['product:id,name,price,image_url', 'targetProduct:id,name,price']);

        return response()->json($bump, 201);
    }

    /**
     * Atualizar order bump.
     */
    public function update(Request $request, string $storeId, string $orderBumpId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $bump = $store->orderBumps()->findOrFail($orderBumpId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'product_id' => 'sometimes|required|integer|exists:products,id',
            'discount_value' => 'sometimes|required|numeric|min:0',
            'discount_type' => 'sometimes|required|in:fixed,percent',
            'scope' => 'sometimes|required|in:any,specific',
            'target_product_id' => 'nullable|integer|exists:products,id',
            'show_credit_card' => 'boolean',
            'show_pix' => 'boolean',
            'show_boleto' => 'boolean',
            'offer_title' => 'sometimes|nullable|string|max:255',
            'offer_message' => 'sometimes|nullable|string|max:2000',
            'bg_color' => 'sometimes|nullable|string|max:20',
            'border_color' => 'sometimes|nullable|string|max:20',
            'button_color' => 'sometimes|nullable|string|max:20',
            'button_text_color' => 'sometimes|nullable|string|max:20',
            'button_label' => 'sometimes|nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        if (! empty($validated['product_id'])) {
            $ownsProduct = $store->products()->where('id', $validated['product_id'])->exists();
            if (! $ownsProduct) {
                return response()->json(['error' => 'Product does not belong to this store'], 422);
            }
        }

        if (array_key_exists('target_product_id', $validated) && ! empty($validated['target_product_id'])) {
            $ownsTarget = $store->products()->where('id', $validated['target_product_id'])->exists();
            if (! $ownsTarget) {
                return response()->json(['error' => 'Target product does not belong to this store'], 422);
            }
        }

        $bump->update($validated);
        $bump->load(['product:id,name,price,image_url', 'targetProduct:id,name,price']);

        return response()->json($bump);
    }

    /**
     * Remover order bump.
     */
    public function destroy(Request $request, string $storeId, string $orderBumpId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $bump = $store->orderBumps()->findOrFail($orderBumpId);
        $bump->delete();

        return response()->json(null, 204);
    }
}
