<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

class CheckoutResponseCache
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  Closure(): array{store_id: int|null, status: int, body: array<string, mixed>}  $resolver
     * @return array{status: int, body: array<string, mixed>}
     */
    public function rememberCheckout(string $identifier, array $productIds, Closure $resolver): array
    {
        $signature = hash('sha256', json_encode([
            'identifier' => strtolower(trim($identifier)),
            'product_ids' => array_values($productIds),
        ], JSON_THROW_ON_ERROR));
        $key = 'checkout:response:v2:'.$signature;

        try {
            $repository = $this->repository();
            $missing = new \stdClass;
            $cached = $repository->get($key, $missing);
            if ($cached !== $missing
                && is_array($cached)
                && isset($cached['store_id'], $cached['revision'], $cached['result'])
                && (int) $cached['revision'] === $this->revision($repository, (int) $cached['store_id'])
                && is_array($cached['result'])) {
                return $cached['result'];
            }
        } catch (Throwable) {
            // Cache e uma otimizacao: uma indisponibilidade do Redis nunca
            // pode impedir que o checkout seja montado diretamente do banco.
            return $this->response($resolver());
        }

        $result = $resolver();

        // Respostas negativas não entram no cache para evitar poluição por
        // combinações aleatórias de IDs em um endpoint público.
        if (($result['store_id'] ?? null) !== null
            && ($result['status'] ?? 500) >= 200
            && ($result['status'] ?? 500) < 300) {
            $storeId = (int) $result['store_id'];
            $cacheableResult = [
                'status' => (int) $result['status'],
                'body' => $result['body'],
            ];

            try {
                $repository->put($key, [
                    'store_id' => $storeId,
                    'revision' => $this->revision($repository, $storeId),
                    'result' => $cacheableResult,
                ], $this->ttlSeconds());
            } catch (Throwable) {
                // A resposta calculada continua valida mesmo se a gravacao
                // no cache falhar.
            }

            return $cacheableResult;
        }

        return $this->response($result);
    }

    /**
     * Invalida atomicamente todas as combinações de checkout de uma loja.
     * As chaves da revisão anterior desaparecem naturalmente pelo TTL.
     */
    public function invalidateStore(int $storeId): void
    {
        // INCR e atomico no Redis e cria a chave com valor 1 quando ela ainda
        // nao existe, eliminando a janela de corrida da inicializacao.
        try {
            $this->repository()->increment($this->revisionKey($storeId));
        } catch (Throwable) {
            // A atualizacao do modelo nao deve falhar por indisponibilidade
            // de uma camada de cache opcional.
        }
    }

    private function revision(Repository $repository, int $storeId): int
    {
        return max(0, (int) $repository->get($this->revisionKey($storeId), 0));
    }

    private function revisionKey(int $storeId): string
    {
        return 'checkout:response:revision:{'.$storeId.'}';
    }

    private function repository(): Repository
    {
        return $this->cache->store((string) config('checkout_cache.store', 'redis'));
    }

    private function ttlSeconds(): int
    {
        return max(30, (int) config('checkout_cache.ttl_seconds', 300));
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     * @return array{status: int, body: array<string, mixed>}
     */
    private function response(array $result): array
    {
        return [
            'status' => (int) $result['status'],
            'body' => $result['body'],
        ];
    }
}
