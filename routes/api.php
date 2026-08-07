<?php

use App\Http\Controllers\API\AbandonedCartController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AchievementController;
use App\Http\Controllers\API\AdminAchievementController;
use App\Http\Controllers\API\CheckoutController;
use App\Http\Controllers\API\CheckoutSettingController;
use App\Http\Controllers\API\CommunicationController;
use App\Http\Controllers\API\CouponController;
use App\Http\Controllers\API\CustomerController;
use App\Http\Controllers\API\DomainController;
use App\Http\Controllers\API\EmailLogController;
use App\Http\Controllers\API\EmailTemplateController;
use App\Http\Controllers\API\GatewayController;
use App\Http\Controllers\API\GoogleAdsSettingController;
use App\Http\Controllers\API\LiveCheckoutController;
use App\Http\Controllers\API\MetaConversionController;
use App\Http\Controllers\API\MetaPixelSettingController;
use App\Http\Controllers\API\TikTokConversionController;
use App\Http\Controllers\API\TikTokPixelSettingController;
use App\Http\Controllers\API\KwaiConversionController;
use App\Http\Controllers\API\KwaiPixelSettingController;
use App\Http\Controllers\API\TaboolaConversionController;
use App\Http\Controllers\API\TaboolaPixelSettingController;
use App\Http\Controllers\API\MelhorEnvioSettingController;
use App\Http\Controllers\API\OrderBumpController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ShippingMethodController;
use App\Http\Controllers\API\ShopifyController;
use App\Http\Controllers\API\SmtpSettingController;
use App\Http\Controllers\API\SocialProofController;
use App\Http\Controllers\API\SslController;
use App\Http\Controllers\API\StoreController;
use App\Http\Controllers\API\UpsellController;
use App\Http\Controllers\API\UtmifySettingController;
use App\Http\Controllers\API\WhatsappChipController;
use App\Http\Controllers\API\WhatsappLogController;
use App\Http\Controllers\API\WhatsappTemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Rotas públicas ──────────────────────────────────────────────────

// Login e cadastro com proteção anti-bruteforce (definida em AppServiceProvider).
Route::middleware('throttle:register')->post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);

// Shopify OAuth (público — redirecionamento)
Route::get('/shopify/install', [ShopifyController::class, 'install']);
Route::get('/shopify/callback', [ShopifyController::class, 'callback']);

// Checkout público
Route::get('/checkout', [CheckoutController::class, 'show']);
Route::get('/checkout/preview', [CheckoutController::class, 'preview']);
Route::get('/checkout/coupon', [CheckoutController::class, 'validateCoupon']);
Route::post('/checkout/process', [PaymentController::class, 'process']);
Route::get('/checkout/order/{orderId}/pix', [PaymentController::class, 'getPixStatus']);
Route::get('/checkout/order/{orderId}/confirmed', [PaymentController::class, 'getOrderConfirmed']);
Route::post('/webhook/unipay', [PaymentController::class, 'webhook']);

// Upsell público
Route::get('/checkout/upsell', [UpsellController::class, 'getOffer']);
Route::post('/checkout/upsell/charge', [UpsellController::class, 'charge']);
Route::post('/checkout/upsell/decline', [UpsellController::class, 'decline']);

// Shopify checkout redirect (público — chamado pelo snippet injetado no tema)
Route::post('/shopify/checkout-redirect', [ShopifyController::class, 'checkoutRedirect']);

// Customer registration (público — chamado durante o checkout)
Route::post('/checkout/customer', [CustomerController::class, 'register']);
Route::post('/checkout/customer/address', [CustomerController::class, 'updateAddress']);

// Abandoned cart tracking (público — chamado durante o checkout)
Route::post('/checkout/abandoned-cart', [AbandonedCartController::class, 'track']);
Route::get('/checkout/recover/{token}', [AbandonedCartController::class, 'recover']);

