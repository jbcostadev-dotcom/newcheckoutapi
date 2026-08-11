<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ShopifyOrderSync;
use App\Services\UtmifyService;
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

        $period = $request->string('period', 'today')->toString();
        if (! in_array($period, ['today', 'week', 'month', 'year'], true)) {
            $period = 'today';
        }

        $now = now();
        [$periodStart, $series] = $this->salesSeriesForPeriod($period, $now);
        $periodOrders = $store->orders()
            ->whereBetween('created_at', [$periodStart, $now]);

        $revenueTotal = (clone $periodOrders)
            ->where('status', Order::STATUS_PAID)
            ->sum('amount');

        $ordersPaid = (clone $periodOrders)
            ->where('status', Order::STATUS_PAID)
            ->count();

        $ordersTotal = (clone $periodOrders)->count();
        $ordersPending = (clone $periodOrders)
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_PROCESSING,
                Order::STATUS_WAITING_PAYMENT,
                Order::STATUS_IN_ANALYSIS,
            ])
            ->count();
        $ordersFailed = (clone $periodOrders)
            ->whereIn('status', [
                Order::STATUS_FAILED,
                Order::STATUS_REFUSED,
                Order::STATUS_CANCELED,
            ])
            ->count();

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

        foreach ((clone $periodOrders)
            ->where('status', Order::STATUS_PAID)
            ->orderBy('created_at')
            ->cursor(['amount', 'created_at']) as $order) {
            $bucket = $this->salesSeriesBucket($period, $periodStart, $order->created_at);
            if (isset($series[$bucket])) {
                $series[$bucket]['value'] = round(
                    $series[$bucket]['value'] + (float) $order->amount,
                    2,
                );
                $series[$bucket]['orders']++;
            }
        }

        return response()->json([
            'period' => $period,
            'revenue_today' => round((float) $revenueTotal, 2),
            'revenue_total' => round((float) $revenueTotal, 2),
            'orders_paid' => $ordersPaid,
            'orders_total' => $ordersTotal,
            'orders_pending' => $ordersPending,
            'orders_failed' => $ordersFailed,
            'conversion' => $conversion,
            'sales_series' => array_values($series),
            'recent_orders' => $recentOrders,
        ]);
    }

    private function salesSeriesForPeriod(string $period, $now): array
    {
        if ($period === 'today') {
            $start = $now->copy()->startOfDay();
            $series = collect(range(0, 5))->map(fn (int $index) => [
                'label' => str_pad((string) ($index * 4), 2, '0', STR_PAD_LEFT).'h',
                'value' => 0.0,
                'orders' => 0,
            ])->all();

            return [$start, $series];
        }

        if ($period === 'week') {
            $start = $now->copy()->startOfDay()->subDays(6);
            $series = collect(range(0, 6))->map(fn (int $index) => [
                'label' => $start->copy()->addDays($index)->format('d/m'),
                'value' => 0.0,
                'orders' => 0,
            ])->all();

            return [$start, $series];
        }

        if ($period === 'month') {
            $start = $now->copy()->startOfDay()->subDays(29);
            $series = collect(range(0, 5))->map(fn (int $index) => [
                'label' => $start->copy()->addDays($index * 5)->format('d/m'),
                'value' => 0.0,
                'orders' => 0,
            ])->all();

            return [$start, $series];
        }

        $start = $now->copy()->startOfMonth()->subMonths(11);
        $series = collect(range(0, 11))->map(fn (int $index) => [
            'label' => $start->copy()->addMonths($index)->format('m/y'),
            'value' => 0.0,
            'orders' => 0,
        ])->all();

        return [$start, $series];
    }

    private function salesSeriesBucket(string $period, $start, $createdAt): int
    {
        if ($period === 'today') {
            return intdiv((int) $createdAt->format('G'), 4);
        }

        if ($period === 'week') {
            return (int) floor($start->diffInDays($createdAt));
        }

        if ($period === 'month') {
            return min(5, intdiv((int) floor($start->diffInDays($createdAt)), 5));
        }

        return (int) floor($start->diffInMonths($createdAt));
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

        // Reenvia à Utmify quando o status muda manualmente (paid/refunded, etc.).
        try {
            app(UtmifyService::class)->dispatchForOrder($order->fresh());
        } catch (\Throwable $e) {
            Log::warning('Utmify dispatch (manual) falhou', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($order);
    }
}
