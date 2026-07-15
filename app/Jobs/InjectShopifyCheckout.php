<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

use App\Models\Store;
use App\Services\ShopifyThemeInjector;

class InjectShopifyCheckout implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;

    protected Store $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /**
     * Execute the job.
     */
    public function handle(ShopifyThemeInjector $injector): void
    {
        if (!$this->store->isShopifyConnected()) {
            return;
        }

        try {
            $injector->inject($this->store);

            Log::info('Shopify checkout snippet job concluído', [
                'store_id' => $this->store->id,
                'shopify_domain' => $this->store->shopify_domain,
            ]);
        } catch (\Throwable $e) {
            // 403/401 → normalmente falta de escopo; não adianta retentar imediatamente.
            Log::warning('Shopify checkout snippet job falhou', [
                'store_id' => $this->store->id,
                'shopify_domain' => $this->store->shopify_domain,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ]);

            // Re-lança apenas se houver chance de sucesso em retry (429/5xx). Casos
            // de escopo ausente não devem consumir tentativas.
            $code = (int) $e->getCode();
            if (in_array($code, [401, 403, 404], true)) {
                $this->delete();
                return;
            }

            throw $e;
        }
    }
}
