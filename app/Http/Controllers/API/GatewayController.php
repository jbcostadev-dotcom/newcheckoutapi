<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Gateway;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    /**
     * Listar gateways da loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $gateways = $store->gateways()->latest()->get();

        return response()->json($gateways);
    }

    /**
     * Criar novo gateway.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'provider' => 'required|string|max:255',
            'api_key' => 'nullable|string|max:1000',
            'secret_key' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $gateway = $store->gateways()->create($validated);

        return response()->json($gateway, 201);
    }

    /**
     * Atualizar gateway.
     */
    public function update(Request $request, string $storeId, string $gatewayId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $gateway = $store->gateways()->findOrFail($gatewayId);

        $validated = $request->validate([
            'provider' => 'sometimes|string|max:255',
            'api_key' => 'nullable|string|max:1000',
            'secret_key' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $gateway->update($validated);

        return response()->json($gateway);
    }

    /**
     * Remover gateway.
     */
    public function destroy(Request $request, string $storeId, string $gatewayId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $gateway = $store->gateways()->findOrFail($gatewayId);
        $gateway->delete();

        return response()->json(null, 204);
    }

    /**
     * Testar credenciais do gateway.
     * TODO: Implementar chamada real à API do provider.
     */
    public function test(Request $request, string $storeId, string $gatewayId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $gateway = $store->gateways()->findOrFail($gatewayId);

        // Por enquanto retorna sucesso simulado.
        // Implemente a chamada real de acordo com o provider ($gateway->provider).
        return response()->json([
            'success' => true,
            'message' => "Conexão com {$gateway->provider} testada com sucesso (simulação).",
        ]);
    }
}
