<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    public const EVENT_ORDER_CREATED = 'ORDER_CREATED';

    public const EVENT_ORDER_PAID = 'ORDER_PAID';

    public const EVENT_ORDER_REFUSED = 'ORDER_REFUSED';

    public const EVENT_CART_ABANDONED = 'CART_ABANDONED';

    public const EVENT_PIX_CREATED = 'PIX_CREATED';

    public const EVENT_BILLET_CREATED = 'BILLET_CREATED';

    public const EVENTS = [
        self::EVENT_ORDER_CREATED,
        self::EVENT_ORDER_PAID,
        self::EVENT_ORDER_REFUSED,
        self::EVENT_CART_ABANDONED,
        self::EVENT_PIX_CREATED,
        self::EVENT_BILLET_CREATED,
    ];

    protected $fillable = [
        'store_id',
        'name',
        'url',
        'token',
        'events',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->events ?? [], true);
    }
}
