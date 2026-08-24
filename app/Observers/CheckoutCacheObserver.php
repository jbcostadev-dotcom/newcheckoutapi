<?php

namespace App\Observers;

use App\Models\Store;
use App\Services\CheckoutResponseCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class CheckoutCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly CheckoutResponseCache $cache)
    {
    }

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $storeId = $model instanceof Store
            ? $model->getKey()
            : $model->getAttribute('store_id');

        if ($storeId !== null) {
            $this->cache->invalidateStore((int) $storeId);
        }
    }
}
