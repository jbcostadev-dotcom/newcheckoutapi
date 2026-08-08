<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CheckoutSettingController extends Controller
{
    private const ALLOWED_IMAGE_MIMES = ['image/webp', 'image/jpeg', 'image/png'];
    private const ALLOWED_IMAGE_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];
    private const MAX_IMAGE_SIZE_KB = 8192; // 8 MB

    /**
     * Validation rule for uploaded checkout images.
     */
    private function imageRule(): string
    {
        $extensions = implode(',', self::ALLOWED_IMAGE_EXTENSIONS);
        return "nullable|file|mimes:{$extensions}|max:" . self::MAX_IMAGE_SIZE_KB;
    }

    /**
     * Store an uploaded image and return its public URL.
     */
    private function storeUploadedImage($file, string $storeId, string $folder): string
    {
        $path = $file->store("checkout-assets/{$storeId}/{$folder}", 'public');
        return Storage::disk('public')->url($path);
    }

    public function show(string $storeId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $settings = $store->checkoutSettings()->firstOrCreate([]);
        
        return response()->json($settings);
    }

    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $settings = $store->checkoutSettings()->firstOrCreate([]);

        Log::debug('Checkout settings update', [
            'store_id' => $storeId,
            'method' => $request->getMethod(),
            'has_logo' => $request->hasFile('logo'),
            'has_banner' => $request->hasFile('banner'),
            'has_pix_logo' => $request->hasFile('pix_confirmation_logo_file'),
            'logo_url' => $request->input('logo_url'),
            'banner_url' => $request->input('banner_url'),
            'pix_confirmation_logo' => $request->input('pix_confirmation_logo'),
        ]);

        try {
            $validated = $request->validate([
                'primary_color' => 'nullable|string|max:7',
                'secondary_color' => 'nullable|string|max:7',
                'logo' => $this->imageRule(),
                'banner' => $this->imageRule(),
                'logo_url' => 'nullable|string',
                'banner_url' => 'nullable|string',
                'banner_height' => 'nullable|string|in:sm,md,lg',
                'enable_order_bump' => 'boolean',
                'order_bump_display_mode' => 'nullable|string|in:stacked,carousel',
                'order_bump_scarcity_timer_enabled' => 'boolean',
                'order_bump_scarcity_timer_minutes' => 'nullable|integer|min:1|max:1440',
                'dark_mode' => 'boolean',
                'button_text' => 'nullable|string|max:50',
                'banner_message' => 'nullable|string|max:255',
                'header_store_name_visible' => 'boolean',
                'header_secure_badge' => 'boolean',
                'header_logo_alignment' => 'nullable|string|in:left,center,right',
                'header_bg_color' => 'nullable|string|max:20',
                'header_icon_color' => 'nullable|string|max:20',
                'announcement_bar_enabled' => 'boolean',
                'announcement_bar_bg' => 'nullable|string|max:7',
                'announcement_bar_text_color' => 'nullable|string|max:7',
                'summary_title' => 'nullable|string|max:100',
                'summary_total_text_color' => 'nullable|string|max:7',
                'summary_default_expanded' => 'boolean',
                'summary_show_discount' => 'boolean',
                'summary_coupon_enabled' => 'boolean',
                'quantity_selector_enabled' => 'boolean',
                'step_title_font_size' => 'nullable|string|max:10',
                'step_number_color' => 'nullable|string|max:7',
                'input_border_radius' => 'nullable|string|in:none,medium,large',
                'step_button_color' => 'nullable|string|max:7',
                'finalize_button_color' => 'nullable|string|max:7',
                'step_card_background_color' => 'nullable|string|max:7',
                'scarcity_enabled' => 'boolean',
                'scarcity_type' => 'nullable|string|in:countdown,stock,visitors',
                'scarcity_text' => 'nullable|string|max:255',
                'scarcity_title' => 'nullable|string|max:255',
                'scarcity_countdown_minutes' => 'nullable|integer|min:1|max:999',
                'scarcity_font_color' => 'nullable|string|max:7',
                'scarcity_counter_color' => 'nullable|string|max:7',
                'scarcity_counter_text_color' => 'nullable|string|max:7',
                'pix_confirmation_title' => 'nullable|string|max:100',
                'pix_confirmation_message' => 'nullable|string|max:500',
                'pix_confirmation_logo_file' => $this->imageRule(),
                'pix_confirmation_logo' => 'nullable|string',
                'footer_text' => 'nullable|string|max:255',
                'footer_show_store_name' => 'boolean',
                'footer_show_payment_methods' => 'boolean',
                'footer_show_cnpj' => 'boolean',
                'footer_cnpj' => 'nullable|string|max:20',
                'footer_show_contact_email' => 'boolean',
                'footer_contact_email' => 'nullable|string|max:255',
                'footer_show_whatsapp' => 'boolean',
                'footer_whatsapp' => 'nullable|string|max:30',
                'footer_show_address' => 'boolean',
                'footer_address' => 'nullable|string|max:255',
                'footer_show_terms' => 'boolean',
                'footer_terms_url' => 'nullable|string',
                'footer_show_privacy_policy' => 'boolean',
                'footer_privacy_policy_url' => 'nullable|string',
                'footer_show_return_policy' => 'boolean',
                'footer_return_policy_url' => 'nullable|string',
                'footer_text_color' => 'nullable|string|max:7',
                'footer_background_color' => 'nullable|string|max:7',
                'footer_show_security_icons' => 'boolean',
                'footer_icon_color' => 'nullable|string|max:7',
                'font_family' => 'nullable|string|max:50',
                'font_size_base' => 'nullable|string|max:10',
                'social_proofs_enabled' => 'boolean',
                'pix_enabled' => 'boolean',
                'pix_gateway_id' => 'nullable|integer|exists:gateways,id',
                'pix_gateway_ids' => 'nullable|array',
                'pix_gateway_ids.*' => 'integer|exists:gateways,id',
                'card_enabled' => 'boolean',
                'card_gateway_id' => 'nullable|integer|exists:gateways,id',
                'card_gateway_ids' => 'nullable|array',
                'card_gateway_ids.*' => 'integer|exists:gateways,id',
                'boleto_enabled' => 'boolean',
                'boleto_gateway_id' => 'nullable|integer|exists:gateways,id',
                'boleto_gateway_ids' => 'nullable|array',
                'boleto_gateway_ids.*' => 'integer|exists:gateways,id',
                'default_payment_method' => 'nullable|string|in:credit_card,pix,boleto',
                'pix_discount_percentage' => 'nullable|numeric|min:0|max:100',
                'boleto_discount_percentage' => 'nullable|numeric|min:0|max:100',
                'card_discount_percentage' => 'nullable|numeric|min:0|max:100',
                'card_redirect_enabled' => 'boolean',
                'card_redirect_url' => 'nullable|string|url|max:500',
                'pix_redirect_enabled' => 'boolean',
                'pix_redirect_url' => 'nullable|string|url|max:500',
            ]);
        } catch (ValidationException $e) {
            Log::warning('Checkout settings validation failed', [
                'store_id' => $storeId,
                'errors' => $e->errors(),
            ]);
            throw $e;
        }

        // Process image uploads and overwrite the corresponding *_url fields.
        foreach ([
            'logo' => 'logo_url',
            'banner' => 'banner_url',
            'pix_confirmation_logo_file' => 'pix_confirmation_logo',
        ] as $fileKey => $urlKey) {
            if ($request->hasFile($fileKey)) {
                try {
                    $validated[$urlKey] = $this->storeUploadedImage(
                        $request->file($fileKey),
                        $storeId,
                        str_replace('_file', '', $fileKey)
                    );
                    Log::debug('Checkout image stored', [
                        'store_id' => $storeId,
                        'field' => $urlKey,
                        'url' => $validated[$urlKey],
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to store checkout image', [
                        'store_id' => $storeId,
                        'field' => $urlKey,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
            unset($validated[$fileKey]);
        }

        // Treat empty strings as null for image URL fields so removals are persisted.
        // The frontend sends "__keep__" when no new file is selected but the existing URL must be preserved.
        foreach (['logo_url', 'banner_url', 'pix_confirmation_logo'] as $urlKey) {
            if (array_key_exists($urlKey, $validated)) {
                if ($validated[$urlKey] === '') {
                    $validated[$urlKey] = null;
                } elseif ($validated[$urlKey] === '__keep__') {
                    unset($validated[$urlKey]);
                }
            }
        }

        $settings->update($validated);

        return response()->json($settings);
    }
}
