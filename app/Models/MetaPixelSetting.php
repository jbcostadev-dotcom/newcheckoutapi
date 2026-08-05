<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

class MetaPixelSetting extends Model
{
    protected $fillable = [
        'store_id',
        'pixel_name',
        'pixel_id',
        'access_token',
        'test_event_code',
        'browser_enabled',
        'capi_enabled',
        'only_paid_sales',
        'only_selected_products',
        'selected_product_ids',
        'require_consent',
        'enabled',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'test_event_code' => 'encrypted',
        'browser_enabled' => 'boolean',
        'capi_enabled' => 'boolean',
        'only_paid_sales' => 'boolean',
        'only_selected_products' => 'boolean',
        'selected_product_ids' => 'array',
        'require_consent' => 'boolean',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['access_token', 'test_event_code'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isBrowserActive(): bool
    {
        return $this->enabled && $this->browser_enabled && !empty($this->pixel_id);
    }

    public function isCapiActive(): bool
    {
        if (! $this->enabled || ! $this->capi_enabled || empty($this->pixel_id)) {
            return false;
        }

        try {
            return ! empty($this->access_token);
        } catch (DecryptException) {
            // Uma APP_KEY alterada torna tokens já salvos indecifráveis. Não
            // deixe uma integração inválida impedir a abertura do checkout.
            return false;
        }
    }

    public function isActive(): bool
    {
        return $this->isBrowserActive() || $this->isCapiActive();
    }
}
