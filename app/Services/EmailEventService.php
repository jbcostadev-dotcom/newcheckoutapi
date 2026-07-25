<?php

namespace App\Services;

use App\Models\AbandonedCart;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

class EmailEventService
{
    private const METHOD_LABELS = [
        'pix' => 'Pix',
        'credit_card' => 'Cartão de crédito',
        'boleto' => 'Boleto',
    ];

    public function __construct(private readonly SmtpService $smtp)
    {
    }

    public function dispatchForOrder(Store $store, string $event, Order $order): void
    {
        $vars = $this->orderVars($order);
        $this->send($store, $event, 'order:' . $order->id, $vars);
    }

    public function dispatchForCart(Store $store, string $event, AbandonedCart $cart): void
    {
        $vars = $this->cartVars($cart);
        $this->send($store, $event, 'cart:' . $cart->id, $vars);
    }

    private function send(Store $store, string $event, string $contextKey, array $vars): void
    {
        $templates = $store->emailTemplates()
            ->where('event', $event)
            ->where('is_active', true)
            ->get();

        if ($templates->isEmpty()) {
            return;
        }

        // Evita disparos duplicados para o mesmo evento/contexto.
        $alreadySent = EmailLog::where('store_id', $store->id)
            ->where('event', $event)
            ->where('context_key', $contextKey)
            ->where('status', EmailLog::STATUS_SENT)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $email = $vars['email'] ?? null;
        $validEmail = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

        $smtp = $store->smtpSettings()->where('is_active', true)->first();

        foreach ($templates as $template) {
            $subject = $this->render($template->subject ?? '', $vars);
            $body = $this->render($template->body_html ?? '', $vars);

            if (! $validEmail) {
                $this->log($store, $template, $event, $contextKey, $email, $subject, $body, EmailLog::STATUS_FAILED, 'E-mail do cliente inválido ou ausente.');
                continue;
            }

            if (! $smtp) {
                $this->log($store, $template, $event, $contextKey, $email, $subject, $body, EmailLog::STATUS_FAILED, 'Nenhum servidor SMTP configurado/ativo na loja.');
                continue;
            }

            try {
                $this->smtp->sendHtml($smtp, $validEmail, $subject, $body);
                $this->log($store, $template, $event, $contextKey, $validEmail, $subject, $body, EmailLog::STATUS_SENT, null, $smtp->id);
            } catch (\Throwable $e) {
                Log::warning('E-mail envio falhou', [
                    'store_id' => $store->id,
                    'event' => $event,
                    'context' => $contextKey,
                    'error' => $e->getMessage(),
                ]);
                $this->log($store, $template, $event, $contextKey, $validEmail, $subject, $body, EmailLog::STATUS_FAILED, $e->getMessage(), $smtp->id);
            }
        }
    }

    private function log(Store $store, EmailTemplate $template, string $event, string $contextKey, ?string $email, string $subject, string $body, string $status, ?string $error = null, ?int $smtpSettingId = null): void
    {
        EmailLog::create([
            'store_id' => $store->id,
            'smtp_setting_id' => $smtpSettingId,
            'email_template_id' => $template->id,
            'event' => $event,
            'context_key' => $contextKey,
            'email' => $email,
            'subject' => $subject,
            'message' => $body,
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

    private function orderVars(Order $order): array
    {
        $firstName = trim(explode(' ', trim($order->customer_name ?? ''))[0] ?? '');

        $items = $order->items ?? collect();
        $products = $items->map(function ($item) {
            $qty = (int) ($item->qty ?? 1);
            return $item->name . ($qty > 1 ? ' x' . $qty : '');
        })->implode("\n") ?: '';

        $link = '';
        if ($firstItem = $items->first()) {
            $link = $firstItem->product?->checkout_url ?? '';
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
            'pix_copia_cola' => $order->pix_copia_cola ?? '',
            'boleto_url' => $order->boleto_url ?? '',
            'boleto_linha' => $order->boleto_digitable_line ?? '',
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
            ? rtrim(config('app.url'), '/') . '/checkout/recover/' . $cart->recovery_token
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
            'pix_copia_cola' => '',
            'boleto_url' => '',
            'boleto_linha' => '',
        ];
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
