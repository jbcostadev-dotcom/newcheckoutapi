<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'rule_type',
        'min_quantity',
        'min_value',
        'scope',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
        'min_value' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'gift_product');
    }

    public function targetProducts()
    {
        return $this->belongsToMany(Product::class, 'gift_target_product');
    }
}
