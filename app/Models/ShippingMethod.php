<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'price',
        'min_value_free_shipping',
        'min_delivery_days',
        'max_delivery_days',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'min_value_free_shipping' => 'decimal:2',
        'min_delivery_days' => 'integer',
        'max_delivery_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Scope: apenas métodos de frete ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
