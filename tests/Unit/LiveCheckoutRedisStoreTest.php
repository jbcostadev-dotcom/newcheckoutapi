<?php

namespace Tests\Unit;

use App\Services\LiveCheckoutRedisStore;
use Carbon\Carbon;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Mockery;
use Tests\TestCase;

class LiveCheckoutRedisStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_heartbeat_updates_session_and_sorted_set_atomically(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        config([
            'live_checkout.redis_connection' => 'cache',
            'live_checkout.ttl_seconds' => 15,
            'live_checkout.index_ttl_seconds' => 30,
        ]);

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numberOfKeys, ...$arguments) {
                $payload = json_decode($arguments[2], true);

                return $numberOfKeys === 2
                    && str_contains($script, "redis.call('ZADD'")
                    && str_contains($script, "redis.call('ZREMRANGEBYSCORE'")
                    && $arguments[0] === 'checkout:live:session:{12}:session-abc'
                    && $arguments[1] === 'checkout:live:index:{12}'
                    && $payload['step'] === 'pagamento'
                    && $arguments[3] === 15
                    && $arguments[4] === Carbon::now()->getTimestamp()
                    && $arguments[5] === Carbon::now()->getTimestamp() - 15
                    && $arguments[6] === 30;
            })
            ->andReturn('entrega');

        $store = $this->makeStore($connection);

        $previousStep = $store->heartbeat(12, 'session-abc', [
            'step' => 'pagamento',
            'total' => 149.9,
        ]);

        $this->assertSame('entrega', $previousStep);
    }

    public function test_active_sessions_are_decoded_in_redis_order(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        config([
            'live_checkout.redis_connection' => 'cache',
            'live_checkout.ttl_seconds' => 15,
            'live_checkout.index_ttl_seconds' => 30,
        ]);

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('eval')
            ->once()
            ->withArgs(fn (string $script, int $numberOfKeys, ...$arguments) => $numberOfKeys === 1
                && str_contains($script, "redis.call('ZREVRANGEBYSCORE'")
                && $arguments === [
                    'checkout:live:index:{12}',
                    Carbon::now()->getTimestamp() - 15,
                    30,
                ])
            ->andReturn([
                '{"session_id":"newest","step":"pagamento"}',
                'invalid-json',
                '{"session_id":"oldest","step":"dados"}',
            ]);

        $sessions = $this->makeStore($connection)->activeSessions(12);

        $this->assertSame(['newest', 'oldest'], array_column($sessions, 'session_id'));
    }

    public function test_forget_removes_session_and_index_member_atomically(): void
    {
        config([
            'live_checkout.redis_connection' => 'cache',
            'live_checkout.ttl_seconds' => 15,
            'live_checkout.index_ttl_seconds' => 30,
        ]);

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('eval')
            ->once()
            ->withArgs(fn (string $script, int $numberOfKeys, ...$arguments) => $numberOfKeys === 2
                && str_contains($script, "redis.call('ZREM'")
                && $arguments === [
                    'checkout:live:session:{12}:session-abc',
                    'checkout:live:index:{12}',
                    30,
                ])
            ->andReturn(1);

        $this->makeStore($connection)->forget(12, 'session-abc');

        $this->addToAssertionCount(1);
    }

    private function makeStore(PhpRedisConnection $connection): LiveCheckoutRedisStore
    {
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')
            ->with('cache')
            ->andReturn($connection);

        return new LiveCheckoutRedisStore($redis);
    }
}
