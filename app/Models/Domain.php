<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $fillable = [
        'store_id',
        'domain',
        'is_primary',
        'ssl_active',
        'status',
        'dns_verified_at',
        'ssl_status',
        'verification_token',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'ssl_active' => 'boolean',
        'dns_verified_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Verifica se o DNS já foi validado.
     */
    public function isDnsVerified(): bool
    {
        return !is_null($this->dns_verified_at);
    }

    /**
     * Verifica se o SSL está ativo.
     */
    public function isSslActive(): bool
    {
        return $this->ssl_active && $this->ssl_status === 'active';
    }
}
