<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'email',
        'phone',
        'document',
        'zip',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'uf',
        'shopify_customer_id',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Indica se o cliente já teve algum pedido pago.
     */
    public function hasPaidOrder(): bool
    {
        return $this->orders()
            ->where('status', Order::STATUS_PAID)
            ->exists();
    }

    /**
     * Total já pago por este cliente (apenas pedidos pagos).
     */
    public function paidTotal(): float
    {
        return (float) $this->orders()
            ->where('status', Order::STATUS_PAID)
            ->sum('amount');
    }

    /**
     * Quantidade de pedidos pagos.
     */
    public function paidOrdersCount(): int
    {
        return (int) $this->orders()
            ->where('status', Order::STATUS_PAID)
            ->count();
    }
}