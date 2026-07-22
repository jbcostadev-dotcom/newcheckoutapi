<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\StoreController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\CheckoutSettingController;
use App\Http\Controllers\API\ShopifyController;
use App\Http\Controllers\API\CheckoutController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\DomainController;
use App\Http\Controllers\API\CommunicationController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\GatewayController;
use App\Http\Controllers\API\SslController;
use App\Http\Controllers\API\ShippingMethodController;
use App\Http\Controllers\API\SocialProofController;
use App\Http\Controllers\API\CustomerController;
use App\Http\Controllers\API\OrderBumpController;
use App\Http\Controllers\API\CouponController;
use App\Http\Controllers\API\AbandonedCartController;

// ── Rotas públicas ──────────────────────────────────────────────────

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

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

// Shopify checkout redirect (público — chamado pelo snippet injetado no tema)
Route::post('/shopify/checkout-redirect', [ShopifyController::class, 'checkoutRedirect']);

// Customer registration (público — chamado durante o checkout)
Route::post('/checkout/customer', [CustomerController::class, 'register']);
Route::post('/checkout/customer/address', [CustomerController::class, 'updateAddress']);

// Abandoned cart tracking (público — chamado durante o checkout)
Route::post('/checkout/abandoned-cart', [AbandonedCartController::class, 'track']);

// SSL validation — público (consultado pelo Caddy On-Demand TLS)
Route::get('/ssl/domain-check', [SslController::class, 'domainCheck']);

// ── Rotas autenticadas (Sanctum) ──────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

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

    // Coupons (Cupons)
    Route::get('stores/{store}/coupons', [CouponController::class, 'index']);
    Route::post('stores/{store}/coupons', [CouponController::class, 'store']);
    Route::put('stores/{store}/coupons/{coupon}', [CouponController::class, 'update']);
    Route::delete('stores/{store}/coupons/{coupon}', [CouponController::class, 'destroy']);

    // Abandoned Carts (Carrinhos Abandonados)
    Route::get('stores/{store}/abandoned-carts', [AbandonedCartController::class, 'index']);
    Route::get('stores/{store}/abandoned-carts/{cart}', [AbandonedCartController::class, 'show']);
    Route::patch('stores/{store}/abandoned-carts/{cart}/status', [AbandonedCartController::class, 'updateStatus']);
});
