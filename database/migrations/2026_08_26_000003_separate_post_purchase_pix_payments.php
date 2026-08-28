<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('post_purchase_pix_transaction_id')->nullable()->index();
            $table->json('post_purchase_pix')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['post_purchase_pix_transaction_id']);
            $table->dropColumn(['post_purchase_pix_transaction_id', 'post_purchase_pix']);
        });
    }
};
