<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ShopifyCollectionController extends Controller
{
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $search = trim(mb_substr((string) $request->query('search', ''), 0, 100));

        $query = $store->shopifyCollections()
            ->select([
                'id',
                'store_id',
                'shopify_collection_id',
                'shopify_graphql_id',
                'title',
                'handle',
                'image_url',
                'products_count',
                'sort_order',
                'shopify_updated_at',
                'last_synced_at',
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(function (Builder $query) use ($like) {
                    $query->where('title', 'like', $like)
                        ->orWhere('handle', 'like', $like);
                });
            })
            ->orderByDesc('shopify_updated_at')
            ->orderByDesc('id');

        $collections = $query->paginate($perPage);

        return response()->json([
            'data' => $collections->items(),
            'meta' => [
                'current_page' => $collections->currentPage(),
                'last_page' => $collections->lastPage(),
                'per_page' => $collections->perPage(),
                'total' => $collections->total(),
            ],
        ]);
    }
}
