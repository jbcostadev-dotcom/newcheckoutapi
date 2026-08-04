<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Store;
use App\Services\Ssl\CaddyProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DomainController extends Controller
{
    /**
     * Listar domínios de uma loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domains = $store->domains()->latest()->get();
        return response()->json($domains);
    }

    /**
     * Adicionar um novo domínio personalizado.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'domain' => 'required|string|max:255|unique:domains,domain',
        ]);

        // Remove protocolo e trailing slash se o usuário digitou
        $domain = preg_replace('#^https?://#', '', rtrim($validated['domain'], '/'));

        // Verifica se o domínio é um subdomínio do sistema
        $baseDomain = config('services.checkout.base_domain', 'bersenker.shop');
        $appDomain = config('services.checkout.app_domain', "checkout.{$baseDomain}");
        if ($domain === $baseDomain || $domain === "www.{$baseDomain}" || $domain === $appDomain || $domain === "www.{$appDomain}") {
            return response()->json(['error' => 'Este domínio não pode ser usado.'], 422);
        }

        // Verifica se outra loja já usa este domínio dentro de uma transação
        // para reduzir a janela de race condition entre cadastros simultâneos.
        try {
            $domainModel = DB::transaction(function () use ($store, $domain) {
                $lockedStore = Store::query()
                    ->whereKey($store->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedStore->domains()->exists()) {
                    throw new \DomainException('Store already has a custom domain');
                }

                if (Domain::where('domain', $domain)->lockForUpdate()->exists()) {
                    throw new \RuntimeException('Domain already in use');
                }

                return $lockedStore->domains()->create([
                    'domain' => $domain,
                    'status' => 'pending',
                    'ssl_status' => 'pending',
                ]);
            });
        } catch (\DomainException $e) {
            return response()->json([
                'message' => 'Esta loja ja possui um dominio personalizado. Remova o dominio atual para cadastrar outro.',
            ], 422);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json(['error' => 'Este domínio já está em uso por outra loja.'], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Este domínio já está em uso por outra loja.'], 422);
        }

        // Instruções DNS para o usuário
        $appDomain = config('services.checkout.app_domain', "checkout.{$baseDomain}");
        $instructions = [
            'type' => 'CNAME',
            'host' => 'www',
            'target' => $appDomain,
            'expected_cname' => $appDomain,
        ];

        return response()->json([
            'domain' => $domainModel,
            'instructions' => $instructions,
        ], 201);
    }

    /**
     * Verificar DNS de um domínio (consulta CNAME real).
     */
    public function verifyDns(Request $request, string $storeId, string $domainId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domain = $store->domains()->findOrFail($domainId);

        $baseDomain = config('services.checkout.base_domain', 'bersenker.shop');
        $expectedCname = config('services.checkout.app_domain', "checkout.{$baseDomain}");

        // Consulta DNS CNAME
        $records = @dns_get_record($domain->domain, DNS_CNAME);

        $foundCname = null;
        $verified = false;

        if ($records !== false && is_array($records)) {
            foreach ($records as $record) {
                $target = strtolower($record['target'] ?? '');
                if ($target === $expectedCname || $target === strtolower("{$domain->domain}.{$baseDomain}")) {
                    $foundCname = $record['target'];
                    $verified = true;
                    break;
                }
            }
        }

        // Tenta também com o domínio completo (se usuário apontou o root)
        if (!$verified) {
            $records = @dns_get_record($domain->domain, DNS_A);
            // Verifica se o A record aponta para o IP do nosso servidor (opcional)
        }

        if ($verified) {
            $domain->update([
                'dns_verified_at' => now(),
                'ssl_status' => 'dns_verified',
            ]);
        }

        return response()->json([
            'verified' => $verified,
            'found_cname' => $foundCname,
            'expected_cname' => $expectedCname,
            'dns_records' => $records ?: [],
        ]);
    }

    /**
     * Ativar domínio (marca SSL ativo + salva custom_domain na loja).
     */
    public function activate(Request $request, string $storeId, string $domainId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domain = $store->domains()->findOrFail($domainId);

        if (!$domain->isDnsVerified()) {
            return response()->json(['error' => 'O DNS precisa ser verificado antes da ativação.'], 422);
        }

        // Provisiona via Caddy
        $provisioner = new CaddyProvisioner();
        $provisionResult = $provisioner->provision($domain->domain);

        // Atualiza o domínio
        $domain->update([
            'ssl_active' => true,
            'ssl_status' => 'active',
        ]);

        // Salva o custom_domain na loja (permite resolução no checkout)
        $store->update([
            'custom_domain' => $domain->domain,
        ]);

        // Regenera o checkout_url de todos os produtos (domínio mudou).
        $store->regenerateProductUrls();

        return response()->json([
            'message' => "Domínio {$domain->domain} ativado com sucesso.",
            'domain' => $domain,
            'provision' => $provisionResult,
        ]);
    }

    /**
     * Remover um domínio.
     */
    public function destroy(Request $request, string $storeId, string $domainId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $domain = $store->domains()->findOrFail($domainId);

        // Revoga do Caddy se SSL ativo
        if ($domain->isSslActive()) {
            $provisioner = new CaddyProvisioner();
            $provisioner->revoke($domain->domain);
        }

        // Remove o custom_domain da loja se for o mesmo
        if ($store->custom_domain === $domain->domain) {
            $store->update(['custom_domain' => null]);
            // Regenera URLs — agora voltam a usar subdomínio.
            $store->regenerateProductUrls();
        }

        $domain->delete();

        return response()->json(null, 204);
    }
}
