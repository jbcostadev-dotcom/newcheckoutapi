<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardPaymentAttempt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'store_id',
        'order_id',
        'customer_email',
        'customer_document',
        'card_fingerprint',
        'card_last4',
        'card_expiry',
        'card_cvv_hash',
        'card_brand',
        'status',
        'error_message',
        'gateway_response',
        'ip_address',
    ];

    protected $casts = [
        'gateway_response' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
