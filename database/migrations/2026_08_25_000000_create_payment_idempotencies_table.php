<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_idempotencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 20);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('state', 20)->default('reserved');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('gateway_started_at')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->uuid('owner_token')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('processing_alerted_at')->nullable();
            $table->timestamp('indeterminate_alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'scope', 'key_hash'], 'payment_idempotencies_store_scope_key_unique');
            $table->index(['state', 'updated_at'], 'payment_idempotencies_state_updated_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_idempotencies');
    }
};
