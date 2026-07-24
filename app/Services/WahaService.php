<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WahaService
{
    private string $baseUrl;

    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.waha.url', 'http://localhost:3000'), '/');
        $this->apiKey = config('services.waha.key');
    }

    /**
     * Indica se a integração com a WAHA está configurada.
     */
    public function configured(): bool
    {
        return ! empty($this->baseUrl);
    }

    protected function client()
    {
        $client = Http::withHeaders(['Accept' => 'application/json'])
            ->baseUrl($this->baseUrl)
            ->timeout(15)
            ->connectTimeout(10);

        if ($this->apiKey) {
            $client = $client->withHeaders(['X-Api-Key' => $this->apiKey]);
        }

        return $client;
    }

    /**
     * Cria (e inicia) uma sessão na WAHA.
     *
     * @return array{name: string, status: string}
     */
    public function createSession(string $name): array
    {
        $response = $this->client()->post('/api/sessions', [
            'name' => $name,
            'start' => true,
        ]);

        return $this->decode($response);
    }

    /**
     * Retorna os dados de uma sessão (status + me).
     */
    public function getSession(string $name): ?array
    {
        $response = $this->client()->get('/api/sessions/' . urlencode($name));

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    public function startSession(string $name): array
    {
        $response = $this->client()->post('/api/sessions/' . urlencode($name) . '/start');

        return $this->decode($response);
    }

    public function logoutSession(string $name): array
    {
        $response = $this->client()->post('/api/sessions/' . urlencode($name) . '/logout');

        return $this->decode($response);
    }

    public function deleteSession(string $name): void
    {
        $response = $this->client()->delete('/api/sessions/' . urlencode($name));

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('WAHA deleteSession falhou', [
                'session' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Retorna o QR code em base64 (data URL) ou null se ainda não disponível.
     */
    public function getQrCode(string $name): ?string
    {
        $response = $this->client()->get('/api/' . urlencode($name) . '/auth/qr');

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['data'])) {
            return null;
        }

        $mimetype = $data['mimetype'] ?? 'image/png';
        $base64 = $data['data'];

        if (str_starts_with($base64, 'data:')) {
            return $base64;
        }

        return 'data:' . $mimetype . ';base64,' . $base64;
    }

    /**
     * Informa  telefone associado à sessão (me), se houver.
     */
    public function getMe(string $name): ?array
    {
        $response = $this->client()->get('/api/sessions/' . urlencode($name) . '/me');

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['id']) ? $data : null;
    }

    /**
     * Envia uma mensagem de texto. Retorna a resposta decodificada.
     *
     * @return array{id: ?string}
     */
    public function sendText(string $session, string $chatId, string $text): array
    {
        $response = $this->client()->post('/api/sendText', [
            'session' => $session,
            'chatId' => $chatId,
            'text' => $text,
        ]);

        if (! $response->successful()) {
            $body = $response->json() ?: $response->body();
            $message = is_array($body) && isset($body['message'])
                ? (string) $body['message']
                : ('WAHA erro ' . $response->status());

            throw new \RuntimeException($message, $response->status());
        }

        return $this->decode($response);
    }

    /**
     * Normaliza o status retornado pela WAHA para o domínio do sistema.
     */
    public static function mapStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'WORKING' => \App\Models\WhatsappInstance::STATUS_CONNECTED,
            'SCAN_QR_CODE' => \App\Models\WhatsappInstance::STATUS_QR_READY,
            'STARTING' => \App\Models\WhatsappInstance::STATUS_STARTING,
            'FAILED' => \App\Models\WhatsappInstance::STATUS_FAILED,
            default => \App\Models\WhatsappInstance::STATUS_DISCONNECTED,
        };
    }

    protected function decode(Response $response): array
    {
        if (! $response->successful()) {
            $body = $response->json() ?: $response->body();
            throw new \RuntimeException(
                is_array($body) && isset($body['message']) ? (string) $body['message'] : 'WAHA erro ' . $response->status(),
                $response->status()
            );
        }

        return $response->json() ?? [];
    }
}