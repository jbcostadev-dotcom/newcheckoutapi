<?php

use App\Models\Store;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stores')
            ->where('type', Store::LEGACY_TYPE_LANDING)
            ->update(['type' => Store::TYPE_LANDING_PHYSICAL]);
    }

    public function down(): void
    {
        DB::table('stores')
            ->whereIn('type', [Store::TYPE_LANDING_PHYSICAL, Store::TYPE_LANDING_DIGITAL])
            ->update(['type' => Store::LEGACY_TYPE_LANDING]);
    }
};
