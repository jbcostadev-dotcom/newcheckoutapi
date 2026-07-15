<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ── Shopify ────────────────────────────────────────────────────────
    // Credenciais do app Shopify são por loja (self-service):
    // salvas em stores.shopify_client_id / shopify_client_secret.
    // Aqui fica apenas a config global do redirect_uri e dos escopos.
    'shopify' => [
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_orders,write_themes'),
        'redirect_uri' => rtrim(env('APP_URL', 'https://' . env('API_DOMAIN', 'api.bersenker.shop')), '/') . '/api/shopify/callback',
        // Versão da Admin API REST usada nas chamadas de tema/assets.
        'api_version' => env('SHOPIFY_API_VERSION', '2025-07'),
        // URL base exposta ao snippet injetado no tema (pública). Default = APP_URL.
        'public_api_base' => env('SHOPIFY_PUBLIC_API_BASE', env('APP_URL')),
    ],

    // ── SuitPay ───────────────────────────────────────────────────────
    'suitpay' => [
        'api_url' => env('SUITPAY_API_URL', 'https://api.suitpay.com.br'),
        'api_key' => env('SUITPAY_API_KEY'),
        'secret' => env('SUITPAY_SECRET'),
        'environment' => env('SUITPAY_ENVIRONMENT', 'sandbox'), // sandbox | production
    ],

    // ── Domínios do projeto ───────────────────────────────────────────
    'domains' => [
        'api' => env('API_DOMAIN', 'api.bersenker.shop'),
        'admin' => env('ADMIN_DOMAIN', 'app.bersenker.shop'),
    ],

    // ── Checkout app (subdomínios e domínios personalizados) ──────────
    'checkout' => [
        'base_domain' => env('CHECKOUT_BASE_DOMAIN', 'bersenker.shop'),
        'app_domain' => env('CHECKOUT_APP_DOMAIN', 'checkout.bersenker.shop'),
    ],

    // ── Caddy (On-Demand TLS / Admin API) ─────────────────────────────
    'caddy' => [
        'admin_url' => env('CADDY_ADMIN_URL', 'http://localhost:2019'),
    ],

];
