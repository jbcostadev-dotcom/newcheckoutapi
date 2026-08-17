<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'shopify_product_id',
        'shopify_variant_id',
        'name',
        'parent_title',
        'attributes',
        'sku',
        'barcode',
        'weight',
        'weight_unit',
        'grams',
        'height',
        'width',
        'length',
        'dimension_unit',
        'product_type',
        'vendor',
        'tags',
        'taxable',
        'requires_shipping',
        'inventory_policy',
        'fulfillment_service',
        'inventory_item_id',
        'position',
        'tax_code',
        'cost',
        'description',
        'price',
        'compare_at_price',
        'stock_quantity',
        'image_url',
        'checkout_url',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight' => 'decimal:3',
        'height' => 'decimal:3',
        'width' => 'decimal:3',
        'length' => 'decimal:3',
        'stock_quantity' => 'integer',
        'grams' => 'integer',
        'position' => 'integer',
        'taxable' => 'boolean',
        'requires_shipping' => 'boolean',
        'is_active' => 'boolean',
        'attributes' => 'array',
        'tags' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function kits()
    {
        return $this->belongsToMany(Kit::class, 'kit_product')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
