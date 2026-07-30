<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtmifySetting extends Model
{
    protected $fillable = [
        'store_id',
        'api_token',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Indica se a integração está pronta para enviar pedidos.
     */
    public function isActive(): bool
    {
        return $this->enabled && !empty($this->api_token);
    }
}