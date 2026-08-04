<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kwai_pixel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->onDelete('cascade');
            $table->string('pixel_name')->nullable();
            $table->string('pixel_code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('test_event_code')->nullable();
            $table->boolean('browser_enabled')->default(true);
            $table->boolean('events_api_enabled')->default(false);
            $table->boolean('only_paid_sales')->default(true);
            $table->boolean('only_selected_products')->default(false);
            $table->json('selected_product_ids')->nullable();
            $table->boolean('require_consent')->default(false);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kwai_pixel_settings');
    }
};
