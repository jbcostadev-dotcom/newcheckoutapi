<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('melhor_envio_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->onDelete('cascade');
            $table->string('api_token')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('remetente_nome');
            $table->string('remetente_telefone');
            $table->string('remetente_email');
            $table->string('remetente_cpf');
            $table->string('remetente_cnpj')->nullable();
            $table->string('remetente_ie')->nullable();
            $table->string('remetente_cep');
            $table->string('remetente_endereco');
            $table->string('remetente_numero');
            $table->string('remetente_complemento')->nullable();
            $table->string('remetente_bairro');
            $table->string('remetente_cidade');
            $table->string('remetente_estado');
            $table->string('etiquetas_sem_nota')->default('nao');
            $table->boolean('nao_enviar_etiquetas')->default(false);
            $table->boolean('comprar_automatico')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('melhor_envio_settings');
    }
};
