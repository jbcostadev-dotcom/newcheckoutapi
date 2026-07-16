<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('banner_message')->default('Digite aqui a mensagem')->after('button_text');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn('banner_message');
        });
    }
};