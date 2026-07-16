<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'store_id', 'customer_name', 'customer_email',
        'customer_phone', 'customer_document', 'amount', 'payment_method',
        'status', 'gateway_transaction_id', 'pix_qrcode', 'pix_copia_cola',
        'shipping_cep', 'shipping_logradouro', 'shipping_numero',
        'shipping_complemento', 'shipping_bairro', 'shipping_cidade', 'shipping_uf',
        'shipping_method_id', 'shipping_price',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'shipping_price' => 'decimal:2',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
