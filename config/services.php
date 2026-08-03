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
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_orders,write_orders,write_themes,read_customers,write_customers'),
        'redirect_uri' => rtrim(env('APP_URL', 'https://'.env('API_DOMAIN', 'api.bersenker.shop')), '/').'/api/shopify/callback',
        // Versão da Admin API REST usada nas chamadas de tema/assets.
        'api_version' => env('SHOPIFY_API_VERSION', '2025-07'),
        // URL base exposta ao snippet injetado no tema (pública). Default = APP_URL.
        'public_api_base' => env('SHOPIFY_PUBLIC_API_BASE', env('APP_URL')),
        // URL do painel (frontend) para onde o callback Shopify redireciona o lojista.
        'frontend_url' => env('FRONTEND_URL', 'https://app.bersenker.shop'),
    ],

    // ── Unipay (FastSoft Brasil) ──────────────────────────────────────
    // Credenciais (api_key = pk_live_* pública do SDK, secret_key = sk_* secreta
    // para Basic Auth server-side) ficam por loja, na tabela `gateways`.
    // Aqui permanecem apenas as URLs globais (API REST + SDK JS client-side).
    'unipay' => [
        'api_url' => env('UNIPAY_API_URL', 'https://api.fastsoftbrasil.com'),
        'js_url' => env('UNIPAY_JS_URL', 'https://js.fastsoftbrasil.com/security.js'),
        'webhook_secret' => env('UNIPAY_WEBHOOK_SECRET'),
        'webhook_ips' => array_filter(array_map('trim', explode(',', env('UNIPAY_WEBHOOK_IPS', '')))),
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

    // ── Utmify (rastreamento de vendas) ───────────────────────────────
    // Credencial de API fica por loja, na tabela `utmify_settings`.
    'utmify' => [
        'api_url' => env('UTMIFY_API_URL', 'https://api.utmify.com.br'),
        'platform' => env('UTMIFY_PLATFORM', 'Bersenker'),
    ],

    // Meta Pixel + Conversions API. O token de cada loja fica criptografado
    // em meta_pixel_settings; aqui ficam apenas endpoint e versão globais.
    'meta' => [
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v23.0'),
        'timeout' => env('META_GRAPH_TIMEOUT', 8),
    ],

    // ── WhatsApp HTTP API (WAHA - API nao oficial) ───────────────────
    // Cada chip WhatsApp e uma sessao na WAHA. URL e chave sao globais;
    // cada conexao fica registrada na tabela whatsapp_instances.
    'waha' => [
        'url' => env('WAHA_API_URL', 'http://localhost:3000'),
        'key' => env('WAHA_API_KEY'),
    ],

];
