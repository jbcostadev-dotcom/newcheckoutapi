<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('upsell_id')->nullable()->constrained('upsells')->nullOnDelete()->after('payment_method');
            $table->decimal('upsell_amount', 10, 2)->nullable()->after('upsell_id');
            $table->enum('upsell_status', ['offered', 'accepted', 'declined'])->nullable()->after('upsell_amount');
            $table->foreignId('upsell_product_id')->nullable()->constrained('products')->nullOnDelete()->after('upsell_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upsell_id');
            $table->dropColumn('upsell_amount');
            $table->dropColumn('upsell_status');
            $table->dropConstrainedForeignId('upsell_product_id');
        });
    }
};
