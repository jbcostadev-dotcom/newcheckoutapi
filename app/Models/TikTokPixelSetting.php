<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokPixelSetting extends Model
{
    protected $table = 'tiktok_pixel_settings';

    protected $fillable = [
        'store_id',
        'pixel_name',
        'pixel_code',
        'access_token',
        'test_event_code',
        'browser_enabled',
        'events_api_enabled',
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
        'events_api_enabled' => 'boolean',
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
        return $this->enabled && $this->browser_enabled && !empty($this->pixel_code);
    }

    public function isEventsApiActive(): bool
    {
        return $this->enabled
            && $this->events_api_enabled
            && !empty($this->pixel_code)
            && !empty($this->access_token);
    }

    public function isActive(): bool
    {
        return $this->isBrowserActive() || $this->isEventsApiActive();
    }
}
