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
     *  - ID imutável da loja          → https://{checkout.app_domain}/store/{id}/checkout?products={id}
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
     * Alias que gera a URL usando o ID imutável da loja.
     * Útil para emails/WhatsApp e outros links que precisam sobreviver a
     * renomeações de subdomínio.
     *
     * @param Store $store
     * @param int   $productId
     */
    public function generateById(Store $store, int $productId): string
    {
        return $this->build($store, (string) $productId);
    }

    /**
     * Monta a URL final considerando custom_domain / store_id / app domain.
     *
     * A URL pública agora usa o ID imutável da loja:
     *   https://checkout.bersenker.shop/store/{id}/checkout?products={ids}
     *
     * Domínios customizados continuam servindo checkout no próprio host.
     */
    protected function build(Store $store, string $productsParam): string
    {
        $baseDomain = config('services.checkout.base_domain', 'bersenker.shop');
        $appDomain = config('services.checkout.app_domain', "checkout.{$baseDomain}");

        if ($store->custom_domain) {
            return "https://{$store->custom_domain}/checkout?products={$productsParam}";
        }

        return "https://{$appDomain}/store/{$store->id}/checkout?products={$productsParam}";
    }
}