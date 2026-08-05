<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->decimal('pix_discount_percentage', 5, 2)->default(1)->after('default_payment_method');
            $table->decimal('boleto_discount_percentage', 5, 2)->default(0)->after('pix_discount_percentage');
            $table->decimal('card_discount_percentage', 5, 2)->default(5)->after('boleto_discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'pix_discount_percentage',
                'boleto_discount_percentage',
                'card_discount_percentage',
            ]);
        });
    }
};
