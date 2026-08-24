<?php

namespace App\Services;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use JsonException;

class LiveCheckoutRedisStore
{
    /**
     * Mescla a sessao, renova seu TTL, atualiza o indice e remove membros
     * expirados em uma unica operacao atomica no Redis.
     */
    private const HEARTBEAT_SCRIPT = <<<'LUA'
local current = {}
local existing = redis.call('GET', KEYS[1])
local previous_step = ''

if existing then
    current = cjson.decode(existing)
    previous_step = current['step'] or ''
end

local updates = cjson.decode(ARGV[1])
for key, value in pairs(updates) do
    current[key] = value
end

redis.call('SET', KEYS[1], cjson.encode(current), 'EX', ARGV[2])
redis.call('ZADD', KEYS[2], ARGV[3], KEYS[1])
redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', ARGV[4])
redis.call('EXPIRE', KEYS[2], ARGV[5])

return previous_step
LUA;

    /**
     * Remove scores vencidos, carrega as sessoes restantes da mais recente
     * para a mais antiga e elimina referencias cujo payload ja expirou.
     */
    private const ACTIVE_SESSIONS_SCRIPT = <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])

local session_keys = redis.call('ZREVRANGEBYSCORE', KEYS[1], '+inf', ARGV[1])
local sessions = {}

for _, session_key in ipairs(session_keys) do
    local payload = redis.call('GET', session_key)
    if payload then
        table.insert(sessions, payload)
    else
        redis.call('ZREM', KEYS[1], session_key)
    end
end

if redis.call('ZCARD', KEYS[1]) == 0 then
    redis.call('DEL', KEYS[1])
else
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end

return sessions
LUA;

    /**
     * Remove o payload e sua referencia no indice atomicamente.
     */
    private const FORGET_SCRIPT = <<<'LUA'
redis.call('DEL', KEYS[1])
redis.call('ZREM', KEYS[2], KEYS[1])

if redis.call('ZCARD', KEYS[2]) == 0 then
    redis.call('DEL', KEYS[2])
else
    redis.call('EXPIRE', KEYS[2], ARGV[1])
end

return 1
LUA;

    public function __construct(private readonly RedisFactory $redis)
    {
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function heartbeat(int $storeId, string $sessionId, array $updates): ?string
    {
        $now = now()->getTimestamp();
        $previousStep = $this->connection()->eval(
            self::HEARTBEAT_SCRIPT,
            2,
            $this->sessionKey($storeId, $sessionId),
            $this->indexKey($storeId),
            json_encode($updates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->ttlSeconds(),
            $now,
            $now - $this->ttlSeconds(),
            $this->indexTtlSeconds(),
        );

        return $previousStep === '' || $previousStep === null
            ? null
            : (string) $previousStep;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSessions(int $storeId): array
    {
        $payloads = $this->connection()->eval(
            self::ACTIVE_SESSIONS_SCRIPT,
            1,
            $this->indexKey($storeId),
            now()->getTimestamp() - $this->ttlSeconds(),
            $this->indexTtlSeconds(),
        );

        $sessions = [];
        foreach ((array) $payloads as $payload) {
            try {
                $session = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($session)) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    public function forget(int $storeId, string $sessionId): void
    {
        $this->connection()->eval(
            self::FORGET_SCRIPT,
            2,
            $this->sessionKey($storeId, $sessionId),
            $this->indexKey($storeId),
            $this->indexTtlSeconds(),
        );
    }

    private function connection()
    {
        return $this->redis->connection((string) config('live_checkout.redis_connection', 'cache'));
    }

    private function ttlSeconds(): int
    {
        return max(5, (int) config('live_checkout.ttl_seconds', 15));
    }

    private function indexTtlSeconds(): int
    {
        return max(
            $this->ttlSeconds() + 1,
            (int) config('live_checkout.index_ttl_seconds', 30),
        );
    }

    private function sessionKey(int $storeId, string $sessionId): string
    {
        return 'checkout:live:session:{'.$storeId.'}:'.$sessionId;
    }

    private function indexKey(int $storeId): string
    {
        return 'checkout:live:index:{'.$storeId.'}';
    }
}
