<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedInteger('max_uses')->default(0);
            $table->unsignedInteger('used_count')->default(0);
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->enum('discount_type', ['fixed', 'percent'])->default('fixed');
            $table->boolean('auto_apply')->default(false);
            $table->boolean('first_purchase_only')->default(false);
            $table->boolean('accumulate_with_promos')->default(false);
            $table->boolean('free_shipping')->default(false);
            $table->decimal('min_purchase_value', 10, 2)->nullable();
            $table->boolean('min_items_required')->default(false);
            $table->unsignedInteger('min_items_quantity')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->boolean('applies_to_all_products')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
