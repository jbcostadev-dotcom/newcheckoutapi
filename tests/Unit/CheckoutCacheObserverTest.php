<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Store;
use App\Observers\CheckoutCacheObserver;
use App\Services\CheckoutResponseCache;
use Mockery;
use Tests\TestCase;

class CheckoutCacheObserverTest extends TestCase
{
    public function test_store_model_invalidates_its_own_checkout_cache(): void
    {
        $cache = Mockery::mock(CheckoutResponseCache::class);
        $cache->shouldReceive('invalidateStore')->once()->with(42);

        $store = new Store;
        $store->setAttribute('id', 42);

        (new CheckoutCacheObserver($cache))->saved($store);

        $this->addToAssertionCount(1);
    }

    public function test_store_owned_model_invalidates_parent_store_cache(): void
    {
        $cache = Mockery::mock(CheckoutResponseCache::class);
        $cache->shouldReceive('invalidateStore')->once()->with(73);

        $product = new Product;
        $product->setAttribute('store_id', 73);

        (new CheckoutCacheObserver($cache))->deleted($product);

        $this->addToAssertionCount(1);
    }
}
