<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_instances', 'phone_number')) {
                $table->string('phone_number')->nullable()->after('status');
            }
            if (! Schema::hasColumn('whatsapp_instances', 'session_name')) {
                $table->string('session_name')->nullable()->after('instance_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_instances', 'phone_number')) {
                $table->dropColumn('phone_number');
            }
            if (Schema::hasColumn('whatsapp_instances', 'session_name')) {
                $table->dropColumn('session_name');
            }
        });
    }
};