<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\CheckoutUrlGenerator;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        return response()->json($store->products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
            'compare_at_price' => 'nullable|numeric',
            'image_url' => 'nullable|string|url',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'sku' => 'nullable|string|max:255',
            'barcode' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'weight_unit' => 'nullable|string|max:20',
            'grams' => 'nullable|integer',
            'height' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'dimension_unit' => 'nullable|string|max:20',
            'product_type' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'taxable' => 'nullable|boolean',
            'requires_shipping' => 'nullable|boolean',
            'inventory_policy' => 'nullable|string|max:255',
            'fulfillment_service' => 'nullable|string|max:255',
            'inventory_item_id' => 'nullable|string|max:255',
            'position' => 'nullable|integer',
            'tax_code' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validated['image_url'] = url('storage/'.$path);
        }

        $product = $store->products()->create($validated);

        // Gera o link direto de checkout pós-create (precisa do ID).
        $product->update([
            'checkout_url' => app(CheckoutUrlGenerator::class)->generate($store, (int) $product->id),
        ]);

        return response()->json($product->fresh(), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $storeId, string $productId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $product = $store->products()->findOrFail($productId);

        return response()->json($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $storeId, string $productId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $product = $store->products()->findOrFail($productId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric',
            'compare_at_price' => 'nullable|numeric',
            'image_url' => 'nullable|string|url',
            'is_active' => 'boolean',
            'sku' => 'nullable|string|max:255',
            'barcode' => 'nullable|string|max:255',
            'weight' => 'nullable|numeric',
            'weight_unit' => 'nullable|string|max:20',
            'grams' => 'nullable|integer',
            'height' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'dimension_unit' => 'nullable|string|max:20',
            'product_type' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'taxable' => 'nullable|boolean',
            'requires_shipping' => 'nullable|boolean',
            'inventory_policy' => 'nullable|string|max:255',
            'fulfillment_service' => 'nullable|string|max:255',
            'inventory_item_id' => 'nullable|string|max:255',
            'position' => 'nullable|integer',
            'tax_code' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric',
        ]);

        $product->update($validated);

        return response()->json($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $storeId, string $productId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $product = $store->products()->findOrFail($productId);

        $product->delete();

        return response()->json(null, 204);
    }
}
