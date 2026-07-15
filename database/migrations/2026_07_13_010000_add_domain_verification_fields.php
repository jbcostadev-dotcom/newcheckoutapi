<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('dns_verified_at')->nullable()->after('ssl_active');
            $table->string('ssl_status')->default('pending')->after('dns_verified_at'); // pending, dns_verified, active, failed
            $table->string('verification_token')->nullable()->after('ssl_status');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['dns_verified_at', 'ssl_status', 'verification_token']);
        });
    }
};
