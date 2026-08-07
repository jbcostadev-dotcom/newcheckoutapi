<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\AchievementService;

class OrderObserver
{
    public function saved(Order $order): void
    {
        if (! $order->wasRecentlyCreated && ! $order->wasChanged('status') && ! $order->wasChanged('amount')) {
            return;
        }

        app(AchievementService::class)->synchronize($order->store()->first());
    }
}
