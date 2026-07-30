<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class UtmifySettingController extends Controller
{
    /**
     * Retorna a configuração da Utmify da loja.
     */
    public function show(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $setting = $store->utmifySetting()->firstOrCreate([]);

        return response()->json([
            'enabled' => (bool) $setting->enabled,
            'has_token' => ! empty($setting->api_token),
            // Não devolve o token: o frontend só precisa saber se está configurado.
        ]);
    }

    /**
     * Atualiza a credencial de API e/ou o flag de ativação da Utmify.
     */
    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'api_token' => 'nullable|string|max:500',
            'enabled' => 'boolean',
            // Permite limpar o token enviando string vazia.
            'clear_token' => 'sometimes|boolean',
        ]);

        $setting = $store->utmifySetting()->firstOrCreate([]);

        $update = [];
        if (array_key_exists('enabled', $validated)) {
            $update['enabled'] = (bool) $validated['enabled'];
        }

        if (($validated['clear_token'] ?? false) || (array_key_exists('api_token', $validated) && trim($validated['api_token']) === '')) {
            $update['api_token'] = null;
        } elseif (array_key_exists('api_token', $validated)) {
            $token = trim($validated['api_token']);
            if ($token !== '') {
                $update['api_token'] = $token;
            }
        }

        if (! empty($update)) {
            $setting->update($update);
        }

        return response()->json([
            'enabled' => (bool) $setting->fresh()->enabled,
            'has_token' => ! empty($setting->fresh()->api_token),
        ]);
    }
}