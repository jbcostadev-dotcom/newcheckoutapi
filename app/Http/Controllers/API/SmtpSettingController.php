<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SmtpSetting;
use App\Services\SmtpService;
use Illuminate\Http\Request;

class SmtpSettingController extends Controller
{
    public function __construct(private readonly SmtpService $smtp)
    {
    }

    /**
     * Retorna a configuração SMTP da loja (sem expor a senha).
     */
    public function show(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $setting = $store->smtpSettings;

        if (! $setting) {
            return response()->json(null);
        }

        return response()->json(array_merge($setting->toArray(), [
            'has_password' => ! empty($setting->getRawOriginal('password')),
        ]));
    }

    /**
     * Cria ou atualiza a configuração SMTP da loja (uma por loja).
     */
    public function update(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string|max:255',
            'encryption' => 'nullable|in:tls,ssl,none',
            'from_email' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:120',
            'is_active' => 'boolean',
        ]);

        $setting = $store->smtpSettings;

        // Senha em branco mantém a atual (edição sem redigitar).
        if (empty($validated['password'])) {
            unset($validated['password']);
            if (! $setting) {
                return response()->json([
                    'message' => 'A senha é obrigatória na primeira configuração.',
                ], 422);
            }
        }

        $validated['encryption'] = $validated['encryption'] ?? 'tls';
        $validated['from_email'] = $validated['from_email'] ?? $validated['username'];
        $validated['from_name'] = $validated['from_name'] ?? $validated['name'];

        if ($setting) {
            $setting->update($validated);
        } else {
            $setting = $store->smtpSettings()->create($validated);
        }

        return response()->json($setting->fresh());
    }

    /**
     * Testa conexão e autenticação com o servidor SMTP.
     * Aceita payload de teste (antes de salvar) ou usa a config salva.
     */
    public function test(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string|max:255',
            'encryption' => 'nullable|in:tls,ssl,none',
        ]);

        $password = $validated['password'] ?? null;

        // Sem senha no payload → usa a senha salva (se existir).
        if (! $password) {
            $password = $store->smtpSettings?->password;
        }

        if (! $password) {
            return response()->json([
                'message' => 'Informe a senha para testar a conexão.',
            ], 422);
        }

        $setting = new SmtpSetting([
            'host' => $validated['host'],
            'port' => $validated['port'],
            'username' => $validated['username'],
            'encryption' => $validated['encryption'] ?? 'tls',
        ]);
        // Seta o valor bruto (o cast 'encrypted' criptografa ao atribuir).
        $setting->password = $password;

        try {
            $this->smtp->testConnection($setting);

            return response()->json(['success' => true, 'message' => 'Conexão estabelecida com sucesso.']);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha na conexão: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove a configuração SMTP da loja.
     */
    public function destroy(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $setting = $store->smtpSettings()->firstOrFail();
        $setting->delete();

        return response()->json(null, 204);
    }
}