// Live checkout tracking (público — chamado durante o checkout)
Route::post('/checkout/live/heartbeat', [LiveCheckoutController::class, 'heartbeat']);
Route::post('/checkout/live/remove', [LiveCheckoutController::class, 'remove']);

// Meta Pixel + Conversions API (evento público, validado contra a loja ativa)
Route::middleware('throttle:120,1')->post('/checkout/meta/event', [MetaConversionController::class, 'event']);
Route::middleware('throttle:120,1')->post('/checkout/tiktok/event', [TikTokConversionController::class, 'event']);
Route::middleware('throttle:120,1')->post('/checkout/kwai/event', [KwaiConversionController::class, 'event']);
Route::middleware('throttle:120,1')->post('/checkout/taboola/event', [TaboolaConversionController::class, 'event']);

// SSL validation — público (consultado pelo Caddy On-Demand TLS)
Route::get('/ssl/domain-check', [SslController::class, 'domainCheck']);

// ── Rotas autenticadas (Sanctum) ──────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::put('/user', [AuthController::class, 'update']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);

    // Stores (apiResource)
    Route::apiResource('stores', StoreController::class);

    // Products (aninhado a stores)
    Route::apiResource('stores.products', ProductController::class);

    // Checkout Settings
    Route::get('stores/{store}/settings', [CheckoutSettingController::class, 'show']);
    Route::put('stores/{store}/settings', [CheckoutSettingController::class, 'update']);

    // Orders
    Route::get('stores/{store}/metrics', [OrderController::class, 'metrics']);
    Route::get('stores/{store}/orders', [OrderController::class, 'index']);
    Route::get('stores/{store}/orders/{order}', [OrderController::class, 'show']);
    Route::patch('stores/{store}/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Conquistas da loja (o escopo é sempre validado pelo proprietário da loja)
    Route::get('stores/{store}/achievements', [AchievementController::class, 'index']);

    // Gateways
    Route::get('stores/{store}/gateways', [GatewayController::class, 'index']);
    Route::post('stores/{store}/gateways', [GatewayController::class, 'store']);
    Route::put('stores/{store}/gateways/{gateway}', [GatewayController::class, 'update']);
    Route::delete('stores/{store}/gateways/{gateway}', [GatewayController::class, 'destroy']);
    Route::post('stores/{store}/gateways/{gateway}/test', [GatewayController::class, 'test']);

    // Shipping Methods (Fretes)
    Route::get('stores/{store}/shipping-methods', [ShippingMethodController::class, 'index']);
    Route::post('stores/{store}/shipping-methods', [ShippingMethodController::class, 'store']);
    Route::put('stores/{store}/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'update']);
    Route::delete('stores/{store}/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'destroy']);

    // Social Proofs
    Route::get('stores/{store}/social-proofs', [SocialProofController::class, 'index']);
    Route::post('stores/{store}/social-proofs', [SocialProofController::class, 'store']);
    Route::post('stores/{store}/social-proofs/{socialProof}', [SocialProofController::class, 'update']);
    Route::delete('stores/{store}/social-proofs/{socialProof}', [SocialProofController::class, 'destroy']);

    // Shopify (status, credenciais, sync e desconexão)
    Route::get('stores/{store}/shopify/status', [ShopifyController::class, 'status']);
    Route::put('stores/{store}/shopify/credentials', [ShopifyController::class, 'updateCredentials']);
    Route::post('stores/{store}/shopify/sync', [ShopifyController::class, 'sync']);
    Route::delete('stores/{store}/shopify', [ShopifyController::class, 'disconnect']);

    // Utmify (credencial de API de rastreamento por loja)
    Route::get('stores/{store}/utmify', [UtmifySettingController::class, 'show']);
    Route::put('stores/{store}/utmify', [UtmifySettingController::class, 'update']);

    // Melhor Envio (configuração de remetente e etiquetas por loja)
    Route::get('stores/{store}/melhor-envio', [MelhorEnvioSettingController::class, 'show']);
    Route::put('stores/{store}/melhor-envio', [MelhorEnvioSettingController::class, 'update']);

    // Google Ads (configuração de pixel de conversão por loja)
    Route::get('stores/{store}/google-ads', [GoogleAdsSettingController::class, 'show']);
    Route::put('stores/{store}/google-ads', [GoogleAdsSettingController::class, 'update']);

    // Meta Pixel + Conversions API (credenciais server-side por loja)
    Route::get('stores/{store}/meta-pixel', [MetaPixelSettingController::class, 'show']);
    Route::put('stores/{store}/meta-pixel', [MetaPixelSettingController::class, 'update']);

    // TikTok Pixel + Events API (credenciais server-side por loja)
    Route::get('stores/{store}/tiktok-pixel', [TikTokPixelSettingController::class, 'show']);
    Route::put('stores/{store}/tiktok-pixel', [TikTokPixelSettingController::class, 'update']);

    // Kwai Pixel + API server-side (credenciais criptografadas por loja)
    Route::get('stores/{store}/kwai-pixel', [KwaiPixelSettingController::class, 'show']);
    Route::put('stores/{store}/kwai-pixel', [KwaiPixelSettingController::class, 'update']);

    // Taboola Pixel + S2S postback (Account ID por loja)
    Route::get('stores/{store}/taboola-pixel', [TaboolaPixelSettingController::class, 'show']);
    Route::put('stores/{store}/taboola-pixel', [TaboolaPixelSettingController::class, 'update']);

    // Shopify — injeção do snippet de checkout no tema
    Route::post('stores/{store}/shopify/inject-checkout', [ShopifyController::class, 'injectCheckout']);
    Route::delete('stores/{store}/shopify/inject-checkout', [ShopifyController::class, 'removeCheckout']);

    // Domains (CRUD + verificação DNS + ativação)
    Route::get('stores/{store}/domains', [DomainController::class, 'index']);
    Route::post('stores/{store}/domains', [DomainController::class, 'store']);
    Route::delete('stores/{store}/domains/{domain}', [DomainController::class, 'destroy']);
    Route::post('stores/{store}/domains/{domain}/verify-dns', [DomainController::class, 'verifyDns']);
    Route::post('stores/{store}/domains/{domain}/activate', [DomainController::class, 'activate']);

    // Communications
    Route::post('/orders/{id}/notify', [CommunicationController::class, 'notifyOrder']);

    // Customers (Clientes)
    Route::get('stores/{store}/customers', [CustomerController::class, 'index']);
    Route::get('stores/{store}/customers/{customer}', [CustomerController::class, 'show']);

    // Order Bumps
    Route::get('stores/{store}/order-bumps', [OrderBumpController::class, 'index']);
    Route::post('stores/{store}/order-bumps', [OrderBumpController::class, 'store']);
    Route::put('stores/{store}/order-bumps/{orderBump}', [OrderBumpController::class, 'update']);
    Route::delete('stores/{store}/order-bumps/{orderBump}', [OrderBumpController::class, 'destroy']);

    // Upsells
    Route::get('stores/{store}/upsells', [UpsellController::class, 'index']);
    Route::post('stores/{store}/upsells', [UpsellController::class, 'store']);
    Route::put('stores/{store}/upsells/{upsell}', [UpsellController::class, 'update']);
    Route::delete('stores/{store}/upsells/{upsell}', [UpsellController::class, 'destroy']);

    // Coupons (Cupons)
    Route::get('stores/{store}/coupons', [CouponController::class, 'index']);
    Route::post('stores/{store}/coupons', [CouponController::class, 'store']);
    Route::put('stores/{store}/coupons/{coupon}', [CouponController::class, 'update']);
    Route::delete('stores/{store}/coupons/{coupon}', [CouponController::class, 'destroy']);

    // Abandoned Carts (Carrinhos Abandonados)
    Route::get('stores/{store}/abandoned-carts', [AbandonedCartController::class, 'index']);
    Route::get('stores/{store}/abandoned-carts/{cart}', [AbandonedCartController::class, 'show']);
    Route::patch('stores/{store}/abandoned-carts/{cart}/status', [AbandonedCartController::class, 'updateStatus']);

    // Live Checkout (Checkout ao vivo)
    Route::get('stores/{store}/live-checkout', [LiveCheckoutController::class, 'index']);

    // WhatsApp — Chips (instâncias/conexão)
    Route::get('stores/{store}/whatsapp/chips', [WhatsappChipController::class, 'index']);
    Route::post('stores/{store}/whatsapp/chips', [WhatsappChipController::class, 'store']);
    Route::post('stores/{store}/whatsapp/chips/sync', [WhatsappChipController::class, 'sync']);
    Route::get('stores/{store}/whatsapp/chips/{chip}/qr', [WhatsappChipController::class, 'qr']);
    Route::post('stores/{store}/whatsapp/chips/{chip}/logout', [WhatsappChipController::class, 'logout']);
    Route::delete('stores/{store}/whatsapp/chips/{chip}', [WhatsappChipController::class, 'destroy']);

    // WhatsApp — Templates
    Route::get('stores/{store}/whatsapp/templates', [WhatsappTemplateController::class, 'index']);
    Route::post('stores/{store}/whatsapp/templates', [WhatsappTemplateController::class, 'store']);
    Route::put('stores/{store}/whatsapp/templates/{template}', [WhatsappTemplateController::class, 'update']);
    Route::delete('stores/{store}/whatsapp/templates/{template}', [WhatsappTemplateController::class, 'destroy']);

    // WhatsApp — Logs (Entregas/Falhas)
    Route::get('stores/{store}/whatsapp/logs', [WhatsappLogController::class, 'index']);
    Route::delete('stores/{store}/whatsapp/logs/{log}', [WhatsappLogController::class, 'destroy']);

    // E-mail — Provedor SMTP (uma configuração por loja)
    Route::get('stores/{store}/email/smtp', [SmtpSettingController::class, 'show']);
    Route::put('stores/{store}/email/smtp', [SmtpSettingController::class, 'update']);
    Route::post('stores/{store}/email/smtp/test', [SmtpSettingController::class, 'test']);
    Route::delete('stores/{store}/email/smtp', [SmtpSettingController::class, 'destroy']);

    // E-mail — Templates (HTML)
    Route::get('stores/{store}/email/templates', [EmailTemplateController::class, 'index']);
    Route::post('stores/{store}/email/templates', [EmailTemplateController::class, 'store']);
    Route::put('stores/{store}/email/templates/{template}', [EmailTemplateController::class, 'update']);
    Route::delete('stores/{store}/email/templates/{template}', [EmailTemplateController::class, 'destroy']);

    // E-mail — Logs (Entregas/Falhas)
    Route::get('stores/{store}/email/logs', [EmailLogController::class, 'index']);
    Route::delete('stores/{store}/email/logs/{log}', [EmailLogController::class, 'destroy']);
});

// Administração da plataforma: autorização no servidor, nunca por e-mail no frontend.
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin')->group(function () {
    Route::get('achievements', [AdminAchievementController::class, 'index']);
    Route::get('audit-logs', [AdminAchievementController::class, 'auditLogs']);
    Route::post('achievements', [AdminAchievementController::class, 'store']);
    Route::put('achievements/{achievement}', [AdminAchievementController::class, 'update']);
    Route::delete('achievements/{achievement}', [AdminAchievementController::class, 'destroy']);
    Route::post('achievements/{achievement}/image', [AdminAchievementController::class, 'upload'])
        ->middleware('throttle:30,1');
    Route::post('stores/{store}/achievements/recalculate', [AdminAchievementController::class, 'recalculate'])
        ->middleware('throttle:30,1');
});
