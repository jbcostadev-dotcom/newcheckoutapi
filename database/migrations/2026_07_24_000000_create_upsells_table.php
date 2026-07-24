<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->enum('discount_type', ['fixed', 'percent'])->default('fixed');
            $table->enum('scope', ['any', 'specific'])->default('any');
            $table->foreignId('target_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('show_credit_card')->default(true);
            $table->boolean('show_pix')->default(true);
            $table->boolean('show_boleto')->default(false);
            $table->string('offer_title')->nullable();
            $table->text('offer_message')->nullable();
            $table->string('button_label')->default('QUERO ESSA OFERTA');
            $table->string('bg_color')->default('#ffffff');
            $table->string('border_color')->default('#e2e8f0');
            $table->string('button_color')->default('#22c55e');
            $table->string('button_text_color')->default('#ffffff');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsells');
    }
};
