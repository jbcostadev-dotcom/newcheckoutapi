<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shopify_variant_id')->nullable()->after('shopify_product_id');
            $table->string('checkout_url')->nullable()->after('image_url');

            // Uma loja não pode ter duas variantes Shopify iguais.
            // Usamos composite único entre store_id e shopify_variant_id.
            $table->unique(['store_id', 'shopify_variant_id'], 'products_store_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_store_variant_unique');
            $table->dropColumn(['shopify_variant_id', 'checkout_url']);
        });
    }
};