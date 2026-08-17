<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('rule_type', ['always', 'min_quantity', 'min_value'])->default('always');
            $table->unsignedInteger('min_quantity')->nullable();
            $table->decimal('min_value', 12, 2)->nullable();
            $table->enum('scope', ['any', 'specific'])->default('any');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active', 'starts_at', 'expires_at']);
        });

        Schema::create('gift_product', function (Blueprint $table) {
            $table->foreignId('gift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['gift_id', 'product_id']);
        });

        Schema::create('gift_target_product', function (Blueprint $table) {
            $table->foreignId('gift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['gift_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_target_product');
        Schema::dropIfExists('gift_product');
        Schema::dropIfExists('gifts');
    }
};
