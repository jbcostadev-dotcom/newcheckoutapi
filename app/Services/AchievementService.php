<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\AchievementAward;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class AchievementService
{
    /** @return array<string, int> */
    public function metricValues(Store $store): array
    {
        $paid = $store->orders()->where('status', 'paid');
        $paid24h = $store->orders()->where('status', 'paid')->where('created_at', '>=', now()->subDay());

        return [
            'revenue_total' => (int) round(((float) $paid->sum('amount')) * 100),
            'orders_paid' => $paid->count(),
            'revenue_24h' => (int) round(((float) $paid24h->sum('amount')) * 100),
            'orders_paid_24h' => $paid24h->count(),
        ];
    }

    /**
     * Libera somente novas conquistas. Conquistas entregues não são revogadas
     * quando ocorre um reembolso: o histórico permanece auditável.
     */
    public function synchronize(Store $store): array
    {
        $values = $this->metricValues($store);
        $achievements = Achievement::query()->where('active', true)->get();

        foreach ($achievements as $achievement) {
            $value = $values[$achievement->metric] ?? 0;
            if ($value >= $achievement->target_value) {
                AchievementAward::firstOrCreate(
                    ['store_id' => $store->id, 'achievement_id' => $achievement->id],
                    [
                        'unlocked_at' => now(),
                        'value_at_unlock' => $value,
                        'target_at_unlock' => $achievement->target_value,
                    ]
                );
            }
        }

        return $values;
    }

    public function payload(Achievement $achievement, int $value, ?AchievementAward $award = null): array
    {
        $target = $achievement->target_value;
        $progress = $target > 0 ? min(100, round(($value / $target) * 100, 1)) : 0;

        return [
            'id' => $achievement->id,
            'type' => $achievement->type,
            'metric' => $achievement->metric,
            'target_value' => $target,
            'target' => $achievement->usesMoneyMetric() ? $target / 100 : $target,
            'is_monetary' => $achievement->usesMoneyMetric(),
            'title' => $achievement->title,
            'subtitle' => $achievement->subtitle,
            'description' => $achievement->description,
            'image_url' => $achievement->image_path ? Storage::disk('public')->url($achievement->image_path) : null,
            'active' => $achievement->active,
            'sort_order' => $achievement->sort_order,
            'current_value' => $value,
            'current' => $achievement->usesMoneyMetric() ? $value / 100 : $value,
            'progress' => $progress,
            'unlocked' => (bool) $award,
            'unlocked_at' => $award?->unlocked_at?->toISOString(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function catalogForStore(Store $store): Collection
    {
        $values = $this->synchronize($store);
        $awards = $store->achievementAwards()->get()->keyBy('achievement_id');

        return Achievement::query()
            ->where('active', true)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Achievement $achievement) => $this->payload(
                $achievement,
                $values[$achievement->metric] ?? 0,
                $awards->get($achievement->id)
            ));
    }
}
