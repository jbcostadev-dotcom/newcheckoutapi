<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsappInstance;
use App\Services\WahaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WhatsappChipController extends Controller
{
    public function __construct(private readonly WahaService $waha)
    {
    }

    /**
     * Lista os chips (instâncias WhatsApp) da loja.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        return $store->whatsappInstances()->latest()->get();
    }

    /**
     * Cria um novo chip: cria a sessão na WAHA e persiste a instância.
     */
    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'instance_name' => 'required|string|max:120',
        ]);

        $sessionName = 'chip-' . $store->id . '-' . Str::random(8);

        $wahaStatus = WhatsappInstance::STATUS_STARTING;
        $wahaError = null;

        if ($this->waha->configured()) {
            try {
                $session = $this->waha->createSession($sessionName);
                $wahaStatus = WahaService::mapStatus($session['status'] ?? null);
            } catch (\Throwable $e) {
                $wahaStatus = WhatsappInstance::STATUS_FAILED;
                $wahaError = $e->getMessage();
            }
        } else {
            $wahaError = 'Integração WAHA não configurada (WAHA_API_URL).';
            $wahaStatus = WhatsappInstance::STATUS_DISCONNECTED;
        }

        $chip = $store->whatsappInstances()->create([
            'instance_name' => $validated['instance_name'],
            'instance_key' => $sessionName,
            'session_name' => $sessionName,
            'status' => $wahaStatus,
            'phone_number' => null,
            'qr_code_url' => null,
            'is_active' => true,
        ]);

        return response()->json([
            'chip' => $chip,
            'warning' => $wahaError,
        ], 201);
    }

    /**
     * Sincroniza o status (e telefone) de todos os chips com a WAHA.
     */
    public function sync(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        if (! $this->waha->configured()) {
            return response()->json(['message' => 'Integração WAHA não configurada.'], 422);
        }

        $chips = $store->whatsappInstances()->get();

        foreach ($chips as $chip) {
            try {
                $session = $this->waha->getSession($chip->session_name);

                if (! $session) {
                    $chip->update([
                        'status' => WhatsappInstance::STATUS_DISCONNECTED,
                        'phone_number' => null,
                        'qr_code_url' => null,
                    ]);
                    continue;
                }

                $status = WahaService::mapStatus($session['status'] ?? null);
                $update = ['status' => $status];

                if ($status === WhatsappInstance::STATUS_CONNECTED) {
                    $me = $session['me'] ?? null;
                    if (! $me) {
                        $me = $this->waha->getMe($chip->session_name);
                    }
                    $update['phone_number'] = $this->extractPhone($me['id'] ?? null);
                    $update['qr_code_url'] = null;
                } elseif ($status !== WhatsappInstance::STATUS_QR_READY) {
                    $update['qr_code_url'] = null;
                }

                $chip->update($update);
            } catch (\Throwable $e) {
                // Continua sincronizando os demais chips mesmo se um falhar.
            }
        }

        return $store->whatsappInstances()->latest()->get();
    }

    /**
     * Busca e retorna o QR code atual do chip (ou o status se já conectado).
     */
    public function qr(Request $request, string $storeId, string $chipId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $chip = $store->whatsappInstances()->findOrFail($chipId);

        if (! $this->waha->configured()) {
            return response()->json(['message' => 'Integração WAHA não configurada.'], 422);
        }

        $session = $this->waha->getSession($chip->session_name);

        if (! $session) {
            $chip->update([
                'status' => WhatsappInstance::STATUS_DISCONNECTED,
                'phone_number' => null,
                'qr_code_url' => null,
            ]);

            return response()->json([
                'status' => $chip->fresh()->status,
                'qr_code_url' => null,
            ]);
        }

        $status = WahaService::mapStatus($session['status'] ?? null);
        $update = ['status' => $status];

        $qr = null;
        if ($status === WhatsappInstance::STATUS_QR_READY) {
            $qr = $this->waha->getQrCode($chip->session_name);
            if ($qr) {
                $update['qr_code_url'] = $qr;
            }
        } elseif ($status === WhatsappInstance::STATUS_CONNECTED) {
            $me = $session['me'] ?? null;
            if (! $me) {
                $me = $this->waha->getMe($chip->session_name);
            }
            $update['phone_number'] = $this->extractPhone($me['id'] ?? null);
            $update['qr_code_url'] = null;
            $qr = null;
        } else {
            $update['qr_code_url'] = null;
        }

        $chip->update($update);
        $chip = $chip->fresh();

        return response()->json([
            'status' => $chip->status,
            'status_label' => $chip->status,
            'phone_number' => $chip->phone_number,
            'qr_code_url' => $chip->qr_code_url,
        ]);
    }

    /**
     * Desconecta (logout) o chip mantendo o cadastro.
     */
    public function logout(Request $request, string $storeId, string $chipId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $chip = $store->whatsappInstances()->findOrFail($chipId);

        if ($this->waha->configured()) {
            try {
                $this->waha->logoutSession($chip->session_name);
            } catch (\Throwable $e) {
                // Mesmo se o logout falhar na WAHA, liberamos localmente.
            }
        }

        $chip->update([
            'status' => WhatsappInstance::STATUS_DISCONNECTED,
            'phone_number' => null,
            'qr_code_url' => null,
        ]);

        return response()->json($chip->fresh());
    }

    /**
     * Remove o chip (exclui a sessão na WAHA e o registro local).
     */
    public function destroy(Request $request, string $storeId, string $chipId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $chip = $store->whatsappInstances()->findOrFail($chipId);

        if ($this->waha->configured()) {
            $this->waha->deleteSession($chip->session_name);
        }

        $chip->delete();

        return response()->json(null, 204);
    }

    private function extractPhone(?string $chatId): ?string
    {
        if (! $chatId) {
            return null;
        }

        return Str::before($chatId, '@');
    }
}