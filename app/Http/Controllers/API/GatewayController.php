<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Services\UnipayService;
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
            'installment_type' => 'sometimes|in:default,custom',
            'default_installment_rate' => 'nullable|numeric|min:0|max:100',
            'installment_rates' => 'nullable|array',
            'installment_rates.*' => 'nullable|numeric|min:0|max:100',
            'pre_selected_installment' => 'sometimes|integer|min:1|max:12',
            'installment_limit' => 'sometimes|integer|min:1|max:12',
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
            'installment_type' => 'sometimes|in:default,custom',
            'default_installment_rate' => 'nullable|numeric|min:0|max:100',
            'installment_rates' => 'nullable|array',
            'installment_rates.*' => 'nullable|numeric|min:0|max:100',
            'pre_selected_installment' => 'sometimes|integer|min:1|max:12',
            'installment_limit' => 'sometimes|integer|min:1|max:12',
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
     * Para a Unipay (FastSoft Brasil), consulta o saldo da carteira.
     */
    public function test(Request $request, string $storeId, string $gatewayId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $gateway = $store->gateways()->findOrFail($gatewayId);

        if ($gateway->provider === 'unipay') {
            try {
                $service = new UnipayService($gateway);
                $balance = $service->testConnection();

                return response()->json([
                    'success' => true,
                    'message' => 'Conexão com Unipay validada com sucesso.',
                    'balance' => $balance,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao conectar à Unipay: ' . $e->getMessage(),
                ], 422);
            }
        }

        // Outros providers: simulação.
        return response()->json([
            'success' => true,
            'message' => "Conexão com {$gateway->provider} testada com sucesso (simulação).",
        ]);
    }
}
