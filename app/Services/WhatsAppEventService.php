<?php

namespace App\Services;

use App\Models\AbandonedCart;
use App\Models\Order;
use App\Models\Store;
use App\Models\WhatsappInstance;
use App\Models\WhatsappLog;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Log;

class WhatsAppEventService
{
    private const METHOD_LABELS = [
        'pix' => 'Pix',
        'credit_card' => 'Cartão de crédito',
        'boleto' => 'Boleto',
    ];

    public function __construct(private readonly WahaService $waha)
    {
    }

    /**
     * Dispara mensagens para um evento associado a um pedido.
     */
    public function dispatchForOrder(Store $store, string $event, Order $order): void
    {
        $vars = $this->orderVars($store, $order);
        $this->send($store, $event, 'order:' . $order->id, $vars);
    }

    /**
     * Dispara mensagens para um evento associado a um carrinho abandonado.
     */
    public function dispatchForCart(Store $store, string $event, AbandonedCart $cart): void
    {
        $vars = $this->cartVars($cart);
        $this->send($store, $event, 'cart:' . $cart->id, $vars);
    }

    private function send(Store $store, string $event, string $contextKey, array $vars): void
    {
        $templates = $store->whatsappTemplates()
            ->where('event', $event)
            ->where('is_active', true)
            ->get();

        if ($templates->isEmpty()) {
            return;
        }

        // Evita disparos duplicados para o mesmo evento/contexto.
        $alreadySent = WhatsappLog::where('store_id', $store->id)
            ->where('event', $event)
            ->where('context_key', $contextKey)
            ->where('status', WhatsappLog::STATUS_SENT)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $phone = $vars['telefone'] ?? null;
        $normalized = $phone ? $this->normalizeChatId($phone) : null;

        $chip = $store->whatsappInstances()
            ->where('is_active', true)
            ->where('status', WhatsappInstance::STATUS_CONNECTED)
            ->orderByDesc('is_active')
            ->first();

        foreach ($templates as $template) {
            $message = $this->render($template->message ?? '', $vars);

            if (! $normalized) {
                $this->log($store, $template, $event, $contextKey, $phone, $message, WhatsappLog::STATUS_FAILED, 'Telefone inválido ou ausente.');
                continue;
            }

            if (! $chip) {
                $this->log($store, $template, $event, $contextKey, $phone, $message, WhatsappLog::STATUS_FAILED, 'Nenhum chip WhatsApp conectado.');
                continue;
            }
            if (! $this->waha->configured()) {
                $this->log($store, $template, $event, $contextKey, $phone, $message, WhatsappLog::STATUS_FAILED, 'Integração WAHA não configurada (WAHA_API_URL).');
                continue;
            }

            try {
                $this->waha->sendText($chip->session_name, $normalized, $message);
                $this->log($store, $template, $event, $contextKey, $phone, $message, WhatsappLog::STATUS_SENT, null, $chip);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp envio falhou', [
                    'store_id' => $store->id,
                    'event' => $event,
                    'context' => $contextKey,
                    'error' => $e->getMessage(),
                ]);
                $this->log($store, $template, $event, $contextKey, $phone, $message, WhatsappLog::STATUS_FAILED, $e->getMessage(), $chip);
            }
        }
    }

    private function log(Store $store, WhatsappTemplate $template, string $event, string $contextKey, ?string $phone, string $message, string $status, ?string $error = null, ?WhatsappInstance $chip = null): void
    {
        WhatsappLog::create([
            'store_id' => $store->id,
            'whatsapp_instance_id' => $chip?->id,
            'whatsapp_template_id' => $template->id,
            'event' => $event,
            'context_key' => $contextKey,
            'phone' => $phone,
            'message' => $message,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
            return (string) ($vars[$m[1]] ?? '');
        }, $template);
    }

    private function orderVars(Store $store, Order $order): array
    {
        $firstName = trim(explode(' ', trim($order->customer_name ?? ''))[0] ?? '');

        $items = $order->items ?? collect();
        $products = $items->map(function ($item) {
            $qty = (int) ($item->qty ?? 1);
            return $item->name . ($qty > 1 ? ' x' . $qty : '');
        })->implode("\n") ?: '';

        $link = '';
        if ($firstItem = $items->first()) {
            $link = app(\App\Services\CheckoutUrlGenerator::class)
                ->generateById($store, (int) $firstItem->product_id);
        }

        return [
            'nome' => $firstName ?: ($order->customer_name ?? ''),
            'email' => $order->customer_email ?: '',
            'telefone' => $order->customer_phone ?? '',
            'valor' => $this->formatMoney((float) $order->amount),
            'metodo' => self::METHOD_LABELS[$order->payment_method] ?? ($order->payment_method ?? ''),
            'pedido' => (string) $order->id,
            'produtos' => $products,
            'link' => $link,
        ];
    }

    private function cartVars(AbandonedCart $cart): array
    {
        $firstName = trim(explode(' ', trim($cart->customer_name ?? ''))[0] ?? '');

        $items = $cart->items ?? [];
        $products = collect($items)->map(function ($item) {
            $qty = (int) ($item['qty'] ?? 1);
            return ($item['name'] ?? 'Produto') . ($qty > 1 ? ' x' . $qty : '');
        })->implode("\n") ?: '';

        $link = $cart->recovery_token
            ? rtrim(config('app.url'), '/') . '/api/checkout/recover/' . $cart->recovery_token
            : '';

        return [
            'nome' => $firstName ?: ($cart->customer_name ?? ''),
            'email' => $cart->customer_email ?: '',
            'telefone' => $cart->customer_phone ?? '',
            'valor' => $this->formatMoney((float) $cart->total),
            'metodo' => '',
            'pedido' => '',
            'produtos' => $products,
            'link' => $link,
        ];
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    /**
     * Converte um telefone em um chatId válido para a WAHA ({ddi}{numero}@c.us).
     * Assume números brasileiros quando não há DDI explícito.
     */
    private function normalizeChatId(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '' || strlen($digits) > 15) {
            return null;
        }

        // Já inclui DDI 55.
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            return $digits . '@c.us';
        }

        // DDI diferente informado explicitamente (não começa com 55 e tem 8..15 dígitos).
        if (strlen($digits) > 11) {
            return $digits . '@c.us';
        }

        // Brasileiro sem DDI: 10 (fixo) ou 11 (com nono dígito) dígitos.
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits . '@c.us';
        }

        return null;
    }
}