<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AchievementAward extends Model
{
    protected $fillable = [
        'store_id', 'achievement_id', 'unlocked_at', 'value_at_unlock', 'target_at_unlock',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
        'value_at_unlock' => 'integer',
        'target_at_unlock' => 'integer',
    ];

    public function achievement()
    {
        return $this->belongsTo(Achievement::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
