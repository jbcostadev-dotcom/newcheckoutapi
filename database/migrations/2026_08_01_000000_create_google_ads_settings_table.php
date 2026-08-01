<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->onDelete('cascade');
            $table->string('pixel_name')->nullable();
            $table->string('pixel_id')->nullable();
            $table->string('conversion_label')->nullable();
            $table->boolean('only_paid_sales')->default(true);
            $table->boolean('only_selected_products')->default(false);
            $table->json('selected_product_ids')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_settings');
    }
};