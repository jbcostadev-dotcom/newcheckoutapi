<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;

class ShippingMethodController extends Controller
{
    /**
     * Listar métodos de frete da loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $shippingMethods = $store->shippingMethods()->latest()->get();

        return response()->json($shippingMethods);
    }

    /**
     * Criar novo método de frete.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'min_value_free_shipping' => 'nullable|numeric|min:0',
            'min_delivery_days' => 'nullable|integer|min:0',
            'max_delivery_days' => 'nullable|integer|min:0',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $shippingMethod = $store->shippingMethods()->create($validated);

        return response()->json($shippingMethod, 201);
    }

    /**
     * Atualizar método de frete.
     */
    public function update(Request $request, string $storeId, string $shippingMethodId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $shippingMethod = $store->shippingMethods()->findOrFail($shippingMethodId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'min_value_free_shipping' => 'nullable|numeric|min:0',
            'min_delivery_days' => 'nullable|integer|min:0',
            'max_delivery_days' => 'nullable|integer|min:0',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $shippingMethod->update($validated);

        return response()->json($shippingMethod);
    }

    /**
     * Remover método de frete.
     */
    public function destroy(Request $request, string $storeId, string $shippingMethodId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $shippingMethod = $store->shippingMethods()->findOrFail($shippingMethodId);
        $shippingMethod->delete();

        return response()->json(null, 204);
    }
}
