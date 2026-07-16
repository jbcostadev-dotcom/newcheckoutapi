<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Garante que não existam domínios Shopify duplicados antes de adicionar
        // o índice único. Mantém o registro mais recente (maior id) e limpa os
        // demais, preservando o histórico de pedidos (eles não dependem de
        // shopify_domain).
        $duplicates = DB::table('stores')
            ->select('shopify_domain')
            ->whereNotNull('shopify_domain')
            ->groupBy('shopify_domain')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shopify_domain');

        foreach ($duplicates as $domain) {
            $ids = DB::table('stores')
                ->where('shopify_domain', $domain)
                ->orderByDesc('id')
                ->pluck('id')
                ->toArray();

            // Mantém o mais recente; limpa os demais.
            array_shift($ids);
            DB::table('stores')
                ->whereIn('id', $ids)
                ->update(['shopify_domain' => null, 'shopify_access_token' => null]);

            Log::info('shopify_domain duplicado resolvido', [
                'domain' => $domain,
                'cleared_store_ids' => $ids,
            ]);
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->unique('shopify_domain', 'stores_shopify_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique('stores_shopify_domain_unique');
        });
    }
};
