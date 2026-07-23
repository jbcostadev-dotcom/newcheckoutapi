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
            $table->boolean('footer_show_store_name')->default(true);
            $table->boolean('footer_show_payment_methods')->default(true);
            $table->boolean('footer_show_contact_email')->default(false);
            $table->boolean('footer_show_whatsapp')->default(false);
            $table->boolean('footer_show_address')->default(false);
            $table->boolean('footer_show_terms')->default(false);
            $table->string('footer_terms_url')->nullable();
            $table->boolean('footer_show_privacy_policy')->default(false);
            $table->string('footer_privacy_policy_url')->nullable();
            $table->boolean('footer_show_return_policy')->default(false);
            $table->string('footer_return_policy_url')->nullable();
            $table->string('footer_text_color')->default('#3a3636')->nullable();
            $table->string('footer_background_color')->default('#ffffff')->nullable();
            $table->boolean('footer_show_security_icons')->default(true);
            $table->string('footer_icon_color')->default('#000000')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'footer_show_store_name',
                'footer_show_payment_methods',
                'footer_show_contact_email',
                'footer_show_whatsapp',
                'footer_show_address',
                'footer_show_terms',
                'footer_terms_url',
                'footer_show_privacy_policy',
                'footer_privacy_policy_url',
                'footer_show_return_policy',
                'footer_return_policy_url',
                'footer_text_color',
                'footer_background_color',
                'footer_show_security_icons',
                'footer_icon_color',
            ]);
        });
    }
};
