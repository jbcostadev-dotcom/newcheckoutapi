<?php

namespace Tests\Unit;

use App\Services\CheckoutResponseCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CheckoutResponseCacheTest extends TestCase
{
    public function test_successful_checkout_response_is_reused_until_store_is_invalidated(): void
    {
        config([
            'checkout_cache.store' => 'array',
            'checkout_cache.ttl_seconds' => 300,
        ]);

        $cache = app(CheckoutResponseCache::class);
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return [
                'store_id' => 77,
                'status' => 200,
                'body' => ['generation' => $calls],
            ];
        };

        $first = $cache->rememberCheckout('77', [10, 10, 20], $resolver);
        $second = $cache->rememberCheckout('77', [10, 10, 20], $resolver);

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);

        $cache->invalidateStore(77);
        $third = $cache->rememberCheckout('77', [10, 10, 20], $resolver);

        $this->assertSame(2, $calls);
        $this->assertSame(2, $third['body']['generation']);
    }

    public function test_product_order_and_quantity_are_part_of_cache_signature(): void
    {
        config([
            'checkout_cache.store' => 'array',
            'checkout_cache.ttl_seconds' => 300,
        ]);

        $cache = app(CheckoutResponseCache::class);
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return [
                'store_id' => 88,
                'status' => 200,
                'body' => ['generation' => $calls],
            ];
        };

        $cache->rememberCheckout('loja-teste', [10, 20], $resolver);
        $cache->rememberCheckout('loja-teste', [20, 10], $resolver);
        $cache->rememberCheckout('loja-teste', [10, 10, 20], $resolver);

        $this->assertSame(3, $calls);
    }

    public function test_error_responses_are_not_cached(): void
    {
        config([
            'checkout_cache.store' => 'array',
            'checkout_cache.ttl_seconds' => 300,
        ]);

        $cache = app(CheckoutResponseCache::class);
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return [
                'store_id' => null,
                'status' => 404,
                'body' => ['error' => 'No active products found'],
            ];
        };

        $cache->rememberCheckout('99', [999], $resolver);
        $cache->rememberCheckout('99', [999], $resolver);

        $this->assertSame(2, $calls);
    }

    public function test_redis_failure_falls_back_to_resolver(): void
    {
        config(['checkout_cache.store' => 'redis']);

        $factory = Mockery::mock(CacheFactory::class);
        $factory->shouldReceive('store')
            ->once()
            ->with('redis')
            ->andThrow(new RuntimeException('Redis unavailable'));

        $cache = new CheckoutResponseCache($factory);
        $result = $cache->rememberCheckout('loja-teste', [10], fn () => [
            'store_id' => 55,
            'status' => 200,
            'body' => ['source' => 'database'],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('database', $result['body']['source']);
    }
}
