<?php

namespace App\Services;

use App\Models\Store;

class CheckoutUrlGenerator
{
    /**
     * Gera a URL pública do checkout para um produto da loja.
     *
     * Regras (espelham o middleware do Next.js):
     *  - Domínio personalizado ativo  → https://{custom_domain}/checkout?products={id}
     *  - Subdomínio                   → https://{checkout.app_domain}/{subdomain}/checkout?products={id}
     *
     * @param Store $store
     * @param int   $productId
     */
    public function generate(Store $store, int $productId): string
    {
        $baseDomain = config('services.checkout.base_domain', 'bersenker.shop');
        $appDomain = config('services.checkout.app_domain', "checkout.{$baseDomain}");

        if ($store->custom_domain) {
            return "https://{$store->custom_domain}/checkout?products={$productId}";
        }

        $subdomain = $store->subdomain ?? '';
        if ($subdomain) {
            return "https://{$appDomain}/{$subdomain}/checkout?products={$productId}";
        }

        // Fallback — subdomínio é obrigatório em produção, mas evita URL quebrada.
        return "https://{$appDomain}/checkout?products={$productId}";
    }
}