<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutFunnelSession extends Model
{
    public const STAGE_ENTERED = 'entered';

    public const STAGE_PERSONAL_DATA = 'personal_data';

    public const STAGE_DELIVERY = 'delivery';

    protected $fillable = [
        'store_id',
        'session_id',
        'furthest_stage',
        'payment_approved',
        'approved_at',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'payment_approved' => 'boolean',
        'approved_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public static function recordStage(int $storeId, string $sessionId, string $checkoutStep): void
    {
        $stage = match ($checkoutStep) {
            'entrega' => self::STAGE_PERSONAL_DATA,
            'pagamento' => self::STAGE_DELIVERY,
            default => self::STAGE_ENTERED,
        };

        $session = self::firstOrNew([
            'store_id' => $storeId,
            'session_id' => $sessionId,
        ]);

        if (! $session->exists) {
            $session->furthest_stage = $stage;
            $session->first_seen_at = now();
        } elseif (self::stageRank($stage) > self::stageRank($session->furthest_stage)) {
            $session->furthest_stage = $stage;
        }

        $session->last_seen_at = now();
        $session->save();
    }

    public static function markApproved(int $storeId, string $sessionId): void
    {
        self::where('store_id', $storeId)
            ->where('session_id', $sessionId)
            ->update([
                'payment_approved' => true,
                'approved_at' => now(),
                'last_seen_at' => now(),
            ]);
    }

    private static function stageRank(?string $stage): int
    {
        return match ($stage) {
            self::STAGE_PERSONAL_DATA => 2,
            self::STAGE_DELIVERY => 3,
            default => 1,
        };
    }
}
