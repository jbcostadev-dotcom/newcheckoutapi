<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyCollection extends Model
{
    protected $fillable = [
        'store_id',
        'shopify_collection_id',
        'shopify_graphql_id',
        'title',
        'handle',
        'description',
        'image_url',
        'products_count',
        'sort_order',
        'shopify_updated_at',
        'last_synced_at',
    ];

    protected $casts = [
        'products_count' => 'integer',
        'shopify_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
