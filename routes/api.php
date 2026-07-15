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

// ── Rotas públicas ──────────────────────────────────────────────────

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Shopify OAuth (público — redirecionamento)
Route::get('/shopify/install', [ShopifyController::class, 'install']);
Route::get('/shopify/callback', [ShopifyController::class, 'callback']);

// Checkout público
Route::get('/checkout', [CheckoutController::class, 'show']);
Route::post('/checkout/process', [PaymentController::class, 'process']);
Route::post('/webhook/suitpay', [PaymentController::class, 'webhook']);

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

    // Shopify (status e sync)
    Route::get('stores/{store}/shopify/status', [ShopifyController::class, 'status']);
    Route::post('stores/{store}/shopify/sync', [ShopifyController::class, 'sync']);

    // Domains (CRUD + verificação DNS + ativação)
    Route::get('stores/{store}/domains', [DomainController::class, 'index']);
    Route::post('stores/{store}/domains', [DomainController::class, 'store']);
    Route::delete('stores/{store}/domains/{domain}', [DomainController::class, 'destroy']);
    Route::post('stores/{store}/domains/{domain}/verify-dns', [DomainController::class, 'verifyDns']);
    Route::post('stores/{store}/domains/{domain}/activate', [DomainController::class, 'activate']);

    // Communications
    Route::post('/orders/{id}/notify', [CommunicationController::class, 'notifyOrder']);
});
