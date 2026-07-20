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
        'banner_height',
        'enable_order_bump',
        'dark_mode',
        'button_text',
        'banner_message',
        'header_store_name_visible',
        'header_secure_badge',
        'header_logo_alignment',
        'header_bg_color',
        'header_icon_color',
        'announcement_bar_enabled',
        'announcement_bar_bg',
        'announcement_bar_text_color',
        'summary_title',
        'summary_show_discount',
        'summary_coupon_enabled',
        'step_title_font_size',
        'scarcity_enabled',
        'scarcity_type',
        'scarcity_text',
        'scarcity_countdown_minutes',
        'pix_confirmation_title',
        'pix_confirmation_message',
        'pix_confirmation_logo',
        'footer_text',
        'footer_show_cnpj',
        'footer_cnpj',
        'font_family',
        'font_size_base',
        'social_proofs_enabled',
        'pix_enabled',
        'pix_gateway_id',
        'card_enabled',
        'card_gateway_id',
        'boleto_enabled',
        'boleto_gateway_id',
        'default_payment_method',
    ];

    protected $casts = [
        'enable_order_bump' => 'boolean',
        'dark_mode' => 'boolean',
        'header_store_name_visible' => 'boolean',
        'header_secure_badge' => 'boolean',
        'announcement_bar_enabled' => 'boolean',
        'summary_show_discount' => 'boolean',
        'summary_coupon_enabled' => 'boolean',
        'scarcity_enabled' => 'boolean',
        'scarcity_countdown_minutes' => 'integer',
        'footer_show_cnpj' => 'boolean',
        'social_proofs_enabled' => 'boolean',
        'pix_enabled' => 'boolean',
        'card_enabled' => 'boolean',
        'boleto_enabled' => 'boolean',
        'pix_gateway_id' => 'integer',
        'card_gateway_id' => 'integer',
        'boleto_gateway_id' => 'integer',
        'default_payment_method' => 'string',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
