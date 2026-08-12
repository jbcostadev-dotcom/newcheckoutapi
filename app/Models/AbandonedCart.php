<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbandonedCart extends Model
{
    public const STEP_DADOS = 'dados';

    public const STEP_ENTREGA = 'entrega';

    public const STEP_PAGAMENTO = 'pagamento';

    public const STEP_PAGAMENTO_TENTADO = 'pagamento_tentado';

    public const STATUS_OPEN = 'open';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_EXPIRED = 'expired';

    public const REASON_LEFT_DADOS = 'left_dados';

    public const REASON_LEFT_ENTREGA = 'left_entrega';

    public const REASON_LEFT_PAGAMENTO = 'left_pagamento';

    public const REASON_CARD_REFUSED = 'card_refused';

    public const REASON_PIX_EXPIRED = 'pix_expired';

    public const REASON_BOLETO_EXPIRED = 'boleto_expired';

    protected $fillable = [
        'store_id',
        'session_id',
        'customer_id',
        'order_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_document',
        'items',
        'subtotal',
        'total',
        'shipping_address',
        'shipping_method_id',
        'shipping_method_name',
        'shipping_price',
        'step_reached',
        'payment_method',
        'status',
        'abandoned_reason',
        'card_brand',
        'card_last4',
        'recovery_token',
        'recovered_at',
        'expired_at',
        'last_activity_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'device_type',
        'user_agent',
        'ip_address',
    ];

    protected $casts = [
        'items' => 'array',
        'shipping_address' => 'array',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'recovered_at' => 'datetime',
        'expired_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isRecoverable(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_EXPIRED], true);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('customer_email', $email);
    }

    /**
     * Gera um token único de recuperação caso ainda não exista.
     */
    public function ensureRecoveryToken(): string
    {
        if ($this->recovery_token) {
            return $this->recovery_token;
        }

        $token = bin2hex(random_bytes(32));
        $this->update(['recovery_token' => $token]);

        return $token;
    }
}
