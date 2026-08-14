<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_collection_id', 64);
            $table->string('shopify_graphql_id', 191);
            $table->string('title');
            $table->string('handle')->nullable();
            $table->longText('description')->nullable();
            $table->text('image_url')->nullable();
            $table->unsignedInteger('products_count')->default(0);
            $table->string('sort_order', 50)->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'shopify_collection_id'], 'shopify_collections_store_legacy_unique');
            $table->unique(['store_id', 'shopify_graphql_id'], 'shopify_collections_store_graphql_unique');
            $table->index(['store_id', 'title'], 'shopify_collections_store_title_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_collections');
    }
};
