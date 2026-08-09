<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('cloudflare_custom_hostname_id')->nullable()->unique()->after('verification_token');
            $table->string('cloudflare_hostname_status')->default('pending')->after('cloudflare_custom_hostname_id');
            $table->text('cloudflare_error')->nullable()->after('cloudflare_hostname_status');
            $table->timestamp('cloudflare_synced_at')->nullable()->after('cloudflare_error');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['cloudflare_custom_hostname_id']);
            $table->dropColumn([
                'cloudflare_custom_hostname_id',
                'cloudflare_hostname_status',
                'cloudflare_error',
                'cloudflare_synced_at',
            ]);
        });
    }
};
