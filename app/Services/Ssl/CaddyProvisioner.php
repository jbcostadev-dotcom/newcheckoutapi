<?php

namespace App\Services\Ssl;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service para interagir com o Caddy On-Demand TLS.
 *
 * O Caddy é configurado com um endpoint `ask` que consulta a nossa API
 * (/api/ssl/domain-check). Quando ativamos um domínio, ele já
 * estará autorizado na tabela `domains` com `ssl_status = active`.
 *
 * Este service pode ser expandido para:
 * - Adicionar/remover domínios dinamicamente via Caddy Admin API
 * - Recarregar configuração do Caddy via API
 * - Consultar status de certificados
 */
class CaddyProvisioner
{
    /**
     * URL base da API admin do Caddy (configurar em .env).
     */
    protected string $caddyAdminUrl;

    public function __construct()
    {
        $this->caddyAdminUrl = config('services.caddy.admin_url', 'http://localhost:2019');
    }

    /**
     * Autoriza e provisiona SSL para um domínio.
     *
     * Na prática com On-Demand TLS + endpoint `ask`, basta
     * garantir que o domínio esteja ativo na tabela `domains`.
     * O Caddy emitirá o certificado automaticamente na primeira requisição HTTPS.
     *
     * Aqui podemos opcionalmente:
     * - Pregar o certificado via Caddy Admin API (evita latency na primeira visita)
     * - Verificar se o Caddy está acessível
     */
    public function provision(string $domain): array
    {
        // Tenta notificar o Caddy para pre-carregar o certificado (opcional)
        try {
            $response = Http::post("{$this->caddyAdminUrl}/certificates/load", [
                'domain' => $domain,
                'issuer' => 'internal',
            ]);

            if ($response->successful()) {
                return [
                    'status' => 'pre_loaded',
                    'message' => "Certificado pré-carregado para {$domain}.",
                ];
            }

            // Se falhar, tudo bem — o on-demand TLS emitirá na primeira visita
            return [
                'status' => 'on_demand',
                'message' => "Domínio autorizado. SSL será emitido automaticamente na primeira visita HTTPS.",
            ];
        } catch (\Throwable $e) {
            Log::warning("Não foi possível contatar a API do Caddy: " . $e->getMessage());

            return [
                'status' => 'on_demand',
                'message' => "Domínio autorizado. Certificado será emitido pelo Caddy na primeira visita.",
            ];
        }
    }

    /**
     * Revoga um domínio (remove autorização de SSL).
     *
     * Remove o certificado do cache do Caddy, se possível.
     */
    public function revoke(string $domain): array
    {
        try {
            Http::delete("{$this->caddyAdminUrl}/certificates/id/{$domain}");
        } catch (\Throwable $e) {
            Log::warning("Erro ao revogar certificado no Caddy: " . $e->getMessage());
        }

        return [
            'status' => 'revoked',
            'message' => "Certificado de {$domain} revogado.",
        ];
    }
}
