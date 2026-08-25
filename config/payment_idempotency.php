<?php

return [
    'store' => env('PAYMENT_IDEMPOTENCY_CACHE_STORE', 'redis'),
    'required' => (bool) env('PAYMENT_IDEMPOTENCY_REQUIRED', false),
    'ttl_seconds' => (int) env('PAYMENT_IDEMPOTENCY_TTL_SECONDS', 86400),
    'lock_seconds' => (int) env('PAYMENT_IDEMPOTENCY_LOCK_SECONDS', 45),
    'wait_milliseconds' => (int) env('PAYMENT_IDEMPOTENCY_WAIT_MILLISECONDS', 2000),
    'processing_stale_seconds' => (int) env('PAYMENT_IDEMPOTENCY_PROCESSING_STALE_SECONDS', 60),
    'retention_days' => (int) env('PAYMENT_IDEMPOTENCY_RETENTION_DAYS', 90),
    'secret' => env('PAYMENT_IDEMPOTENCY_SECRET', env('APP_KEY')),
];
