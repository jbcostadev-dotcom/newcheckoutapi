<?php

namespace App\Services\Ssl;

use App\Models\Domain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareCustomHostnameService
{
    private string $apiToken;

    private string $zoneId;

    private string $cnameTarget;

    private int $timeout;

    public function __construct()
    {
        $this->apiToken = (string) config('services.cloudflare.api_token');
        $this->zoneId = (string) config('services.cloudflare.zone_id');
        $this->cnameTarget = strtolower(rtrim((string) config('services.cloudflare.saas_cname_target'), '.'));
        $this->timeout = (int) config('services.cloudflare.timeout', 10);
    }

    public function cnameTarget(): string
    {
        if ($this->cnameTarget === '') {
            throw new RuntimeException('CLOUDFLARE_SAAS_CNAME_TARGET nao foi configurado.');
        }

        return $this->cnameTarget;
    }

    /**
     * Cria o Custom Hostname ou recupera o cadastro existente.
     * A busca previa torna a operacao segura para repeticao apos timeouts.
     */
    public function provision(string $hostname): array
    {
        $existing = $this->findByHostname($hostname);

        if ($existing !== null) {
            return $existing;
        }

        $response = $this->client()->post($this->customHostnamesPath(), [
            'hostname' => $hostname,
            'ssl' => $this->sslConfiguration(),
        ]);

        return $this->result($response, 'criar o Custom Hostname');
    }

    public function findByHostname(string $hostname): ?array
    {
        $response = $this->client()->get($this->customHostnamesPath(), [
            'hostname' => $hostname,
            'per_page' => 50,
        ]);

        $results = $this->resultList($response, 'consultar o Custom Hostname');

        foreach ($results as $result) {
            if (strtolower((string) ($result['hostname'] ?? '')) === strtolower($hostname)) {
                return $result;
            }
        }

        return null;
    }

    public function get(string $customHostnameId): array
    {
        $response = $this->client()->get($this->customHostnamesPath().'/'.$customHostnameId);

        return $this->result($response, 'consultar o status do Custom Hostname');
    }

    /**
     * Com validacao HTTP, a Cloudflare exige um PATCH depois que o CNAME
     * estiver configurado. Reenviar a mesma configuracao tambem reinicia o
     * backoff de validacao de forma idempotente.
     */
    public function refreshValidation(string $customHostnameId): array
    {
        $response = $this->client()->patch(
            $this->customHostnamesPath().'/'.$customHostnameId,
            ['ssl' => $this->sslConfiguration()],
        );

        return $this->result($response, 'reiniciar a validacao do Custom Hostname');
    }

    public function delete(?string $customHostnameId, string $hostname): void
    {
        if (!$customHostnameId) {
            $customHostnameId = $this->findByHostname($hostname)['id'] ?? null;
        }

        if (!$customHostnameId) {
            return;
        }

        $response = $this->client()->delete($this->customHostnamesPath().'/'.$customHostnameId);

        if ($response->status() === 404) {
            return;
        }

        $this->ensureSuccessful($response, 'remover o Custom Hostname');
    }

    public function syncDomain(Domain $domain): bool
    {
        if (!$domain->cloudflare_custom_hostname_id) {
            $state = $this->provision($domain->domain);
        } elseif (!$domain->ssl_active || $domain->status !== 'active') {
            $state = $this->refreshValidation($domain->cloudflare_custom_hostname_id);
        } else {
            $state = $this->get($domain->cloudflare_custom_hostname_id);
        }

        return $this->applyDomainState($domain, $state);
    }

    public function applyDomainState(Domain $domain, array $state): bool
    {
        $hostnameStatus = strtolower((string) ($state['status'] ?? 'pending'));
        $sslStatus = strtolower((string) data_get($state, 'ssl.status', 'pending'));
        $isActive = $hostnameStatus === 'active' && $sslStatus === 'active';
        $terminalStatuses = [
            'blocked',
            'deleted',
            'expired',
            'failed',
            'inactive',
            'moved',
            'initializing_timed_out',
            'validation_timed_out',
            'issuance_timed_out',
            'deployment_timed_out',
            'deletion_timed_out',
        ];
        $hasFailed = in_array($hostnameStatus, $terminalStatuses, true)
            || in_array($sslStatus, $terminalStatuses, true);

        $validationErrors = collect(data_get($state, 'ssl.validation_errors', []))
            ->concat($state['verification_errors'] ?? [])
            ->pluck('message')
            ->filter()
            ->implode(' ');

        $domain->update([
            'cloudflare_custom_hostname_id' => $state['id'] ?? $domain->cloudflare_custom_hostname_id,
            'cloudflare_hostname_status' => $hostnameStatus,
            'cloudflare_error' => $validationErrors !== '' ? $validationErrors : null,
            'cloudflare_synced_at' => now(),
            'status' => $isActive ? 'active' : ($hasFailed ? 'failed' : 'pending'),
            'ssl_status' => $sslStatus,
            'ssl_active' => $isActive,
            'dns_verified_at' => $isActive ? ($domain->dns_verified_at ?? now()) : $domain->dns_verified_at,
        ]);

        if ($isActive && $domain->store->custom_domain !== $domain->domain) {
            $domain->store->update(['custom_domain' => $domain->domain]);
            $domain->store->regenerateProductUrls();
        } elseif ($hasFailed && $domain->store->custom_domain === $domain->domain) {
            $domain->store->update(['custom_domain' => null]);
            $domain->store->regenerateProductUrls();
        }

        return $isActive;
    }

    private function client(): PendingRequest
    {
        if ($this->apiToken === '' || $this->zoneId === '') {
            throw new RuntimeException('Cloudflare for SaaS nao foi configurado na API.');
        }

        return Http::baseUrl('https://api.cloudflare.com/client/v4')
            ->withToken($this->apiToken)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(2, 250, throw: false);
    }

    private function customHostnamesPath(): string
    {
        return '/zones/'.$this->zoneId.'/custom_hostnames';
    }

    private function sslConfiguration(): array
    {
        return [
            'method' => 'http',
            'type' => 'dv',
            'wildcard' => false,
            'settings' => [
                'min_tls_version' => '1.2',
            ],
        ];
    }

    private function result(Response $response, string $operation): array
    {
        $this->ensureSuccessful($response, $operation);

        $result = $response->json('result');

        if (!is_array($result)) {
            throw new RuntimeException("A Cloudflare retornou uma resposta invalida ao {$operation}.");
        }

        return $result;
    }

    private function resultList(Response $response, string $operation): array
    {
        $this->ensureSuccessful($response, $operation);

        $result = $response->json('result', []);

        return is_array($result) ? $result : [];
    }

    private function ensureSuccessful(Response $response, string $operation): void
    {
        if ($response->successful() && $response->json('success', true) !== false) {
            return;
        }

        $messages = collect($response->json('errors', []))
            ->pluck('message')
            ->filter()
            ->implode(' ');

        $detail = $messages !== '' ? " {$messages}" : '';

        throw new RuntimeException("Nao foi possivel {$operation}.{$detail}");
    }
}
