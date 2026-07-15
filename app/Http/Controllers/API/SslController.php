<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Controller público para validação de domínios pelo Caddy (On-Demand TLS).
 * O Caddy consulta este endpoint na directive `ask` para saber se
 * pode emitir um certificado para um determinado domínio.
 */
class SslController extends Controller
{
    /**
     * Endpoint chamado pelo Caddy para validar se um domínio pode receber certificado.
     *
     * Retorna 200 se o domínio for permitido (ativo na tabela domains OU
     * corresponder ao domínio do checkout app).
     * Retorna 404 caso contrário (Caddy rejeita a emissão).
     */
    public function domainCheck(Request $request)
    {
        $domain = $request->query('domain');

        if (!$domain) {
            return response()->json(['error' => 'Missing domain parameter'], 400);
        }

        $baseDomain = config('services.checkout.base_domain', 'bersenker.shop');
        $checkoutAppDomain = config('services.checkout.app_domain', "checkout.{$baseDomain}");

        // 1. Permitir o domínio principal do checkout app
        if ($domain === $baseDomain || $domain === "www.{$baseDomain}" || $domain === $checkoutAppDomain || $domain === "www.{$checkoutAppDomain}") {
            return response()->json(['allowed' => true, 'type' => 'system']);
        }

        // 2. Permitir domínios customizados ativos
        $activeDomain = Domain::where('domain', $domain)
            ->where('ssl_active', true)
            ->where('ssl_status', 'active')
            ->exists();

        if ($activeDomain) {
            return response()->json(['allowed' => true, 'type' => 'custom']);
        }

        // 3. Permitir domínios customizados que já estão no stores.custom_domain
        $customDomainStore = Store::where('custom_domain', $domain)
            ->where('status', true)
            ->exists();

        if ($customDomainStore) {
            return response()->json(['allowed' => true, 'type' => 'store_custom']);
        }

        // 4. Negar
        return response()->json(['allowed' => false], 404);
    }
}
