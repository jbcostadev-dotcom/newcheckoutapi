<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CheckoutFunnelSession;
use App\Models\Order;
use App\Services\ShopifyOrderSync;
use App\Services\UtmifyService;
use Carbon\Carbon;
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

        $funnelSessions = CheckoutFunnelSession::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$periodStart, $now]);
        $funnelEntered = (clone $funnelSessions)->count();
        $funnelPersonalData = (clone $funnelSessions)
            ->whereIn('furthest_stage', [
                CheckoutFunnelSession::STAGE_PERSONAL_DATA,
                CheckoutFunnelSession::STAGE_DELIVERY,
            ])
            ->count();
        $funnelDelivery = (clone $funnelSessions)
            ->where('furthest_stage', CheckoutFunnelSession::STAGE_DELIVERY)
            ->count();
        $funnelApproved = (clone $funnelSessions)
            ->where('payment_approved', true)
            ->count();
        $funnelConversion = $funnelEntered > 0
            ? round(($funnelApproved / $funnelEntered) * 100, 1)
            : 0;

        $approvedByMethod = (clone $periodOrders)
            ->where('status', Order::STATUS_PAID)
            ->select('payment_method')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');
        $approvedPaymentsTotal = (int) $approvedByMethod->sum();
        $paymentMethods = collect([
            ['method' => 'credit_card', 'label' => 'Cartão'],
            ['method' => 'pix', 'label' => 'Pix'],
            ['method' => 'boleto', 'label' => 'Boleto'],
        ])->map(function (array $method) use ($approvedByMethod, $approvedPaymentsTotal) {
            $count = (int) ($approvedByMethod[$method['method']] ?? 0);

            return [
                ...$method,
                'count' => $count,
                'percentage' => $approvedPaymentsTotal > 0
                    ? round(($count / $approvedPaymentsTotal) * 100, 1)
                    : 0,
            ];
        })->all();

        $salesByState = (clone $periodOrders)
            ->where('status', Order::STATUS_PAID)
            ->whereNotNull('shipping_uf')
            ->where('shipping_uf', '<>', '')
            ->selectRaw('UPPER(shipping_uf) as state')
            ->selectRaw('COUNT(*) as sales')
            ->selectRaw('SUM(amount) as revenue')
            ->groupByRaw('UPPER(shipping_uf)')
            ->orderByDesc('sales')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'state' => $row->state,
                'sales' => (int) $row->sales,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();

        $paymentStatusCounts = (clone $periodOrders)
            ->whereIn('payment_method', ['credit_card', 'pix', 'boleto'])
            ->select('payment_method', 'status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('payment_method', 'status')
            ->get();

        $paymentConversions = collect([
            ['method' => 'credit_card', 'label' => 'Cartão', 'basis' => 'decided'],
            ['method' => 'pix', 'label' => 'Pix', 'basis' => 'generated'],
            ['method' => 'boleto', 'label' => 'Boleto', 'basis' => 'generated'],
        ])->map(function (array $method) use ($paymentStatusCounts) {
            $rows = $paymentStatusCounts->where('payment_method', $method['method']);
            $approved = (int) $rows
                ->where('status', Order::STATUS_PAID)
                ->sum('total');
            $refused = (int) $rows
                ->whereIn('status', [Order::STATUS_REFUSED, Order::STATUS_FAILED])
                ->sum('total');
            $generated = $method['basis'] === 'decided'
                ? $approved + $refused
                : (int) $rows->sum('total');

            return [
                ...$method,
                'approved' => $approved,
                'generated' => $generated,
                'refused' => $refused,
                'conversion' => $generated > 0
                    ? round(($approved / $generated) * 100, 1)
                    : 0,
            ];
        })->all();

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
            'checkout_funnel' => [
                'conversion' => $funnelConversion,
                'stages' => [
                    ['key' => 'entered', 'label' => 'Entraram', 'count' => $funnelEntered],
                    ['key' => 'personal_data', 'label' => 'Dados pessoais', 'count' => $funnelPersonalData],
                    ['key' => 'delivery', 'label' => 'Entrega', 'count' => $funnelDelivery],
                    ['key' => 'approved', 'label' => 'Aprovados', 'count' => $funnelApproved],
                ],
            ],
            'payment_methods' => $paymentMethods,
            'sales_by_state' => $salesByState,
            'payment_conversions' => $paymentConversions,
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

        $this->applyOrderFilters($query, $request);

        $orders = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($orders);
    }

    /**
     * Baixar todos os pedidos que correspondem aos filtros ativos.
     */
    public function export(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $query = $store->orders()->with('items:id,order_id,name,qty,unit_price');

        $this->applyOrderFilters($query, $request);

        $filename = sprintf('pedidos-%s-%s.csv', $store->id, now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Número do pedido',
                'Cliente',
                'E-mail',
                'Telefone',
                'Documento',
                'Itens',
                'Valor',
                'Método de pagamento',
                'Status',
                'Data',
            ], ';', '"', '');

            foreach ($query->latest()->lazy(500) as $order) {
                $items = $order->items
                    ->map(fn ($item) => sprintf('%dx %s', $item->qty, $item->name))
                    ->implode(' | ');

                fputcsv($output, array_map([$this, 'safeCsvValue'], [
                    $order->id,
                    $order->customer_name,
                    $order->customer_email,
                    $order->customer_phone,
                    $order->customer_document,
                    $items,
                    number_format((float) $order->amount, 2, ',', ''),
                    $this->paymentMethodLabel($order->payment_method),
                    $this->orderStatusLabel($order->status),
                    $order->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                ]), ';', '"', '');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Aplica os mesmos filtros à listagem e à exportação.
     */
    private function applyOrderFilters($query, Request $request): void
    {
        $request->validate([
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
        ]);

        // Filtro por status
        if ($request->filled('status') && $request->status !== 'all') {
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

        if ($request->filled('start_at')) {
            $query->where('created_at', '>=', Carbon::parse($request->string('start_at')->toString()));
        }

        if ($request->filled('end_at')) {
            $query->where('created_at', '<', Carbon::parse($request->string('end_at')->toString()));
        }
    }

    private function safeCsvValue(mixed $value): string
    {
        $string = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $string) === 1 ? "'{$string}" : $string;
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'pix' => 'PIX',
            'credit_card' => 'Cartão',
            'boleto' => 'Boleto',
            default => $method ?? '',
        };
    }

    private function orderStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pendente',
            'processing' => 'Processando',
            'waiting_payment' => 'Aguardando pagamento',
            'in_analysis' => 'Em análise',
            'authorized' => 'Autorizado',
            'paid' => 'Pago',
            'failed', 'refused' => 'Recusado',
            'refunded' => 'Reembolsado',
            'chargedback' => 'Chargeback',
            'in_protest' => 'Em protesto',
            'canceled' => 'Cancelado',
            default => $status ?? '',
        };
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
