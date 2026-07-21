<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ShopifyOrderSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Resumo de métricas para o dashboard.
     */
    public function metrics(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $today = now()->startOfDay();

        $revenueToday = $store->orders()
            ->where('status', 'paid')
            ->where('created_at', '>=', $today)
            ->sum('amount');

        $ordersPaid = $store->orders()
            ->where('status', 'paid')
            ->where('created_at', '>=', $today)
            ->count();

        $ordersTotal = $store->orders()->count();

        // Conversão simples: pedidos pagos / total (evita divisão por zero)
        $conversion = $ordersTotal > 0
            ? round(($ordersPaid / $ordersTotal) * 100, 1)
            : 0;

        // Últimos 5 pedidos para o dashboard
        $recentOrders = $store->orders()
            ->with('items.product:id,name,image_url,price')
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'revenue_today' => round((float) $revenueToday, 2),
            'orders_paid' => $ordersPaid,
            'orders_total' => $ordersTotal,
            'conversion' => $conversion,
            'recent_orders' => $recentOrders,
        ]);
    }

    /**
     * Listar pedidos da loja com filtros e paginação.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $query = $store->orders()->with('items.product:id,name,image_url,price');

        // Filtro por status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtro por método de pagamento
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Busca por cliente (nome ou email)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        $orders = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($orders);
    }

    /**
     * Detalhes de um pedido específico.
     */
    public function show(Request $request, string $storeId, string $orderId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $order = $store->orders()->with('items.product', 'store')->findOrFail($orderId);

        return response()->json($order);
    }

    /**
     * Atualizar status do pedido manualmente.
     */
    public function updateStatus(Request $request, string $storeId, string $orderId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $order = $store->orders()->findOrFail($orderId);

        $validated = $request->validate([
            'status' => 'required|in:pending,paid,failed,refunded',
        ]);

        $previousStatus = $order->status;

        $order->update(['status' => $validated['status']]);

        // Transição manual para "paid" → marca o pedido Shopify como pago.
        if ($order->fresh()->isPaid() && $previousStatus !== Order::STATUS_PAID) {
            try {
                if ($store->isShopifyConnected()) {
                    app(ShopifyOrderSync::class)->markAsPaid($store, $order->fresh());
                }
            } catch (\Throwable $e) {
                Log::warning('Shopify order markAsPaid (manual) falhou', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($order);
    }
}
