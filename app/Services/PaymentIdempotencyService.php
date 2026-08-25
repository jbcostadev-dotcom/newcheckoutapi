<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentIdempotency;
use App\Models\Store;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentIdempotencyService
{
    private const REQUEST_ATTRIBUTE = 'payment_idempotency_id';

    private const OWNER_ATTRIBUTE = 'payment_idempotency_owner';

    private const FORBIDDEN_RESPONSE_KEYS = [
        'card_number',
        'card_cvv',
        'cvv',
        'card_token',
        'token',
        'gateway_response',
        'details',
    ];

    public function __construct(private readonly CacheFactory $cache)
    {
    }

    public function isRequired(): bool
    {
        return (bool) config('payment_idempotency.required', false);
    }

    public function validKey(?string $key): bool
    {
        return is_string($key)
            && preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key) === 1;
    }

    public function requestHash(string $scope, Store $store, array $payload): string
    {
        $canonical = $this->canonicalPayload($scope, (int) $store->id, $payload);

        return hash_hmac(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->secret(),
        );
    }

    public function execute(
        Request $request,
        Store $store,
        string $scope,
        string $key,
        string $requestHash,
        Closure $operation,
    ): Response {
        $keyHash = hash('sha256', $key);
        $cacheKey = $this->resultKey((int) $store->id, $scope, $keyHash);

        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            $conflict = $this->conflictIfNeeded($cached['request_hash'] ?? null, $requestHash);
            if ($conflict) {
                return $conflict;
            }

            if (in_array($cached['state'] ?? null, [PaymentIdempotency::STATE_COMPLETED, PaymentIdempotency::STATE_FAILED], true)) {
                return $this->replayResponse($cached);
            }
        }

        $redisLock = $this->acquireRedisLock($store->id, $scope, $keyHash);
        if ($redisLock === false) {
            return $this->awaitOrPending($store->id, $scope, $keyHash, $requestHash);
        }

        $ownerToken = (string) Str::uuid();

        try {
            [$record, $claimed] = $this->reserveOrClaim(
                (int) $store->id,
                $scope,
                $keyHash,
                $requestHash,
                $ownerToken,
            );

            $conflict = $this->conflictIfNeeded($record->request_hash, $requestHash);
            if ($conflict) {
                return $conflict;
            }

            if ($record->isTerminal()) {
                return $this->replayRecord($record);
            }

            if (! $claimed) {
                return $this->awaitOrPending($store->id, $scope, $keyHash, $requestHash);
            }

            $request->attributes->set(self::REQUEST_ATTRIBUTE, $record->id);
            $request->attributes->set(self::OWNER_ATTRIBUTE, $ownerToken);

            try {
                $response = $operation();
            } catch (Throwable $exception) {
                $record->refresh();
                if ($record->owner_token !== $ownerToken) {
                    return $this->awaitOrPending($store->id, $scope, $keyHash, $requestHash);
                }

                if ($record->gateway_started_at !== null) {
                    $this->markIndeterminate($record, $exception->getMessage());

                    return $this->pendingResponse($record);
                }

                $this->releaseReservation($record, $ownerToken);
                throw $exception;
            }

            $record->refresh();
            if ($record->owner_token !== $ownerToken) {
                return $this->awaitOrPending($store->id, $scope, $keyHash, $requestHash);
            }

            $status = $response->getStatusCode();

            if ($status === 202) {
                $payload = $this->sanitizeResponsePayload($this->responseArray($response));

                $record->update([
                    'state' => PaymentIdempotency::STATE_PROCESSING,
                    'order_id' => $payload['order_id'] ?? $record->order_id,
                    'gateway_started_at' => $record->gateway_started_at ?? now(),
                    'owner_token' => null,
                    'locked_until' => null,
                ]);

                return response()->json($payload, 202, [
                    'Retry-After' => (string) ($payload['retry_after_seconds'] ?? 2),
                    'Idempotency-Key-Accepted' => 'true',
                ]);
            }

            if ($status >= 500 && $record->gateway_started_at !== null) {
                $this->markIndeterminate($record, 'Resposta HTTP '.$status.' após início da chamada à gateway.');

                return $this->pendingResponse($record->fresh());
            }

            if ($status >= 500) {
                $this->releaseReservation($record, $ownerToken);

                return $response;
            }

            $payload = $this->sanitizeResponsePayload($this->responseArray($response));
            $state = $status >= 400
                ? PaymentIdempotency::STATE_FAILED
                : PaymentIdempotency::STATE_COMPLETED;

            $record->update([
                'state' => $state,
                'http_status' => $status,
                'response_payload' => $payload,
                'owner_token' => null,
                'locked_until' => null,
                'expires_at' => now()->addSeconds($this->ttlSeconds()),
            ]);

            $snapshot = $this->snapshot($record->fresh());
            $this->cachePut($cacheKey, $snapshot);
            $this->propagateTerminalToSiblingUpsellIntents($record->fresh());

            return response()->json($payload, $status, [
                'Idempotency-Key-Accepted' => 'true',
            ]);
        } finally {
            if ($redisLock instanceof Lock) {
                try {
                    $redisLock->release();
                } catch (Throwable) {
                    // O lease durável no banco continua protegendo a intenção.
                }
            }
        }
    }

    public function markGatewayStarted(Request $request, Order $order): void
    {
        $recordId = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $ownerToken = $request->attributes->get(self::OWNER_ATTRIBUTE);
        if (! $recordId || ! $ownerToken) {
            return;
        }

        $started = PaymentIdempotency::query()
            ->whereKey($recordId)
            ->where('owner_token', $ownerToken)
            ->whereIn('state', [PaymentIdempotency::STATE_RESERVED, PaymentIdempotency::STATE_PROCESSING])
            ->update([
                'state' => PaymentIdempotency::STATE_PROCESSING,
                'order_id' => $order->id,
                'gateway_started_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if (! $started) {
            throw new RuntimeException('O lease da intenção de pagamento expirou antes da chamada à gateway.');
        }
    }

    public function attachOrder(Request $request, Order $order): void
    {
        $recordId = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $ownerToken = $request->attributes->get(self::OWNER_ATTRIBUTE);
        if ($recordId && $ownerToken) {
            PaymentIdempotency::query()
                ->whereKey($recordId)
                ->where('owner_token', $ownerToken)
                ->update([
                    'order_id' => $order->id,
                    'locked_until' => now()->addSeconds($this->lockSeconds()),
                ]);
        }
    }

    public function resumableOrder(Request $request): ?Order
    {
        $recordId = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $ownerToken = $request->attributes->get(self::OWNER_ATTRIBUTE);
        if (! $recordId || ! $ownerToken) {
            return null;
        }

        $record = PaymentIdempotency::query()
            ->whereKey($recordId)
            ->where('owner_token', $ownerToken)
            ->where('state', PaymentIdempotency::STATE_RESERVED)
            ->whereNull('gateway_started_at')
            ->whereNotNull('order_id')
            ->first();

        return $record?->order;
    }

    public function attachGatewayTransaction(Request $request, ?string $transactionId): void
    {
        $recordId = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $ownerToken = $request->attributes->get(self::OWNER_ATTRIBUTE);
        if ($recordId && $ownerToken && $transactionId) {
            PaymentIdempotency::query()
                ->whereKey($recordId)
                ->where('owner_token', $ownerToken)
                ->update([
                    'gateway_transaction_id' => $transactionId,
                ]);
        }
    }

    public function status(Store $store, string $scope, string $key): Response
    {
        $keyHash = hash('sha256', $key);
        $cacheKey = $this->resultKey((int) $store->id, $scope, $keyHash);
        $snapshot = $this->cacheGet($cacheKey);

        if (! is_array($snapshot)) {
            $record = PaymentIdempotency::query()
                ->where('store_id', $store->id)
                ->where('scope', $scope)
                ->where('key_hash', $keyHash)
                ->first();

            if (! $record) {
                return response()->json(['error' => 'payment_intent_not_found'], 404);
            }

            $this->promoteStaleProcessing($record);
            $snapshot = $this->snapshot($record->fresh());
        }

        if (in_array($snapshot['state'], [PaymentIdempotency::STATE_COMPLETED, PaymentIdempotency::STATE_FAILED], true)) {
            return response()->json([
                'idempotency_status' => $snapshot['state'],
                'order_id' => $snapshot['order_id'],
                'result' => [
                    'http_status' => $snapshot['http_status'],
                    'body' => $snapshot['response_payload'],
                ],
            ]);
        }

        return response()->json([
            'idempotency_status' => $snapshot['state'],
            'order_id' => $snapshot['order_id'],
            'retry_after_seconds' => 2,
        ], 202, ['Retry-After' => '2']);
    }

    public function resolveFromOrder(Order $order): void
    {
        PaymentIdempotency::query()
            ->where('order_id', $order->id)
            ->whereIn('state', [PaymentIdempotency::STATE_PROCESSING, PaymentIdempotency::STATE_INDETERMINATE])
            ->get()
            ->each(function (PaymentIdempotency $record) use ($order) {
                if (
                    $record->scope === PaymentIdempotency::SCOPE_UPSELL
                    && ! in_array($order->upsell_status, ['accepted', 'declined'], true)
                ) {
                    return;
                }

                $failed = $record->scope === PaymentIdempotency::SCOPE_UPSELL
                    ? $order->upsell_status !== 'accepted'
                    : in_array($order->status, [
                        Order::STATUS_FAILED,
                        Order::STATUS_REFUSED,
                        Order::STATUS_CANCELED,
                    ], true);

                $payload = $record->scope === PaymentIdempotency::SCOPE_UPSELL
                    ? $this->upsellResponse($order, ! $failed)
                    : $this->checkoutResponse($order);

                $record->update([
                    'state' => $failed ? PaymentIdempotency::STATE_FAILED : PaymentIdempotency::STATE_COMPLETED,
                    'gateway_transaction_id' => $order->gateway_transaction_id,
                    'http_status' => $failed ? 422 : 200,
                    'response_payload' => $payload,
                    'owner_token' => null,
                    'locked_until' => null,
                    'expires_at' => now()->addSeconds($this->ttlSeconds()),
                ]);

                $this->cachePut(
                    $this->resultKey($record->store_id, $record->scope, $record->key_hash),
                    $this->snapshot($record->fresh()),
                );
            });
    }

    private function reserveOrClaim(
        int $storeId,
        string $scope,
        string $keyHash,
        string $requestHash,
        string $ownerToken,
    ): array {
        try {
            $record = PaymentIdempotency::create([
                'store_id' => $storeId,
                'scope' => $scope,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'state' => PaymentIdempotency::STATE_RESERVED,
                'owner_token' => $ownerToken,
                'locked_until' => now()->addSeconds($this->lockSeconds()),
                'expires_at' => now()->addSeconds($this->ttlSeconds()),
            ]);

            return [$record, true];
        } catch (QueryException) {
            $record = PaymentIdempotency::query()
                ->where('store_id', $storeId)
                ->where('scope', $scope)
                ->where('key_hash', $keyHash)
                ->firstOrFail();
        }

        $this->promoteStaleProcessing($record);
        $record->refresh();

        if ($record->isTerminal() || $record->state === PaymentIdempotency::STATE_INDETERMINATE) {
            return [$record, false];
        }

        $claimed = PaymentIdempotency::query()
            ->whereKey($record->id)
            ->where('state', PaymentIdempotency::STATE_RESERVED)
            ->whereNull('gateway_started_at')
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->update([
                'owner_token' => $ownerToken,
                'locked_until' => now()->addSeconds($this->lockSeconds()),
                'updated_at' => now(),
            ]) === 1;

        return [$record->fresh(), $claimed];
    }

    private function promoteStaleProcessing(PaymentIdempotency $record): void
    {
        if ($record->state !== PaymentIdempotency::STATE_PROCESSING || ! $record->gateway_started_at) {
            return;
        }

        if ($record->gateway_started_at->gt(now()->subSeconds($this->processingStaleSeconds()))) {
            return;
        }

        $record->update([
            'state' => PaymentIdempotency::STATE_INDETERMINATE,
            'locked_until' => null,
            'processing_alerted_at' => now(),
        ]);

        Log::critical('Intenção de pagamento entrou em estado indeterminado.', [
            'payment_idempotency_id' => $record->id,
            'store_id' => $record->store_id,
            'scope' => $record->scope,
            'order_id' => $record->order_id,
        ]);
    }

    private function markIndeterminate(PaymentIdempotency $record, string $reason): void
    {
        $record->update([
            'state' => PaymentIdempotency::STATE_INDETERMINATE,
            'owner_token' => null,
            'locked_until' => null,
        ]);

        Log::critical('Resultado da chamada de pagamento é indeterminado; nova cobrança bloqueada.', [
            'payment_idempotency_id' => $record->id,
            'store_id' => $record->store_id,
            'scope' => $record->scope,
            'order_id' => $record->order_id,
            'reason' => Str::limit($reason, 500),
        ]);
    }

    private function releaseReservation(PaymentIdempotency $record, string $ownerToken): void
    {
        PaymentIdempotency::query()
            ->whereKey($record->id)
            ->where('owner_token', $ownerToken)
            ->whereNull('gateway_started_at')
            ->update([
                'state' => PaymentIdempotency::STATE_RESERVED,
                'owner_token' => null,
                'locked_until' => null,
            ]);
    }

    private function awaitOrPending(int $storeId, string $scope, string $keyHash, string $requestHash): Response
    {
        $deadline = microtime(true) + ($this->waitMilliseconds() / 1000);

        do {
            usleep(50000);
            $record = PaymentIdempotency::query()
                ->where('store_id', $storeId)
                ->where('scope', $scope)
                ->where('key_hash', $keyHash)
                ->first();

            if ($record) {
                $conflict = $this->conflictIfNeeded($record->request_hash, $requestHash);
                if ($conflict) {
                    return $conflict;
                }

                $this->promoteStaleProcessing($record);
                $record->refresh();
                if ($record->isTerminal()) {
                    return $this->replayRecord($record);
                }
            }
        } while (microtime(true) < $deadline);

        return $record
            ? $this->pendingResponse($record)
            : response()->json([
                'idempotency_status' => PaymentIdempotency::STATE_RESERVED,
                'order_id' => null,
                'retry_after_seconds' => 2,
            ], 202, ['Retry-After' => '2']);
    }

    private function pendingResponse(PaymentIdempotency $record): JsonResponse
    {
        return response()->json([
            'order_id' => $record->order_id,
            'status' => Order::STATUS_PROCESSING,
            'idempotency_status' => $record->state,
            'retry_after_seconds' => 2,
        ], 202, ['Retry-After' => '2']);
    }

    private function replayRecord(PaymentIdempotency $record): Response
    {
        $snapshot = $this->snapshot($record);
        $this->cachePut(
            $this->resultKey($record->store_id, $record->scope, $record->key_hash),
            $snapshot,
        );

        return $this->replayResponse($snapshot);
    }

    private function propagateTerminalToSiblingUpsellIntents(PaymentIdempotency $source): void
    {
        if ($source->scope !== PaymentIdempotency::SCOPE_UPSELL || ! $source->order_id || ! $source->isTerminal()) {
            return;
        }

        PaymentIdempotency::query()
            ->where('order_id', $source->order_id)
            ->where('scope', PaymentIdempotency::SCOPE_UPSELL)
            ->where('id', '!=', $source->id)
            ->whereIn('state', [PaymentIdempotency::STATE_PROCESSING, PaymentIdempotency::STATE_INDETERMINATE])
            ->get()
            ->each(function (PaymentIdempotency $sibling) use ($source) {
                $sibling->update([
                    'state' => $source->state,
                    'gateway_transaction_id' => $source->gateway_transaction_id,
                    'http_status' => $source->http_status,
                    'response_payload' => $source->response_payload,
                    'owner_token' => null,
                    'locked_until' => null,
                    'expires_at' => now()->addSeconds($this->ttlSeconds()),
                ]);

                $this->cachePut(
                    $this->resultKey($sibling->store_id, $sibling->scope, $sibling->key_hash),
                    $this->snapshot($sibling->fresh()),
                );
            });
    }

    private function replayResponse(array $snapshot): JsonResponse
    {
        return response()->json(
            $snapshot['response_payload'] ?? [],
            (int) ($snapshot['http_status'] ?? 200),
            ['Idempotency-Replayed' => 'true'],
        );
    }

    private function conflictIfNeeded(?string $storedHash, string $requestHash): ?JsonResponse
    {
        if ($storedHash !== null && ! hash_equals($storedHash, $requestHash)) {
            return response()->json([
                'error' => 'idempotency_key_reused',
                'message' => 'Esta chave de idempotência já foi usada com outros dados de pagamento.',
            ], 409);
        }

        return null;
    }

    private function canonicalPayload(string $scope, int $storeId, array $payload): array
    {
        unset(
            $payload['tracking_parameters'],
            $payload['card_cvv'],
            $payload['store_id'],
            $payload['domain'],
        );

        if (isset($payload['card_number'])) {
            $number = preg_replace('/\D/', '', (string) $payload['card_number']);
            $expiry = preg_replace('/\D/', '', (string) ($payload['card_expiry'] ?? ''));
            $payload['card_fingerprint'] = hash_hmac('sha256', $number.'|'.$expiry, $this->secret());
            unset($payload['card_number']);
        }

        foreach (['customer_email'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = strtolower(trim((string) $payload[$field]));
            }
        }

        foreach (['customer_document', 'customer_phone'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = preg_replace('/\D/', '', (string) $payload[$field]);
            }
        }

        foreach (['customer_name', 'card_holder'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = preg_replace('/\s+/', ' ', trim((string) $payload[$field]));
            }
        }

        foreach (['items', 'gift_selections'] as $field) {
            if (isset($payload[$field]) && is_array($payload[$field])) {
                usort($payload[$field], fn ($left, $right) => strcmp(
                    json_encode($this->sortRecursive((array) $left)),
                    json_encode($this->sortRecursive((array) $right)),
                ));
            }
        }

        return $this->sortRecursive([
            'scope' => $scope,
            'store_id' => $storeId,
            'payload' => $payload,
        ]);
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function sanitizeResponsePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_RESPONSE_KEYS, true)) {
                unset($payload[$key]);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitizeResponsePayload($value);
            }
        }

        return $payload;
    }

    private function responseArray(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function snapshot(PaymentIdempotency $record): array
    {
        return [
            'state' => $record->state,
            'request_hash' => $record->request_hash,
            'order_id' => $record->order_id,
            'http_status' => $record->http_status,
            'response_payload' => $record->response_payload,
        ];
    }

    private function checkoutResponse(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'gateway_transaction_id' => $order->gateway_transaction_id,
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'boleto_url' => $order->boleto_url,
            'boleto_barcode' => $order->boleto_barcode,
            'boleto_digitable_line' => $order->boleto_digitable_line,
            'card_brand' => $order->card_brand,
            'card_last4' => $order->card_last4,
            'installments' => $order->installments,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
            'has_upsell' => false,
        ];
    }

    private function upsellResponse(Order $order, bool $success): array
    {
        return [
            'success' => $success,
            'message' => $success ? null : 'A cobrança da oferta não foi aprovada.',
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
        ];
    }

    private function acquireRedisLock(int $storeId, string $scope, string $keyHash): Lock|false|null
    {
        try {
            $lock = $this->repository()->lock(
                'payment:idem:lock:{'.$storeId.'}:'.$scope.':'.$keyHash,
                $this->lockSeconds(),
            );

            return $lock->get() ? $lock : false;
        } catch (Throwable $exception) {
            Log::warning('Redis indisponível para lock de idempotência; usando lease do banco.', [
                'store_id' => $storeId,
                'scope' => $scope,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->repository()->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function cachePut(string $key, array $value): void
    {
        try {
            $this->repository()->put($key, $value, $this->ttlSeconds());
        } catch (Throwable) {
            // O registro durável no banco continua sendo a fonte da verdade.
        }
    }

    private function repository()
    {
        return $this->cache->store((string) config('payment_idempotency.store', 'redis'));
    }

    private function resultKey(int $storeId, string $scope, string $keyHash): string
    {
        return 'payment:idem:result:{'.$storeId.'}:'.$scope.':'.$keyHash;
    }

    private function secret(): string
    {
        $secret = (string) config('payment_idempotency.secret', config('app.key'));

        return $secret !== '' ? $secret : (string) config('app.key');
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('payment_idempotency.ttl_seconds', 86400));
    }

    private function lockSeconds(): int
    {
        return max(10, (int) config('payment_idempotency.lock_seconds', 45));
    }

    private function waitMilliseconds(): int
    {
        return max(0, (int) config('payment_idempotency.wait_milliseconds', 2000));
    }

    private function processingStaleSeconds(): int
    {
        return max($this->lockSeconds(), (int) config('payment_idempotency.processing_stale_seconds', 60));
    }
}
