<?php

namespace App\Console\Commands;

use App\Models\AbandonedCart;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Store;
use App\Services\EmailEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessEmailRecovery extends Command
{
    protected $signature = 'email:process-recovery';

    protected $description = 'Dispara recuperações por e-mail: PIX não pago, Carrinho abandonado.';

    private const PIX_UNPAID_DELAY_MINUTES = 10;

    private const PIX_UNPAID_BEFORE_EXPIRY_MINUTES = 30;

    private const CART_ABANDONED_DELAY_MINUTES = 30;

    public function handle(): int
    {
        $dispatcher = app(EmailEventService::class);

        // ── PIX não pago: pedidos PIX ainda pendentes, entre 10 e 30 minutos ──
        $orders = Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_WAITING_PAYMENT])
            ->where('payment_method', 'pix')
            ->where('created_at', '<=', Carbon::now()->subMinutes(self::PIX_UNPAID_DELAY_MINUTES))
            ->where('created_at', '>', Carbon::now()->subMinutes(self::PIX_UNPAID_BEFORE_EXPIRY_MINUTES))
            ->get();

        $pixCount = 0;
        foreach ($orders as $order) {
            try {
                $store = $order->store ?? Store::find($order->store_id);
                $dispatcher->dispatchForOrder($store, EmailTemplate::EVENT_PIX_UNPAID, $order);
                $pixCount++;
            } catch (\Throwable $e) {
                Log::warning('E-mail pix_unpaid dispatch falhou', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Carrinho abandonado: abertos sem pedido, parados antes de tentar pagar ──
        $carts = AbandonedCart::query()
            ->where('status', AbandonedCart::STATUS_OPEN)
            ->whereNull('order_id')
            ->where('step_reached', '!=', AbandonedCart::STEP_PAGAMENTO_TENTADO)
            ->where('last_activity_at', '<=', Carbon::now()->subMinutes(self::CART_ABANDONED_DELAY_MINUTES))
            ->get();

        $cartCount = 0;
        foreach ($carts as $cart) {
            try {
                $store = Store::find($cart->store_id);
                $dispatcher->dispatchForCart($store, EmailTemplate::EVENT_CART_ABANDONED, $cart);
                $cartCount++;
            } catch (\Throwable $e) {
                Log::warning('E-mail cart_abandoned dispatch falhou', [
                    'cart_id' => $cart->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Recuperações de e-mail processadas: {$pixCount} PIX não pago, {$cartCount} carrinho abandonado.");

        return self::SUCCESS;
    }
}
