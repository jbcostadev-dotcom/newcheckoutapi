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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
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
