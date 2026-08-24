<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Public Checkout Response Cache
    |--------------------------------------------------------------------------
    |
    | A montagem pública do checkout usa explicitamente o store Redis, mesmo
    | quando o cache padrão da aplicação ainda está configurado no banco.
    | A revisão por loja permite invalidar todas as combinações de carrinho
    | com um único incremento atômico.
    |
    */

    'store' => env('CHECKOUT_CACHE_STORE', 'redis'),

    'ttl_seconds' => (int) env('CHECKOUT_CACHE_TTL_SECONDS', 300),
];
