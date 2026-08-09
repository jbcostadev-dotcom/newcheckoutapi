<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Store;
use App\Services\Ssl\CloudflareCustomHostnameService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class DomainController extends Controller
{
    public function index(
        Request $request,
        string $storeId,
        CloudflareCustomHostnameService $cloudflare,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domains = $store->domains()->latest()->get();
        $domains->each(fn (Domain $domain) => $domain->setAttribute(
            'cloudflare_cname_target',
            $cloudflare->cnameTarget(),
        ));

        return response()->json($domains);
    }

    /**
     * Recebe o dominio-base do lojista e sempre provisiona checkout.<dominio>.
     */
    public function store(
        Request $request,
        string $storeId,
        CloudflareCustomHostnameService $cloudflare,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        try {
            $hostname = $this->checkoutHostname((string) $validated['domain']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $domainModel = DB::transaction(function () use ($store, $hostname) {
                $lockedStore = Store::query()
                    ->whereKey($store->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedStore->domains()->exists()) {
                    throw new \DomainException('Store already has a custom domain');
                }

                if (Domain::where('domain', $hostname)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Domain already in use');
                }

                return $lockedStore->domains()->create([
                    'domain' => $hostname,
                    'status' => 'pending',
                    'ssl_status' => 'pending',
                    'cloudflare_hostname_status' => 'pending',
                ]);
            });
        } catch (\DomainException) {
            return response()->json([
                'message' => 'Esta loja ja possui um dominio personalizado. Remova o dominio atual para cadastrar outro.',
            ], 422);
        } catch (UniqueConstraintViolationException|RuntimeException) {
            return response()->json([
                'message' => 'Este dominio ja esta em uso por outra loja.',
            ], 422);
        }

        try {
            $cloudflareState = $cloudflare->provision($hostname);
            $cloudflare->applyDomainState($domainModel, $cloudflareState);
        } catch (Throwable $exception) {
            // Se a requisicao tiver sido criada remotamente e a resposta se perder,
            // o proximo cadastro a recupera pela busca idempotente por hostname.
            $domainModel->delete();
            Log::error('Falha ao provisionar Custom Hostname na Cloudflare.', [
                'hostname' => $hostname,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Nao foi possivel iniciar a integracao com a Cloudflare. Tente novamente em instantes.',
            ], 502);
        }

        return response()->json([
            'domain' => $domainModel->fresh(),
            'instructions' => $this->instructions($cloudflare, $hostname),
        ], 201);
    }

    /**
     * Sincroniza os dois estados que definem prontidao na Cloudflare:
     * hostname e certificado SSL.
     */
    public function verifyDns(
        Request $request,
        string $storeId,
        string $domainId,
        CloudflareCustomHostnameService $cloudflare,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domain = $store->domains()->findOrFail($domainId);

        try {
            $isActive = $cloudflare->syncDomain($domain);
        } catch (Throwable $exception) {
            Log::warning('Falha ao sincronizar Custom Hostname na Cloudflare.', [
                'hostname' => $domain->domain,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Nao foi possivel consultar a Cloudflare agora. Tente novamente em instantes.',
            ], 502);
        }

        $foundCname = $this->findCname($domain->domain);
        $expectedCname = $cloudflare->cnameTarget();

        $dnsConfigured = ($foundCname !== null && $foundCname === $expectedCname)
            || $domain->cloudflare_hostname_status === 'active';

        return response()->json([
            'verified' => $isActive,
            'dns_configured' => $dnsConfigured,
            'found_cname' => $foundCname,
            'expected_cname' => $expectedCname,
            'hostname_status' => $domain->cloudflare_hostname_status,
            'ssl_status' => $domain->ssl_status,
            'error' => $domain->cloudflare_error,
            'domain' => $domain->fresh(),
        ]);
    }

    /**
     * Mantido para compatibilidade com clientes antigos. Nunca ativa antes da
     * confirmacao de hostname e SSL pela Cloudflare.
     */
    public function activate(
        Request $request,
        string $storeId,
        string $domainId,
        CloudflareCustomHostnameService $cloudflare,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domain = $store->domains()->findOrFail($domainId);

        try {
            $isActive = $cloudflare->syncDomain($domain);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Nao foi possivel consultar a Cloudflare agora.',
            ], 502);
        }

        if (!$isActive) {
            return response()->json([
                'message' => 'A Cloudflare ainda esta validando o DNS ou emitindo o certificado.',
                'domain' => $domain->fresh(),
            ], 422);
        }

        return response()->json([
            'message' => "Dominio {$domain->domain} ativado com sucesso.",
            'domain' => $domain->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        string $storeId,
        string $domainId,
        CloudflareCustomHostnameService $cloudflare,
    ) {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domain = $store->domains()->findOrFail($domainId);

        try {
            $cloudflare->delete($domain->cloudflare_custom_hostname_id, $domain->domain);
        } catch (Throwable $exception) {
            Log::warning('Falha ao remover Custom Hostname na Cloudflare.', [
                'hostname' => $domain->domain,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Nao foi possivel remover o dominio da Cloudflare. Tente novamente.',
            ], 502);
        }

        if ($store->custom_domain === $domain->domain) {
            $store->update(['custom_domain' => null]);
            $store->regenerateProductUrls();
        }

        $domain->delete();

        return response()->json(null, 204);
    }

    private function instructions(
        CloudflareCustomHostnameService $cloudflare,
        string $hostname,
    ): array {
        return [
            'type' => 'CNAME',
            'host' => 'checkout',
            'target' => $cloudflare->cnameTarget(),
            'expected_cname' => $cloudflare->cnameTarget(),
            'hostname' => $hostname,
        ];
    }

    private function checkoutHostname(string $input): string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];
        $host = rtrim($host, '.');

        if (str_contains($host, ':')) {
            throw new InvalidArgumentException('Informe apenas o dominio, sem porta.');
        }

        if (str_starts_with($host, 'checkout.')) {
            $host = substr($host, strlen('checkout.'));
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, strlen('www.'));
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = $ascii !== false ? strtolower($ascii) : $host;
        }

        $hostname = 'checkout.'.$host;
        $systemBaseDomain = strtolower((string) config('services.checkout.base_domain', 'bersenker.shop'));

        if (
            $host === ''
            || !str_contains($host, '.')
            || strlen($hostname) > 253
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException('Informe um dominio valido, por exemplo seudominio.com.');
        }

        if ($host === $systemBaseDomain || str_ends_with($host, '.'.$systemBaseDomain)) {
            throw new InvalidArgumentException('Este dominio nao pode ser usado.');
        }

        return $hostname;
    }

    private function findCname(string $hostname): ?string
    {
        $records = @dns_get_record($hostname, DNS_CNAME);

        if (!is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            if (!empty($record['target'])) {
                return strtolower(rtrim((string) $record['target'], '.'));
            }
        }

        return null;
    }
}
