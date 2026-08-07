<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('merchant')->index()->after('password');
        });

        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // plate | badge
            $table->string('metric', 32); // revenue_total, orders_paid, revenue_24h, orders_paid_24h
            $table->unsignedBigInteger('target_value'); // centavos para faturamento, unidades para pedidos
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('achievement_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->restrictOnDelete();
            $table->timestamp('unlocked_at');
            $table->unsignedBigInteger('value_at_unlock');
            $table->unsignedBigInteger('target_at_unlock');
            $table->timestamps();
            $table->unique(['store_id', 'achievement_id']);
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('achievement_awards');
        Schema::dropIfExists('achievements');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
