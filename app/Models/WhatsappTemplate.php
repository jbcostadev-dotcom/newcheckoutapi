<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    public const EVENT_PAYMENT_PENDING = 'payment_pending';
    public const EVENT_PAYMENT_APPROVED = 'payment_approved';
    public const EVENT_PAYMENT_REFUSED = 'payment_refused';
    public const EVENT_PIX_UNPAID = 'pix_unpaid';
    public const EVENT_PIX_EXPIRED = 'pix_expired';
    public const EVENT_CART_ABANDONED = 'cart_abandoned';

    public const EVENTS = [
        self::EVENT_PAYMENT_PENDING,
        self::EVENT_PAYMENT_APPROVED,
        self::EVENT_PAYMENT_REFUSED,
        self::EVENT_PIX_UNPAID,
        self::EVENT_PIX_EXPIRED,
        self::EVENT_CART_ABANDONED,
    ];

    protected $fillable = [
        'store_id',
        'event',
        'name',
        'message',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function logs()
    {
        return $this->hasMany(WhatsappLog::class);
    }
}