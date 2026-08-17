<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\WebhookUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(public int $deliveryId)
    {
    }

    public function backoff(): array
    {
        return [60, 60];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('webhook')->find($this->deliveryId);
        if (! $delivery || $delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        if (! $delivery->webhook || ! $delivery->webhook->is_active) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_SKIPPED,
                'last_error' => 'Webhook removido ou inativo antes da entrega.',
            ]);
            return;
        }

        $webhook = $delivery->webhook;
        $resolvedIp = WebhookUrlGuard::resolvePublicAddress($webhook->url);
        $parts = parse_url($webhook->url);
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));
        $curlAddress = str_contains($resolvedIp, ':') ? "[{$resolvedIp}]" : $resolvedIp;

        $delivery->update([
            'status' => WebhookDelivery::STATUS_PROCESSING,
            'attempt_count' => $delivery->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error' => null,
        ]);

        $options = ['allow_redirects' => false];
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$curlAddress}"]];
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->withOptions($options);

            if ($webhook->token !== '') {
                $request = $request->withToken($webhook->token);
            }

            $payload = $delivery->payload;
            $payload['integrationsPartners'] = (object) ($payload['integrationsPartners'] ?? []);

            $response = $request->post($webhook->url, $payload);
            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 2000),
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Endpoint respondeu HTTP {$response->status()}.");
            }

            $delivery->update([
                'status' => WebhookDelivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $delivery->refresh()->update([
                'status' => WebhookDelivery::STATUS_PENDING,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::whereKey($this->deliveryId)->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'last_error' => mb_substr($exception?->getMessage() ?? 'Falha permanente na entrega.', 0, 2000),
        ]);
    }
}
