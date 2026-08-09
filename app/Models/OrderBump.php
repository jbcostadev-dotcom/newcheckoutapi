<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderBump extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'product_id',
        'discount_value',
        'discount_type',
        'scope',
        'target_product_id',
        'show_credit_card',
        'show_pix',
        'show_boleto',
        'offer_title',
        'offer_message',
        'button_label',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'show_credit_card' => 'boolean',
        'show_pix' => 'boolean',
        'show_boleto' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function targetProduct()
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Calcula o preço final do produto oferecido pelo order bump aplicando
     * o desconto configurado (fixo em R$ ou percentual).
     */
    public function calculateDiscountedPrice(): float
    {
        $base = (float) ($this->product?->price ?? 0);
        $value = (float) $this->discount_value;

        if ($this->discount_type === 'percent') {
            $discount = $base * ($value / 100);
        } else {
            $discount = $value;
        }

        return max(0, round($base - $discount, 2));
    }
}
