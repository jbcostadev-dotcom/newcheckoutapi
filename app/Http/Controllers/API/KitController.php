<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Kit;
use App\Services\CheckoutUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KitController extends Controller
{
    public function index(Request $request, string $storeId, CheckoutUrlGenerator $urlGenerator)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $kits = $store->kits()
            ->with('products:id,store_id,name,parent_title,attributes,price,image_url,is_active')
            ->latest()
            ->get()
            ->map(fn (Kit $kit) => $this->present($kit, $urlGenerator));

        return response()->json($kits);
    }

    public function show(
        Request $request,
        string $storeId,
        string $kitId,
        CheckoutUrlGenerator $urlGenerator,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $kit = $store->kits()
            ->with('products:id,store_id,name,parent_title,attributes,price,image_url,is_active')
            ->findOrFail($kitId);

        return response()->json($this->present($kit, $urlGenerator));
    }

    public function store(
        Request $request,
        string $storeId,
        CheckoutUrlGenerator $urlGenerator,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $validated = $this->validateKit($request);
        $this->ensureProductsBelongToStore($store, $validated['products']);

        $kit = DB::transaction(function () use ($store, $validated) {
            $kit = $store->kits()->create([
                'name' => $validated['name'],
                'is_active' => $validated['is_active'],
            ]);

            $kit->products()->attach($this->pivotPayload($validated['products']));

            return $kit;
        });

        $kit->load('products:id,store_id,name,parent_title,attributes,price,image_url,is_active');

        return response()->json($this->present($kit, $urlGenerator), 201);
    }

    public function update(
        Request $request,
        string $storeId,
        string $kitId,
        CheckoutUrlGenerator $urlGenerator,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $kit = $store->kits()->findOrFail($kitId);
        $validated = $this->validateKit($request);
        $this->ensureProductsBelongToStore($store, $validated['products']);

        DB::transaction(function () use ($kit, $validated) {
            $kit->update([
                'name' => $validated['name'],
                'is_active' => $validated['is_active'],
            ]);
            $kit->products()->sync($this->pivotPayload($validated['products']));
        });

        $kit->load('products:id,store_id,name,parent_title,attributes,price,image_url,is_active');

        return response()->json($this->present($kit, $urlGenerator));
    }

    public function destroy(Request $request, string $storeId, string $kitId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $kit = $store->kits()->findOrFail($kitId);
        $kit->delete();

        return response()->json(null, 204);
    }

    private function validateKit(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'products' => 'required|array|min:1|max:100',
            'products.*.product_id' => 'required|integer|distinct|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        if (collect($validated['products'])->sum('quantity') > 100) {
            throw ValidationException::withMessages([
                'products' => ['O kit pode ter no máximo 100 itens no total.'],
            ]);
        }

        return $validated;
    }

    private function ensureProductsBelongToStore($store, array $products): void
    {
        $productIds = collect($products)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $ownedCount = $store->products()->whereIn('id', $productIds)->count();

        if ($ownedCount !== $productIds->count()) {
            throw ValidationException::withMessages([
                'products' => ['Um ou mais produtos não pertencem à loja selecionada.'],
            ]);
        }
    }

    private function pivotPayload(array $products): array
    {
        return collect($products)->mapWithKeys(fn (array $item) => [
            (int) $item['product_id'] => ['quantity' => (int) $item['quantity']],
        ])->all();
    }

    private function present(Kit $kit, CheckoutUrlGenerator $urlGenerator): Kit
    {
        $productIds = $kit->products->flatMap(function ($product) {
            return array_fill(0, (int) $product->pivot->quantity, (int) $product->id);
        })->values()->all();

        $kit->setAttribute('products_count', $kit->products->count());
        $kit->setAttribute('items_count', count($productIds));
        $kit->setAttribute('subtotal', $kit->products->sum(
            fn ($product) => (float) $product->price * (int) $product->pivot->quantity,
        ));
        $kit->setAttribute(
            'checkout_url',
            empty($productIds) ? null : $urlGenerator->generateForCart($kit->store, $productIds),
        );

        return $kit;
    }
}
