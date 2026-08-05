<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->boolean('header_store_name_visible')->default(true)->after('banner_message');
            $table->boolean('header_secure_badge')->default(true)->after('header_store_name_visible');
            $table->boolean('announcement_bar_enabled')->default(true)->after('header_secure_badge');
            $table->string('announcement_bar_bg')->default('#333333')->after('announcement_bar_enabled');
            $table->string('announcement_bar_text_color')->default('#d4a843')->after('announcement_bar_bg');
            $table->string('banner_height')->default('md')->after('banner_url');
            $table->string('summary_title')->default('Resumo do pedido')->after('banner_height');
            $table->boolean('summary_show_discount')->default(true)->after('summary_title');
            $table->boolean('summary_coupon_enabled')->default(true)->after('summary_show_discount');
            $table->string('step_title_font_size')->default('1.25rem')->after('summary_coupon_enabled');
            $table->boolean('scarcity_enabled')->default(false)->after('step_title_font_size');
            $table->string('scarcity_type')->default('countdown')->after('scarcity_enabled');
            $table->string('scarcity_text')->nullable()->after('scarcity_type');
            $table->integer('scarcity_countdown_minutes')->default(20)->after('scarcity_text');
            $table->string('pix_confirmation_title')->default('Aguardando pagamento...')->after('scarcity_countdown_minutes');
            $table->text('pix_confirmation_message')->nullable()->after('pix_confirmation_title');
            $table->string('pix_confirmation_logo')->nullable()->after('pix_confirmation_message');
            $table->string('footer_text')->default('Ambiente seguro · SSL criptografado')->after('pix_confirmation_logo');
            $table->boolean('footer_show_cnpj')->default(false)->after('footer_text');
            $table->string('footer_cnpj')->nullable()->after('footer_show_cnpj');
            $table->string('font_family')->default('Inter')->after('footer_cnpj');
            $table->string('font_size_base')->default('16px')->after('font_family');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn([
                'header_store_name_visible',
                'header_secure_badge',
                'announcement_bar_enabled',
                'announcement_bar_bg',
                'announcement_bar_text_color',
                'banner_height',
                'summary_title',
                'summary_show_discount',
                'summary_coupon_enabled',
                'step_title_font_size',
                'scarcity_enabled',
                'scarcity_type',
                'scarcity_text',
                'scarcity_countdown_minutes',
                'pix_confirmation_title',
                'pix_confirmation_message',
                'pix_confirmation_logo',
                'footer_text',
                'footer_show_cnpj',
                'footer_cnpj',
                'font_family',
                'font_size_base',
            ]);
        });
    }
};
