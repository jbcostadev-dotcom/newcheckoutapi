<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\CheckoutUrlGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        if ($request->query('view') === 'grouped') {
            return $this->groupedIndex($request, $store);
        }

        return response()->json($store->products);
    }

    /**
     * Retorna uma pagina de produtos-pai com apenas os campos necessarios para
     * a listagem. Variantes Shopify permanecem juntas na mesma pagina.
     */
    private function groupedIndex(Request $request, Store $store)
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $search = trim(mb_substr((string) $request->query('search', ''), 0, 100));

        $applySearch = function ($query) use ($search) {
            if ($search === '') {
                return $query;
            }

            $like = '%'.addcslashes($search, '\\%_').'%';

            return $query->where(function (Builder $query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('parent_title', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('attributes', 'like', $like);
            });
        };

        // MySQL com ONLY_FULL_GROUP_BY nao aceita usar `id` dentro da expressao
        // de agrupamento de produtos manuais. Separamos os dois formatos de
        // grupo e os unimos antes de paginar.
        $shopifyGroups = $applySearch(
            $store->products()->getQuery()
                ->whereNotNull('shopify_product_id')
                ->where('shopify_product_id', '!=', '')
        )
            ->select('shopify_product_id')
            ->selectRaw('NULL AS manual_product_id')
            ->selectRaw('MAX(id) AS representative_id')
            ->selectRaw('MAX(updated_at) AS latest_updated_at')
            ->groupBy('shopify_product_id');

        $manualGroups = $applySearch(
            $store->products()->getQuery()->where(function (Builder $query) {
                $query->whereNull('shopify_product_id')
                    ->orWhere('shopify_product_id', '');
            })
        )
            ->selectRaw('NULL AS shopify_product_id')
            ->selectRaw('id AS manual_product_id')
            ->selectRaw('id AS representative_id')
            ->selectRaw('updated_at AS latest_updated_at');

        $groupQuery = DB::query()
            ->fromSub($shopifyGroups->unionAll($manualGroups), 'catalog_groups')
            ->select([
                'shopify_product_id',
                'manual_product_id',
                'representative_id',
                'latest_updated_at',
            ])
            ->orderByDesc('latest_updated_at')
            ->orderByDesc('representative_id');

        $groups = $groupQuery->paginate($perPage);
        $groupRows = collect($groups->items());

        $shopifyProductIds = $groupRows
            ->pluck('shopify_product_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (string) $id)
            ->values();
        $manualProductIds = $groupRows
            ->pluck('manual_product_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->values();

        $products = collect();
        if ($shopifyProductIds->isNotEmpty() || $manualProductIds->isNotEmpty()) {
            $products = $store->products()
                ->select([
                    'id',
                    'store_id',
                    'shopify_product_id',
                    'shopify_variant_id',
                    'name',
                    'parent_title',
                    'attributes',
                    'price',
                    'compare_at_price',
                    'stock_quantity',
                    'image_url',
                    'is_active',
                ])
                ->selectRaw("CASE WHEN shopify_product_id IS NULL OR shopify_product_id = '' THEN SUBSTR(description, 1, 180) ELSE NULL END AS description_excerpt")
                ->where(function (Builder $query) use ($shopifyProductIds, $manualProductIds) {
                    if ($shopifyProductIds->isNotEmpty()) {
                        $query->whereIn('shopify_product_id', $shopifyProductIds);
                    }

                    if ($manualProductIds->isNotEmpty()) {
                        $method = $shopifyProductIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('id', $manualProductIds);
                    }
                })
                ->orderBy('id')
                ->get();
        }

        $manualProducts = $products->keyBy('id');
        $shopifyProducts = $products
            ->filter(fn ($product) => $product->shopify_product_id !== null && $product->shopify_product_id !== '')
            ->groupBy(fn ($product) => (string) $product->shopify_product_id);

        $data = $groupRows->map(function ($group) use ($manualProducts, $shopifyProducts) {
            if ($group->manual_product_id !== null) {
                $product = $manualProducts->get((int) $group->manual_product_id);

                return $product ? [
                    'kind' => 'plain',
                    'group_key' => 'manual:'.$product->id,
                    'product' => $product,
                ] : null;
            }

            $shopifyProductId = (string) $group->shopify_product_id;
            $variants = $shopifyProducts->get($shopifyProductId, collect())->values();
            $representative = $variants->first();
            $image = $variants->first(fn ($variant) => ! empty($variant->image_url))?->image_url;

            if (! $representative) {
                return null;
            }

            return [
                'kind' => 'shopify',
                'group_key' => 'shopify:'.$shopifyProductId,
                'shopify_product_id' => $shopifyProductId,
                'parent_title' => $representative->parent_title ?: $representative->name,
                'image_url' => $image,
                'variants' => $variants,
            ];
        })->filter()->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
            ],
        ]);
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
