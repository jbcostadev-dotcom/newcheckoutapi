<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checkout_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->string('primary_color')->default('#000000');
            $table->string('secondary_color')->default('#ffffff');
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->boolean('enable_order_bump')->default(false);
            $table->boolean('dark_mode')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_settings');
    }
};
