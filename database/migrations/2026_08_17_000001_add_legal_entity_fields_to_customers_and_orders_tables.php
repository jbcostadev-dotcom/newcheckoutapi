<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('person_type', 20)->default('individual')->after('document');
            $table->string('state_registration', 30)->nullable()->after('person_type');
            $table->boolean('state_registration_exempt')->default(false)->after('state_registration');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_type', 20)->default('individual')->after('customer_document');
            $table->string('customer_state_registration', 30)->nullable()->after('customer_type');
            $table->boolean('customer_state_registration_exempt')->default(false)->after('customer_state_registration');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type',
                'customer_state_registration',
                'customer_state_registration_exempt',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['person_type', 'state_registration', 'state_registration_exempt']);
        });
    }
};
