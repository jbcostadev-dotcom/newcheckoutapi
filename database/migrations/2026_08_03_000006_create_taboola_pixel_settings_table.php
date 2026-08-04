<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taboola_pixel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->onDelete('cascade');
            $table->string('pixel_name')->nullable();
            $table->string('account_id')->nullable();
            $table->text('postback_url')->nullable();
            $table->boolean('browser_enabled')->default(true);
            $table->boolean('s2s_enabled')->default(true);
            $table->boolean('only_paid_sales')->default(true);
            $table->boolean('only_selected_products')->default(false);
            $table->json('selected_product_ids')->nullable();
            $table->boolean('require_consent')->default(false);
            $table->string('page_view_event_name', 80)->default('page_view');
            $table->string('view_content_event_name', 80)->default('PRODUCT_VIEW');
            $table->string('add_to_cart_event_name', 80)->default('ADD_TO_CART');
            $table->string('initiate_checkout_event_name', 80)->default('CHECKOUT');
            $table->string('add_payment_info_event_name', 80)->default('ADD_PAYMENT_INFO');
            $table->string('purchase_event_name', 80)->default('PURCHASE');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taboola_pixel_settings');
    }
};
