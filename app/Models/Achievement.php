<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    public const TYPE_PLATE = 'plate';
    public const TYPE_BADGE = 'badge';

    public const METRICS = [
        'revenue_total', 'orders_paid', 'revenue_24h', 'orders_paid_24h',
    ];

    protected $fillable = [
        'type', 'metric', 'target_value', 'title', 'subtitle', 'description',
        'image_path', 'active', 'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'target_value' => 'integer',
        'sort_order' => 'integer',
    ];

    public function awards()
    {
        return $this->hasMany(AchievementAward::class);
    }

    public function usesMoneyMetric(): bool
    {
        return str_starts_with($this->metric, 'revenue_');
    }
}
