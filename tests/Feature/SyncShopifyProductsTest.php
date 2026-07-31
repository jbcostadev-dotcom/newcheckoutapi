<?php

namespace Tests\Feature;

use App\Jobs\SyncShopifyProducts;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncShopifyProductsTest extends TestCase
{
    use RefreshDatabase;

    private function createStore(): Store
    {
        $user = User::factory()->create();

        return Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Teste',
            'subdomain' => 'loja-teste',
            'shopify_domain' => 'loja-teste.myshopify.com',
            'shopify_access_token' => 'token-fake',
        ]);
    }

    public function test_syncs_all_paginated_products(): void
    {
        $store = $this->createStore();

        $nextUrl = 'https://loja-teste.myshopify.com/admin/api/2025-07/products.json?page_info=abc123&limit=250';

        Http::fake([
            'https://loja-teste.myshopify.com/admin/api/2025-07/products.json*' => Http::sequence()
                ->push([
                    'products' => [
                        $this->productPayload(1, 'Produto A', [1]),
                    ],
                ], 200, ['Link' => "<{$nextUrl}>; rel=\"next\""])
                ->push([
                    'products' => [
                        $this->productPayload(2, 'Produto Z', [2]),
                    ],
                ], 200),
        ]);

        (new SyncShopifyProducts($store))->handle();

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['name' => 'Produto A', 'shopify_variant_id' => '1']);
        $this->assertDatabaseHas('products', ['name' => 'Produto Z', 'shopify_variant_id' => '2']);

        // A segunda requisição deve usar a URL do header Link sem parâmetros extras.
        Http::assertSent(function ($request) use ($nextUrl) {
            return $request->url() === $nextUrl;
        });
    }

    public function test_long_description_is_truncated(): void
    {
        $store = $this->createStore();

        Http::fake([
            'https://loja-teste.myshopify.com/admin/api/2025-07/products.json*' => Http::response([
                'products' => [
                    $this->productPayload(1, 'Produto Grande', [1], str_repeat('x', 2_000_000)),
                ],
            ]),
        ]);

        (new SyncShopifyProducts($store))->handle();

        $product = Product::first();
        $this->assertLessThanOrEqual(1_000_000, strlen($product->description));
    }

    public function test_missing_variants_are_deactivated(): void
    {
        $store = $this->createStore();

        $store->products()->create([
            'name' => 'Antigo',
            'parent_title' => 'Antigo',
            'price' => 10,
            'shopify_variant_id' => '999',
            'is_active' => true,
        ]);

        Http::fake([
            'https://loja-teste.myshopify.com/admin/api/2025-07/products.json*' => Http::response([
                'products' => [
                    $this->productPayload(1, 'Novo', [1]),
                ],
            ]),
        ]);

        (new SyncShopifyProducts($store))->handle();

        $this->assertFalse($store->products()->where('shopify_variant_id', '999')->value('is_active'));
        $this->assertTrue($store->products()->where('shopify_variant_id', '1')->value('is_active'));
    }

    public function test_syncs_product_details_from_shopify(): void
    {
        $store = $this->createStore();

        Http::fake([
            'https://loja-teste.myshopify.com/admin/api/2025-07/products.json*' => Http::response([
                'products' => [
                    $this->productPayload(1, 'Produto A', [1], null, [
                        'sku' => 'SKU-001',
                        'barcode' => '7891234567890',
                        'grams' => 500,
                        'weight' => 0.5,
                        'weight_unit' => 'kg',
                        'height' => 10.5,
                        'width' => 20.0,
                        'length' => 30.0,
                        'dimension_unit' => 'cm',
                        'taxable' => true,
                        'requires_shipping' => true,
                        'inventory_policy' => 'deny',
                        'fulfillment_service' => 'manual',
                        'inventory_item_id' => 12345,
                        'position' => 1,
                        'tax_code' => 'P0000000',
                        'cost' => '5.00',
                    ], [
                        'product_type' => 'Eletrônicos',
                        'vendor' => 'Marca X',
                        'tags' => 'tag1, tag2',
                    ]),
                ],
            ]),
        ]);

        (new SyncShopifyProducts($store))->handle();

        $this->assertDatabaseHas('products', [
            'shopify_variant_id' => '1',
            'sku' => 'SKU-001',
            'barcode' => '7891234567890',
            'grams' => 500,
            'weight' => 0.5,
            'weight_unit' => 'kg',
            'height' => 10.5,
            'width' => 20.0,
            'length' => 30.0,
            'dimension_unit' => 'cm',
            'product_type' => 'Eletrônicos',
            'vendor' => 'Marca X',
            'taxable' => 1,
            'requires_shipping' => 1,
            'inventory_policy' => 'deny',
            'fulfillment_service' => 'manual',
            'inventory_item_id' => '12345',
            'position' => 1,
            'tax_code' => 'P0000000',
            'cost' => 5.00,
        ]);

        $product = Product::where('shopify_variant_id', '1')->first();
        $this->assertNotNull($product);
        $this->assertEquals(['tag1', 'tag2'], $product->tags);
    }

    private function productPayload(
        int $productId,
        string $title,
        array $variantIds,
        ?string $description = null,
        array $variantExtras = [],
        array $productExtras = [],
    ): array {
        $variants = [];
        foreach ($variantIds as $variantId) {
            $variants[] = array_merge([
                'id' => $variantId,
                'price' => '10.00',
                'option1' => 'Default Title',
            ], $variantExtras);
        }

        return array_merge([
            'id' => $productId,
            'title' => $title,
            'body_html' => $description ?? '<p>Descrição</p>',
            'status' => 'active',
            'image' => ['src' => 'https://example.com/image.jpg'],
            'options' => [['name' => 'Title']],
            'variants' => $variants,
        ], $productExtras);
    }
}
