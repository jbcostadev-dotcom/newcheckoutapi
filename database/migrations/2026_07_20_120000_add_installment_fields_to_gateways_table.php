<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            // 'default' = mesma taxa pra todas parcelas | 'custom' = taxa personalizada por parcela
            $table->string('installment_type')->default('default')->after('settings');
            $table->decimal('default_installment_rate', 6, 4)->default(3.14)->after('installment_type');
            $table->json('installment_rates')->nullable()->after('default_installment_rate');
            $table->tinyInteger('pre_selected_installment')->default(1)->after('installment_rates');
            $table->tinyInteger('installment_limit')->default(12)->after('pre_selected_installment');
        });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropColumn([
                'installment_type',
                'default_installment_rate',
                'installment_rates',
                'pre_selected_installment',
                'installment_limit',
            ]);
        });
    }
};
