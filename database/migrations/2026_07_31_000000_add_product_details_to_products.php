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
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('name');
            $table->string('barcode')->nullable()->after('sku');

            // Peso
            $table->decimal('weight', 10, 3)->nullable()->after('barcode');
            $table->string('weight_unit', 20)->nullable()->after('weight');
            $table->unsignedInteger('grams')->nullable()->after('weight_unit');

            // Dimensões
            $table->decimal('height', 10, 3)->nullable()->after('grams');
            $table->decimal('width', 10, 3)->nullable()->after('height');
            $table->decimal('length', 10, 3)->nullable()->after('width');
            $table->string('dimension_unit', 20)->nullable()->after('length');

            // Categorização e logística
            $table->string('product_type')->nullable()->after('dimension_unit');
            $table->string('vendor')->nullable()->after('product_type');
            $table->json('tags')->nullable()->after('vendor');
            $table->boolean('taxable')->nullable()->after('tags');
            $table->boolean('requires_shipping')->nullable()->after('taxable');
            $table->string('inventory_policy')->nullable()->after('requires_shipping');
            $table->string('fulfillment_service')->nullable()->after('inventory_policy');
            $table->string('inventory_item_id')->nullable()->after('fulfillment_service');
            $table->unsignedInteger('position')->nullable()->after('inventory_item_id');
            $table->string('tax_code')->nullable()->after('position');
            $table->decimal('cost', 10, 2)->nullable()->after('tax_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'sku',
                'barcode',
                'weight',
                'weight_unit',
                'grams',
                'height',
                'width',
                'length',
                'dimension_unit',
                'product_type',
                'vendor',
                'tags',
                'taxable',
                'requires_shipping',
                'inventory_policy',
                'fulfillment_service',
                'inventory_item_id',
                'position',
                'tax_code',
                'cost',
            ]);
        });
    }
};
