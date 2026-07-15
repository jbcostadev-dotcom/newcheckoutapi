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
        return $this->build($store, (string) $productId);
    }

    /**
     * Gera a URL pública do checkout para um carrinho (1 ou N produtos).
     *
     * Os IDs podem vir repetidos para representar quantidade (mesmo produto
     * várias unidades), espelhando o comportamento do checkout de produto único.
     *
     * @param Store       $store
     * @param array<int>  $productIds  IDs internos dos produtos (repetidos = qty)
     */
    public function generateForCart(Store $store, array $productIds): string
    {
        $ids = array_map('intval', $productIds);
        $ids = array_filter($ids, fn ($id) => $id > 0);

        // Fallback defensivo — nunca devolvemos uma URL sem produtos.
        if (empty($ids)) {
            $ids = [0];
        }

        return $this->build($store, implode(',', $ids));
    }

    /**
     * Monta a URL final considerando custom_domain / subdomain / app domain.
     */
    protected function build(Store $store, string $productsParam): string
    {
        $baseDomain = config('services.checkout.base_domain', 'bersenker.shop');
        $appDomain = config('services.checkout.app_domain', "checkout.{$baseDomain}");

        if ($store->custom_domain) {
            return "https://{$store->custom_domain}/checkout?products={$productsParam}";
        }

        $subdomain = $store->subdomain ?? '';
        if ($subdomain) {
            return "https://{$appDomain}/{$subdomain}/checkout?products={$productsParam}";
        }

        // Fallback — subdomínio é obrigatório em produção, mas evita URL quebrada.
        return "https://{$appDomain}/checkout?products={$productsParam}";
    }
}