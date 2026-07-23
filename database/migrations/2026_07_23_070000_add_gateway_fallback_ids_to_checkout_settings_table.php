<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->json('pix_gateway_ids')->nullable()->after('pix_gateway_id');
            $table->json('card_gateway_ids')->nullable()->after('card_gateway_id');
            $table->json('boleto_gateway_ids')->nullable()->after('boleto_gateway_id');
        });

        // Migrate existing single gateway IDs into the new JSON arrays.
        $rows = DB::table('checkout_settings')
            ->whereNotNull('pix_gateway_id')
            ->orWhereNotNull('card_gateway_id')
            ->orWhereNotNull('boleto_gateway_id')
            ->get(['id', 'pix_gateway_id', 'card_gateway_id', 'boleto_gateway_id']);

        foreach ($rows as $row) {
            $update = [];
            if ($row->pix_gateway_id) {
                $update['pix_gateway_ids'] = json_encode([(int) $row->pix_gateway_id]);
            }
            if ($row->card_gateway_id) {
                $update['card_gateway_ids'] = json_encode([(int) $row->card_gateway_id]);
            }
            if ($row->boleto_gateway_id) {
                $update['boleto_gateway_ids'] = json_encode([(int) $row->boleto_gateway_id]);
            }
            if (!empty($update)) {
                DB::table('checkout_settings')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropColumn(['pix_gateway_ids', 'card_gateway_ids', 'boleto_gateway_ids']);
        });
    }
};
