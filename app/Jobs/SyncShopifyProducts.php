<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Models\Store;
use Illuminate\Support\Facades\Http;

class SyncShopifyProducts implements ShouldQueue
{
    use Queueable;

    protected $store;

    /**
     * Create a new job instance.
     */
    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->store->shopify_access_token || !$this->store->shopify_domain) {
            return;
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->store->shopify_access_token
        ])->get("https://{$this->store->shopify_domain}/admin/api/2024-01/products.json");

        if ($response->successful()) {
            $products = $response->json()['products'] ?? [];

            foreach ($products as $shopifyProduct) {
                $this->store->products()->updateOrCreate(
                    ['shopify_product_id' => $shopifyProduct['id']],
                    [
                        'name' => $shopifyProduct['title'],
                        'description' => $shopifyProduct['body_html'],
                        'price' => $shopifyProduct['variants'][0]['price'] ?? 0,
                        'compare_at_price' => $shopifyProduct['variants'][0]['compare_at_price'] ?? null,
                        'image_url' => $shopifyProduct['image']['src'] ?? null,
                        'is_active' => $shopifyProduct['status'] === 'active'
                    ]
                );
            }
        }
    }
}
