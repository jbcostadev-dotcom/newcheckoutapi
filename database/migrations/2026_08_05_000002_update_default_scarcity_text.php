<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_DEFAULT = 'Você precisa finalizar seu pedido em até';
    private const NEW_DEFAULT = 'Finalize seu pedido em até';

    public function up(): void
    {
        DB::table('checkout_settings')
            ->where('scarcity_text', self::OLD_DEFAULT)
            ->update(['scarcity_text' => self::NEW_DEFAULT]);
    }

    public function down(): void
    {
        DB::table('checkout_settings')
            ->where('scarcity_text', self::NEW_DEFAULT)
            ->update(['scarcity_text' => self::OLD_DEFAULT]);
    }
};
