<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_funnel_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->string('furthest_stage', 32)->default('entered');
            $table->boolean('payment_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['store_id', 'session_id']);
            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'furthest_stage']);
        });

        Schema::table('abandoned_carts', function (Blueprint $table) {
            $table->string('session_id', 64)->nullable()->after('store_id');
            $table->index(['store_id', 'session_id'], 'abandoned_carts_store_session_index');
        });
    }

    public function down(): void
    {
        Schema::table('abandoned_carts', function (Blueprint $table) {
            $table->dropIndex('abandoned_carts_store_session_index');
            $table->dropColumn('session_id');
        });

        Schema::dropIfExists('checkout_funnel_sessions');
    }
};
