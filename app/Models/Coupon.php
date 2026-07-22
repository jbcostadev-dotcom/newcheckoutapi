<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'store_id',
        'code',
        'name',
        'description',
        'status',
        'max_uses',
        'used_count',
        'discount_value',
        'discount_type',
        'auto_apply',
        'first_purchase_only',
        'accumulate_with_promos',
        'free_shipping',
        'shipping_method_id',
        'min_purchase_value',
        'min_items_required',
        'min_items_quantity',
        'starts_at',
        'expires_at',
        'applies_to_all_products',
    ];

    protected $casts = [
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'discount_value' => 'decimal:2',
        'min_purchase_value' => 'decimal:2',
        'min_items_quantity' => 'integer',
        'auto_apply' => 'boolean',
        'first_purchase_only' => 'boolean',
        'accumulate_with_promos' => 'boolean',
        'free_shipping' => 'boolean',
        'min_items_required' => 'boolean',
        'applies_to_all_products' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_product');
    }

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
