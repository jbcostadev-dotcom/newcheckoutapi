<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MelhorEnvioSettingController extends Controller
{
    private const REQUIRED_FIELDS = [
        'remetente_nome',
        'remetente_telefone',
        'remetente_email',
        'remetente_cpf',
        'remetente_cep',
        'remetente_endereco',
        'remetente_numero',
        'remetente_bairro',
        'remetente_cidade',
        'remetente_estado',
    ];

    /**
     * Retorna a configuração do Melhor Envio da loja.
     */
    public function show(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $setting = $store->melhorEnvioSetting()->first();

        return response()->json([
            'enabled' => (bool) $setting?->enabled,
            'has_token' => !empty($setting?->api_token),
            'values' => $setting ? $this->valuesToArray($setting) : [],
        ]);
    }

    /**
     * Atualiza a configuração do Melhor Envio.
     */
    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $rules = [
            'api_token' => 'nullable|string|max:500',
            'enabled' => 'boolean',
            'clear_token' => 'sometimes|boolean',
            'remetente_nome' => 'required|string|max:255',
            'remetente_telefone' => 'required|string|max:50',
            'remetente_email' => 'required|email|max:255',
            'remetente_cpf' => 'required|string|max:20',
            'remetente_cnpj' => 'nullable|string|max:20',
            'remetente_ie' => 'nullable|string|max:50',
            'remetente_cep' => 'required|string|max:10',
            'remetente_endereco' => 'required|string|max:255',
            'remetente_numero' => 'required|string|max:20',
            'remetente_complemento' => 'nullable|string|max:100',
            'remetente_bairro' => 'required|string|max:100',
            'remetente_cidade' => 'required|string|max:100',
            'remetente_estado' => 'required|string|max:2',
            'etiquetas_sem_nota' => ['required', Rule::in(['sim', 'nao'])],
            'nao_enviar_etiquetas' => 'boolean',
            'comprar_automatico' => 'boolean',
        ];

        $validated = $request->validate($rules);

        $setting = $store->melhorEnvioSetting()->firstOrCreate(
            [],
            $this->defaultValues()
        );

        $update = [];

        if (array_key_exists('enabled', $validated)) {
            $update['enabled'] = (bool) $validated['enabled'];
        }

        if (array_key_exists('api_token', $validated)) {
            if (($validated['clear_token'] ?? false) || trim($validated['api_token']) === '') {
                $update['api_token'] = null;
            } else {
                $update['api_token'] = trim($validated['api_token']);
            }
        }

        foreach (array_keys($rules) as $key) {
            if (in_array($key, ['api_token', 'enabled', 'clear_token'], true)) {
                continue;
            }
            if (array_key_exists($key, $validated)) {
                $update[$key] = $validated[$key];
            }
        }

        if (!empty($update)) {
            $setting->update($update);
        }

        return response()->json([
            'enabled' => (bool) $setting->fresh()->enabled,
            'has_token' => !empty($setting->fresh()->api_token),
            'values' => $this->valuesToArray($setting->fresh()),
        ]);
    }

    private function defaultValues(): array
    {
        return [
            'api_token' => null,
            'enabled' => false,
            'remetente_nome' => '',
            'remetente_telefone' => '',
            'remetente_email' => '',
            'remetente_cpf' => '',
            'remetente_cnpj' => null,
            'remetente_ie' => null,
            'remetente_cep' => '',
            'remetente_endereco' => '',
            'remetente_numero' => '',
            'remetente_complemento' => null,
            'remetente_bairro' => '',
            'remetente_cidade' => '',
            'remetente_estado' => '',
            'etiquetas_sem_nota' => 'nao',
            'nao_enviar_etiquetas' => false,
            'comprar_automatico' => false,
        ];
    }

    private function valuesToArray($setting): array
    {
        return [
            'token' => $setting->api_token,
            'remetente_nome' => $setting->remetente_nome,
            'remetente_telefone' => $setting->remetente_telefone,
            'remetente_email' => $setting->remetente_email,
            'remetente_cpf' => $setting->remetente_cpf,
            'remetente_cnpj' => $setting->remetente_cnpj,
            'remetente_ie' => $setting->remetente_ie,
            'remetente_cep' => $setting->remetente_cep,
            'remetente_endereco' => $setting->remetente_endereco,
            'remetente_numero' => $setting->remetente_numero,
            'remetente_complemento' => $setting->remetente_complemento,
            'remetente_bairro' => $setting->remetente_bairro,
            'remetente_cidade' => $setting->remetente_cidade,
            'remetente_estado' => $setting->remetente_estado,
            'etiquetas_sem_nota' => $setting->etiquetas_sem_nota,
            'nao_enviar_etiquetas' => $setting->nao_enviar_etiquetas ? 'true' : 'false',
            'comprar_automatico' => $setting->comprar_automatico ? 'true' : 'false',
        ];
    }
}
