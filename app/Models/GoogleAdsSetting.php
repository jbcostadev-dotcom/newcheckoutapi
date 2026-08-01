<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleAdsSetting extends Model
{
    protected $fillable = [
        'store_id',
        'pixel_name',
        'pixel_id',
        'conversion_label',
        'only_paid_sales',
        'only_selected_products',
        'selected_product_ids',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'only_paid_sales' => 'boolean',
        'only_selected_products' => 'boolean',
        'selected_product_ids' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isActive(): bool
    {
        return $this->enabled && !empty($this->pixel_id);
    }
}