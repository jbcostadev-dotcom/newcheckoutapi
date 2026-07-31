<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MelhorEnvioSetting extends Model
{
    protected $fillable = [
        'store_id',
        'api_token',
        'enabled',
        'remetente_nome',
        'remetente_telefone',
        'remetente_email',
        'remetente_cpf',
        'remetente_cnpj',
        'remetente_ie',
        'remetente_cep',
        'remetente_endereco',
        'remetente_numero',
        'remetente_complemento',
        'remetente_bairro',
        'remetente_cidade',
        'remetente_estado',
        'etiquetas_sem_nota',
        'nao_enviar_etiquetas',
        'comprar_automatico',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'nao_enviar_etiquetas' => 'boolean',
        'comprar_automatico' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Indica se a integração está pronta para uso.
     */
    public function isActive(): bool
    {
        return $this->enabled && !empty($this->api_token);
    }
}
