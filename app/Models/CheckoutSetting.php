<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutSetting extends Model
{
    protected $fillable = [
        'store_id',
        'primary_color',
        'secondary_color',
        'logo_url',
        'banner_url',
        'enable_order_bump',
        'dark_mode',
        'button_text',
    ];

    protected $casts = [
        'enable_order_bump' => 'boolean',
        'dark_mode' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
