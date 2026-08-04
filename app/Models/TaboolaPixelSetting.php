<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaboolaPixelSetting extends Model
{
    protected $fillable = [
        'store_id', 'pixel_name', 'account_id', 'postback_url', 'browser_enabled',
        's2s_enabled', 'only_paid_sales', 'only_selected_products', 'selected_product_ids',
        'require_consent', 'page_view_event_name', 'view_content_event_name',
        'add_to_cart_event_name', 'initiate_checkout_event_name', 'add_payment_info_event_name',
        'purchase_event_name', 'enabled',
    ];

    protected $casts = [
        'postback_url' => 'encrypted',
        'browser_enabled' => 'boolean',
        's2s_enabled' => 'boolean',
        'only_paid_sales' => 'boolean',
        'only_selected_products' => 'boolean',
        'selected_product_ids' => 'array',
        'require_consent' => 'boolean',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['postback_url'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function postbackEndpoint(): string
    {
        return trim((string) ($this->postback_url ?: config('services.taboola.postback_url')));
    }

    public function isBrowserActive(): bool
    {
        return $this->enabled && $this->browser_enabled && filled($this->account_id);
    }

    public function isS2sActive(): bool
    {
        return $this->enabled && $this->s2s_enabled && filled($this->account_id) && filled($this->postbackEndpoint());
    }

    public function isActive(): bool
    {
        return $this->isBrowserActive() || $this->isS2sActive();
    }
}
