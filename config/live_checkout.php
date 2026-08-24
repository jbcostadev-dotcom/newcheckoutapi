<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Live Checkout Redis
    |--------------------------------------------------------------------------
    |
    | O checkout ao vivo usa uma conexao Redis dedicada logicamente ao cache.
    | As sessoes expiram sozinhas e o indice por loja e limpo durante os
    | proprios heartbeats e consultas, sem depender de um cron.
    |
    */

    'redis_connection' => env('LIVE_CHECKOUT_REDIS_CONNECTION', 'cache'),

    'ttl_seconds' => (int) env('LIVE_CHECKOUT_TTL_SECONDS', 15),

    'index_ttl_seconds' => (int) env('LIVE_CHECKOUT_INDEX_TTL_SECONDS', 30),
];
