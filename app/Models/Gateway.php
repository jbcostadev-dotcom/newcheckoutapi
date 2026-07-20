<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gateway extends Model
{
    protected $fillable = [
        'store_id',
        'provider',
        'api_key',
        'secret_key',
        'is_active',
        'settings',
        'installment_type',
        'default_installment_rate',
        'installment_rates',
        'pre_selected_installment',
        'installment_limit',
        'interest_free_installments',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'default_installment_rate' => 'float',
        'installment_rates' => 'array',
        'pre_selected_installment' => 'integer',
        'installment_limit' => 'integer',
        'interest_free_installments' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Scope: apenas gateways ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
