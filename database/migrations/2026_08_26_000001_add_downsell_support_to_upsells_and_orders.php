<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upsells', function (Blueprint $table) {
            $table->enum('offer_type', ['upsell', 'downsell'])
                ->default('upsell')
                ->after('store_id')
                ->index();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('upsell_status', ['offered', 'processing', 'accepted', 'declined'])->nullable()->change();
            $table->foreignId('downsell_id')
                ->nullable()
                ->after('upsell_product_id')
                ->constrained('upsells')
                ->nullOnDelete();
            $table->decimal('downsell_amount', 10, 2)->nullable()->after('downsell_id');
            $table->string('downsell_status', 20)->nullable()->after('downsell_amount');
            $table->foreignId('downsell_product_id')
                ->nullable()
                ->after('downsell_status')
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('upsell_status', ['offered', 'accepted', 'declined'])->nullable()->change();
            $table->dropConstrainedForeignId('downsell_product_id');
            $table->dropColumn('downsell_status');
            $table->dropColumn('downsell_amount');
            $table->dropConstrainedForeignId('downsell_id');
        });

        Schema::table('upsells', function (Blueprint $table) {
            $table->dropIndex(['offer_type']);
            $table->dropColumn('offer_type');
        });
    }
};
