<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Upsell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfferPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Store $store;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        $this->owner = User::factory()->create();
        $this->store = $this->owner->stores()->create(['name' => 'Loja da prévia', 'subdomain' => 'loja-preview']);
        $this->product = $this->store->products()->create(['name' => 'Produto salvo', 'price' => 123, 'is_active' => true]);
    }

    private function offer(string $type = 'upsell'): Upsell
    {
        return $this->store->upsells()->create([
            'offer_type' => $type, 'name' => "Oferta {$type}", 'product_id' => $this->product->id,
            'discount_type' => 'fixed', 'discount_value' => 10.03, 'scope' => 'any',
            'offer_title' => 'Título salvo', 'offer_message' => 'Mensagem salva',
            'button_label' => 'Aceitar oferta salva', 'show_credit_card' => true, 'show_pix' => true,
            'show_boleto' => false, 'is_active' => false,
        ]);
    }

    private function previewUrl(string $type = 'upsell'): string
    {
        return URL::temporarySignedRoute('checkout.offer-preview', now()->addMinutes(30), [
            'store_id' => $this->store->id, 'offer_type' => $type,
        ], false);
    }

    public function test_only_the_store_owner_can_generate_the_preview_link(): void
    {
        $this->offer();
        $path = "/api/stores/{$this->store->id}/upsells/preview-link?offer_type=upsell";
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($path)->assertNotFound();
        $response = $this->actingAs($this->owner, 'sanctum')->getJson($path)->assertOk();
        $this->assertStringContainsString('signature=', $response->json('preview_query'));
        $this->assertStringNotContainsString('token', $response->getContent());
        $response->assertJsonPath('store.id', $this->store->id)->assertJsonMissingPath('store.shopify_access_token');
        $this->getJson('/api/checkout/offer-preview?'.$response->json('preview_query'))->assertOk();
    }

    public function test_empty_or_invalid_offer_types_do_not_generate_links(): void
    {
        $path = "/api/stores/{$this->store->id}/upsells/preview-link";
        $this->actingAs($this->owner, 'sanctum')->getJson($path.'?offer_type=upsell')->assertNotFound();
        $this->getJson($path.'?offer_type=other')->assertUnprocessable();
    }

    public static function offerTypes(): array
    {
        return [['upsell'], ['downsell']];
    }

    #[DataProvider('offerTypes')]
    public function test_signed_preview_uses_saved_offers_and_design_without_orders_or_payments(string $type): void
    {
        $offer = $this->offer($type);
        $this->offer($type === 'upsell' ? 'downsell' : 'upsell');
        $this->store->checkoutSettings()->create([
            'upsell_bg_color' => '#123456', 'downsell_bg_color' => '#654321',
            'header_bg_color' => '#abcdef', 'banner_message' => 'Mensagem do editor',
            'logo_url' => 'https://example.test/logo.png',
        ]);
        $response = $this->getJson($this->previewUrl($type))->assertOk()
            ->assertJsonPath('preview', true)->assertJsonCount(1, 'offers')
            ->assertJsonPath('offers.0.id', $offer->id)->assertJsonPath('offers.0.offer_type', $type)
            ->assertJsonPath('offers.0.is_active', false)
            ->assertJsonPath('offers.0.product.name', 'Produto salvo')
            ->assertJsonPath('offers.0.product.upsell_price', 112.97)
            ->assertJsonPath('offers.0.button_label', 'Aceitar oferta salva')
            ->assertJsonPath('offers.0.payment_methods', ['credit_card', 'pix'])
            ->assertJsonPath('settings.upsell_bg_color', '#123456')
            ->assertJsonPath('settings.downsell_bg_color', '#654321')
            ->assertJsonPath('settings.header_bg_color', '#abcdef')
            ->assertJsonPath('settings.banner_message', 'Mensagem do editor')
            ->assertJsonMissingPath('order')->assertJsonMissingPath('settings.card_gateway_ids');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_idempotencies', 0);
        Http::assertNothingSent();
    }

    public function test_missing_tampered_and_expired_signatures_are_rejected(): void
    {
        $this->offer();
        $url = $this->previewUrl();
        $this->getJson('/api/checkout/offer-preview?store_id='.$this->store->id.'&offer_type=upsell')->assertForbidden();
        $this->getJson(str_replace('offer_type=upsell', 'offer_type=downsell', $url))->assertForbidden();
        $this->getJson(str_replace('store_id='.$this->store->id, 'store_id=99999', $url))->assertForbidden();
        $this->travel(31)->minutes();
        $this->getJson($url)->assertForbidden();
    }

    public function test_variants_use_the_same_presentation_as_the_real_offer(): void
    {
        $this->product->update(['shopify_product_id' => 'preview-product']);
        $this->offer();
        $this->store->products()->create([
            'name' => 'Variante azul', 'price' => 130, 'is_active' => true,
            'shopify_product_id' => 'preview-product', 'attributes' => [['name' => 'Cor', 'value' => 'Azul']],
        ]);
        $this->getJson($this->previewUrl())->assertOk()->assertJsonCount(2, 'offers.0.product.variants');
    }
}
