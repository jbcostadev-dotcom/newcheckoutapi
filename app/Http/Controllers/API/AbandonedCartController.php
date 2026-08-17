<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCart;
use App\Models\CheckoutFunnelSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\WhatsappTemplate;
use App\Services\WhatsAppEventService;
use App\Models\EmailTemplate;
use App\Services\EmailEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AbandonedCartController extends Controller
{
    /**
     * Rastreia um carrinho abandonado durante o checkout.
     *
     * Chamado pelo frontend do checkout a cada transição de etapa ou quando
     * uma tentativa de pagamento falha.
     */
    public function track(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|required_without:store_id',
            'session_id' => 'nullable|string|max:64',
            'step_reached' => 'required|in:dados,entrega,pagamento,pagamento_tentado',
            'customer_name' => 'required|string|max:150',
            'customer_email' => 'required|email|max:150',
            'customer_phone' => 'nullable|string|max:20',
            'customer_document' => 'nullable|string|max:20',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.name' => 'required|string|max:255',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
            'total' => 'nullable|numeric|min:0',
            'shipping_address' => 'nullable|array',
            'shipping_address.cep' => 'nullable|string|max:9',
            'shipping_address.logradouro' => 'nullable|string|max:255',
            'shipping_address.numero' => 'nullable|string|max:30',
            'shipping_address.complemento' => 'nullable|string|max:120',
            'shipping_address.bairro' => 'nullable|string|max:120',
            'shipping_address.cidade' => 'nullable|string|max:120',
            'shipping_address.uf' => 'nullable|string|max:2',
            'shipping_method_id' => 'nullable|integer',
            'payment_method' => 'nullable|in:pix,credit_card,boleto',
            'abandoned_reason' => 'nullable|in:left_dados,left_entrega,left_pagamento,card_refused,pix_expired,boleto_expired',
            'card_brand' => 'nullable|string|max:30',
            'card_last4' => 'nullable|string|max:4',
            'order_id' => 'nullable|integer',
            'utm_source' => 'nullable|string|max:120',
            'utm_medium' => 'nullable|string|max:120',
            'utm_campaign' => 'nullable|string|max:120',
            'device_type' => 'nullable|string|max:50',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);

        if (! $store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $email = strtolower(trim($validated['customer_email']));

        // Busca o customer local, se existir.
        $customer = $store->customers()->where('email', $email)->first();

        // Busca carrinho em aberto para este e-mail + loja.
        $cart = null;
        if (! empty($validated['session_id'])) {
            $cart = AbandonedCart::forStore($store->id)
                ->where('session_id', $validated['session_id'])
                ->whereIn('status', [AbandonedCart::STATUS_OPEN, AbandonedCart::STATUS_EXPIRED])
                ->latest()
                ->first();
        }

        $cart ??= AbandonedCart::forStore($store->id)
            ->byEmail($email)
            ->whereIn('status', [AbandonedCart::STATUS_OPEN, AbandonedCart::STATUS_EXPIRED])
            ->latest()
            ->first();

        // Normaliza itens e valores.
        $items = $this->normalizeItems($validated['items']);
        $subtotal = (float) ($validated['subtotal'] ?? $this->calculateSubtotal($items));
        $total = (float) ($validated['total'] ?? $subtotal);

        // Resolve frete, se informado.
        $shippingMethodName = null;
        $shippingPrice = null;
        if (! empty($validated['shipping_method_id'])) {
            $shippingMethod = ShippingMethod::where('store_id', $store->id)
                ->where('id', $validated['shipping_method_id'])
                ->first();
            if ($shippingMethod) {
                $shippingMethodName = $shippingMethod->name;
                $shippingPrice = $shippingMethod->price;
            }
        }

        $common = [
            'store_id' => $store->id,
            'customer_id' => $customer?->id,
            'customer_name' => $validated['customer_name'],
            'customer_email' => $email,
            'customer_phone' => $validated['customer_phone'] ?? null,
            'customer_document' => $validated['customer_document'] ?? null,
            'items' => $items,
            'subtotal' => $subtotal,
            'total' => $total,
            'last_activity_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'utm_source' => $validated['utm_source'] ?? null,
            'utm_medium' => $validated['utm_medium'] ?? null,
            'utm_campaign' => $validated['utm_campaign'] ?? null,
            'device_type' => $validated['device_type'] ?? $this->guessDeviceType($request->userAgent()),
        ];

        if (! empty($validated['session_id'])) {
            $common['session_id'] = $validated['session_id'];
        }

        // Etapa atingida.
        $stepReached = $validated['step_reached'];

        // Se for tentativa de pagamento, atualiza os dados de pagamento.
        if (! empty($validated['payment_method'])) {
            $common['payment_method'] = $validated['payment_method'];
        }
        if (! empty($validated['shipping_address'])) {
            $common['shipping_address'] = $validated['shipping_address'];
        }
        if (! empty($validated['shipping_method_id'])) {
            $common['shipping_method_id'] = $validated['shipping_method_id'];
            $common['shipping_method_name'] = $shippingMethodName;
            $common['shipping_price'] = $shippingPrice;
        }
        if (! empty($validated['card_brand'])) {
            $common['card_brand'] = $validated['card_brand'];
        }
        if (! empty($validated['card_last4'])) {
            $common['card_last4'] = $validated['card_last4'];
        }
        if (! empty($validated['abandoned_reason'])) {
            $common['abandoned_reason'] = $validated['abandoned_reason'];
        }
        if (! empty($validated['order_id'])) {
            $common['order_id'] = $validated['order_id'];
        }

        if ($cart) {
            // Só evolui o funil; nunca volta a uma etapa anterior.
            $stepOrder = [
                AbandonedCart::STEP_DADOS => 1,
                AbandonedCart::STEP_ENTREGA => 2,
                AbandonedCart::STEP_PAGAMENTO => 3,
                AbandonedCart::STEP_PAGAMENTO_TENTADO => 4,
            ];
            $currentRank = $stepOrder[$cart->step_reached] ?? 0;
            $newRank = $stepOrder[$stepReached] ?? 0;

            if ($newRank > $currentRank) {
                $common['step_reached'] = $stepReached;
            }

            // Se estiver reabrindo um carrinho expirado, volta para open.
            if ($cart->status === AbandonedCart::STATUS_EXPIRED && $cart->isRecoverable()) {
                $common['status'] = AbandonedCart::STATUS_OPEN;
                $common['expired_at'] = null;
            }

            $cart->update($common);
        } else {
            $common['step_reached'] = $stepReached;
            $common['status'] = AbandonedCart::STATUS_OPEN;
            $common['recovery_token'] = bin2hex(random_bytes(32));
            $cart = AbandonedCart::create($common);
        }

        return response()->json([
            'abandoned_cart_id' => $cart->id,
            'recovery_token' => $cart->recovery_token,
        ]);
    }

    /**
     * Lista os carrinhos abandonados da loja autenticada.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $request->validate([
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
        ]);

        $query = AbandonedCart::forStore($store->id)
            ->with(['customer:id,name,email,phone', 'order:id,status,amount,payment_method']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('reason')) {
            $query->where('abandoned_reason', $request->reason);
        }

        if ($request->filled('step')) {
            $query->where('step_reached', $request->step);
        }

        if ($request->filled('start_at')) {
            $startAt = Carbon::parse($request->string('start_at')->toString());
            $query->where(function ($dateQuery) use ($startAt) {
                $dateQuery->where('last_activity_at', '>=', $startAt)
                    ->orWhere(function ($fallbackQuery) use ($startAt) {
                        $fallbackQuery->whereNull('last_activity_at')
                            ->where('created_at', '>=', $startAt);
                    });
            });
        }

        if ($request->filled('end_at')) {
            $endAt = Carbon::parse($request->string('end_at')->toString());
            $query->where(function ($dateQuery) use ($endAt) {
                $dateQuery->where('last_activity_at', '<', $endAt)
                    ->orWhere(function ($fallbackQuery) use ($endAt) {
                        $fallbackQuery->whereNull('last_activity_at')
                            ->where('created_at', '<', $endAt);
                    });
            });
        }

        $carts = $query->latest('last_activity_at')->paginate($request->get('per_page', 15));

        $carts->getCollection()->transform(function (AbandonedCart $cart) {
            $cart->items_count = collect($cart->items)->sum('qty');
            $cart->recovery_url = $cart->recovery_token
                ? $this->buildRecoveryUrl($cart)
                : null;
            return $cart;
        });

        return response()->json($carts);
    }

    /**
     * Detalhes de um carrinho abandonado.
     */
    public function show(Request $request, string $storeId, string $cartId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $cart = AbandonedCart::forStore($store->id)
            ->with(['customer', 'order'])
            ->findOrFail($cartId);

        $cart->items_count = collect($cart->items)->sum('qty');
        $cart->recovery_url = $cart->recovery_token
            ? $this->buildRecoveryUrl($cart)
            : null;

        return response()->json($cart);
    }

    /**
     * Redireciona um link de recuperação para o checkout da loja.
     * A rota é pública: quem tiver o token pode reabrir o carrinho.
     */
    public function recover(string $token)
    {
        $cart = AbandonedCart::where('recovery_token', $token)
            ->with('store')
            ->first();

        if (! $cart || ! $cart->store) {
            return response()->json(['error' => 'Link de recuperação inválido ou expirado.'], 404);
        }

        $urlGenerator = app(\App\Services\CheckoutUrlGenerator::class);

        $productIds = collect($cart->items ?? [])
            ->map(fn ($item) => array_fill(0, (int) ($item['qty'] ?? 1), (int) ($item['product_id'] ?? 0)))
            ->flatten()
            ->filter()
            ->values()
            ->all();

        if (empty($productIds)) {
            return response()->json(['error' => 'Carrinho vazio.'], 404);
        }

        $checkoutUrl = $urlGenerator->generateForCart($cart->store, $productIds);
        $checkoutUrl .= (str_contains($checkoutUrl, '?') ? '&' : '?') . 'recovery_token=' . urlencode($token);

        return redirect()->away($checkoutUrl);
    }

    /**
     * Monta a URL de recuperação usando o ID imutável da loja.
     */
    private function buildRecoveryUrl(AbandonedCart $cart): string
    {
        return rtrim(config('app.url'), '/')
            . '/api/checkout/recover/'
            . $cart->recovery_token;
    }

    /**
     * Atualiza o status de um carrinho abandonado.
     */
    public function updateStatus(Request $request, string $storeId, string $cartId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $cart = AbandonedCart::forStore($store->id)->findOrFail($cartId);

        $validated = $request->validate([
            'status' => 'required|in:open,recovered,converted,expired',
        ]);

        $update = ['status' => $validated['status']];
        if ($validated['status'] === AbandonedCart::STATUS_RECOVERED) {
            $update['recovered_at'] = now();
        }

        $cart->update($update);

        return response()->json($cart);
    }

    /**
     * Vincula um pedido a um carrinho abandonado em aberto, sem alterar o status.
     * Usado quando o cliente inicia o pagamento (PIX, boleto ou cartão).
     */
    public static function linkOrder(Store $store, Order $order): void
    {
        if (! $order->customer_email) {
            return;
        }

        $cart = AbandonedCart::forStore($store->id)
            ->byEmail($order->customer_email)
            ->whereIn('status', [AbandonedCart::STATUS_OPEN, AbandonedCart::STATUS_EXPIRED])
            ->latest()
            ->first();

        if (! $cart) {
            return;
        }

        $update = [
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'last_activity_at' => now(),
        ];

        // Se o endereço ainda não estiver salvo, copia do pedido.
        if (empty($cart->shipping_address) && $order->shipping_cep) {
            $update['shipping_address'] = [
                'cep' => $order->shipping_cep,
                'logradouro' => $order->shipping_logradouro,
                'numero' => $order->shipping_numero,
                'complemento' => $order->shipping_complemento,
                'bairro' => $order->shipping_bairro,
                'cidade' => $order->shipping_cidade,
                'uf' => $order->shipping_uf,
            ];
        }

        $cart->update($update);
    }

    /**
     * Marca um carrinho como convertido a partir de um pedido pago.
     * Método interno usado pelo fluxo de pedidos.
     */
    public static function markConvertedByOrder(Order $order): void
    {
        if (! $order->customer_email) {
            return;
        }

        $cart = AbandonedCart::forStore($order->store_id)
            ->byEmail($order->customer_email)
            ->whereIn('status', [AbandonedCart::STATUS_OPEN, AbandonedCart::STATUS_EXPIRED])
            ->latest()
            ->first();

        if (! $cart) {
            return;
        }

        $cart->update([
            'order_id' => $order->id,
            'status' => AbandonedCart::STATUS_CONVERTED,
            'recovered_at' => $cart->recovered_at ?? now(),
            'last_activity_at' => now(),
        ]);

        if ($cart->session_id) {
            CheckoutFunnelSession::markApproved(
                (int) $order->store_id,
                $cart->session_id,
            );
        }
    }

    /**
     * Marca um carrinho como abandonado por falha/recusa no pagamento.
     * Método interno usado pelo fluxo de pedidos.
     */
    public static function markPaymentFailed(
        Store $store,
        string $email,
        string $reason,
        ?Order $order = null,
        ?array $cardData = null
    ): void {
        $cart = AbandonedCart::forStore($store->id)
            ->byEmail($email)
            ->whereIn('status', [AbandonedCart::STATUS_OPEN, AbandonedCart::STATUS_EXPIRED])
            ->latest()
            ->first();

        $update = [
            'step_reached' => AbandonedCart::STEP_PAGAMENTO_TENTADO,
            'abandoned_reason' => $reason,
            'last_activity_at' => now(),
        ];

        if ($order) {
            $update['order_id'] = $order->id;
            $update['payment_method'] = $order->payment_method;
        }

        if ($cardData) {
            if (! empty($cardData['brand'])) {
                $update['card_brand'] = $cardData['brand'];
            }
            if (! empty($cardData['last4'])) {
                $update['card_last4'] = $cardData['last4'];
            }
        }

        if ($cart) {
            $cart->update($update);
        } else {
            // Sem carrinho prévio: cria um registro mínimo de abandono na etapa de pagamento.
            AbandonedCart::create(array_merge($update, [
                'store_id' => $store->id,
                'customer_email' => $email,
                'customer_name' => $order?->customer_name ?? $email,
                'items' => [],
                'subtotal' => 0,
                'total' => 0,
                'status' => AbandonedCart::STATUS_OPEN,
                'recovery_token' => bin2hex(random_bytes(32)),
            ]));
        }
    }

    /**
     * Marca o carrinho de um pedido específico como expirado caso o prazo tenha vencido.
     * Usado nos endpoints de consulta de status para funcionar sem cron.
     */
    public static function markExpiredPaymentForOrder(Order $order): bool
    {
        if (! in_array($order->payment_method, ['pix', 'boleto'], true)) {
            return false;
        }

        if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_WAITING_PAYMENT], true)) {
            return false;
        }

        $cutoff = Carbon::now()->subMinutes(30);
        if ($order->created_at->greaterThan($cutoff)) {
            return false;
        }

        $cart = AbandonedCart::forStore($order->store_id)
            ->where('order_id', $order->id)
            ->where('status', '!=', AbandonedCart::STATUS_CONVERTED)
            ->first();

        if (! $cart) {
            $cart = AbandonedCart::forStore($order->store_id)
                ->byEmail($order->customer_email)
                ->whereIn('status', [AbandonedCart::STATUS_OPEN, AbandonedCart::STATUS_EXPIRED])
                ->whereNull('order_id')
                ->latest()
                ->first();
        }

        if (! $cart) {
            return false;
        }

        $reason = $order->payment_method === 'pix'
            ? AbandonedCart::REASON_PIX_EXPIRED
            : AbandonedCart::REASON_BOLETO_EXPIRED;

        $alreadyExpired = $cart->status === AbandonedCart::STATUS_EXPIRED;

        $cart->update([
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'status' => AbandonedCart::STATUS_EXPIRED,
            'abandoned_reason' => $reason,
            'expired_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Dispara recuperação WhatsApp apenas para PIX expirado e apenas uma vez.
        if (! $alreadyExpired && $reason === AbandonedCart::REASON_PIX_EXPIRED) {
            try {
                app(WhatsAppEventService::class)->dispatchForOrder(
                    $order->store ?? Store::find($order->store_id),
                    WhatsappTemplate::EVENT_PIX_EXPIRED,
                    $order
                );
            } catch (\Throwable $e) {
                Log::warning('WhatsApp pix_expired dispatch falhou', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(EmailEventService::class)->dispatchForOrder(
                    $order->store ?? Store::find($order->store_id),
                    EmailTemplate::EVENT_PIX_EXPIRED,
                    $order
                );
            } catch (\Throwable $e) {
                Log::warning('E-mail pix_expired dispatch falhou', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * Marca carrinhos PIX/Boleto como expirados quando o prazo de pagamento vence.
     * Pode ser usado via cron/schedule para manter os dados atualizados mesmo sem consultas.
     */
    public static function markExpiredPayments(): int
    {
        $cutoff = Carbon::now()->subMinutes(30);

        $orders = Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_WAITING_PAYMENT])
            ->whereIn('payment_method', ['pix', 'boleto'])
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            if (self::markExpiredPaymentForOrder($order)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Normaliza os itens garantindo campos obrigatórios.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        return array_values(array_map(function ($item) {
            return [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($item['name'] ?? 'Produto'),
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
            ];
        }, $items));
    }

    /**
     * Calcula o subtotal a partir dos itens.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function calculateSubtotal(array $items): float
    {
        return collect($items)->reduce(function ($carry, $item) {
            return $carry + ((float) ($item['unit_price'] ?? 0) * (int) ($item['qty'] ?? 1));
        }, 0.0);
    }

    /**
     * Heurística simples para identificar desktop/mobile.
     */
    private function guessDeviceType(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $agent = Str::lower($userAgent);
        if (str_contains($agent, 'mobile') || str_contains($agent, 'android') || str_contains($agent, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($agent, 'tablet') || str_contains($agent, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }
}
