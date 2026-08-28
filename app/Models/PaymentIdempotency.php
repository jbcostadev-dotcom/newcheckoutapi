<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentIdempotency extends Model
{
    public const SCOPE_CHECKOUT = 'checkout';

    public const SCOPE_UPSELL = 'upsell';

    public const SCOPE_DOWNSELL = 'downsell';

    public const STATE_RESERVED = 'reserved';

    public const STATE_PROCESSING = 'processing';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const STATE_INDETERMINATE = 'indeterminate';

    protected $fillable = [
        'store_id',
        'scope',
        'key_hash',
        'request_hash',
        'state',
        'order_id',
        'gateway_started_at',
        'gateway_transaction_id',
        'http_status',
        'response_payload',
        'owner_token',
        'locked_until',
        'expires_at',
        'processing_alerted_at',
        'indeterminate_alerted_at',
    ];

    protected $casts = [
        'gateway_started_at' => 'datetime',
        'locked_until' => 'datetime',
        'expires_at' => 'datetime',
        'processing_alerted_at' => 'datetime',
        'indeterminate_alerted_at' => 'datetime',
        'response_payload' => 'array',
        'http_status' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }
}
