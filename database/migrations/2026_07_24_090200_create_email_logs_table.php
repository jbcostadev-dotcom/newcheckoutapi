<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('smtp_setting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->nullable();
            $table->string('context_key')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('subject')->nullable();
            $table->longText('message')->nullable();
            $table->string('status')->default('sent');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'event', 'context_key', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
