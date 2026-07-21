<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_bumps', function (Blueprint $table) {
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
            $table->boolean('show_boleto')->default(true);
            $table->string('offer_title')->default('Você também pode gostar');
            $table->text('offer_message')->nullable();
            $table->string('bg_color')->default('#FEFCE8');
            $table->string('border_color')->default('#E2E8F0');
            $table->string('button_color')->default('#13BF8C');
            $table->string('button_text_color')->default('#FFFFFF');
            $table->string('button_label')->default('Quero essa oferta');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_bumps');
    }
};