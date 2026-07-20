<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('default_payment_method', 20)->default('credit_card')->after('boleto_gateway_id');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn('default_payment_method');
        });
    }
};
