<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->boolean('accept_cpf')->default(true)->after('social_proofs_enabled');
            $table->boolean('accept_cnpj')->default(false)->after('accept_cpf');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn(['accept_cpf', 'accept_cnpj']);
        });
    }
};
