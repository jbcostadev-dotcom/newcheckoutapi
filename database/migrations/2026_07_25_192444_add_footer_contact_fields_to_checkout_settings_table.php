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
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->string('footer_contact_email')->nullable()->after('footer_show_contact_email');
            $table->string('footer_whatsapp')->nullable()->after('footer_show_whatsapp');
            $table->string('footer_address')->nullable()->after('footer_show_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn(['footer_contact_email', 'footer_whatsapp', 'footer_address']);
        });
    }
};
