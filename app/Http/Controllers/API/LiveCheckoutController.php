<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CheckoutFunnelSession;
use App\Models\Store;
use App\Services\LiveCheckoutRedisStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LiveCheckoutController extends Controller
{
    public function __construct(private readonly LiveCheckoutRedisStore $liveCheckout)
    {
    }

    /**
     * Recebe um heartbeat do checkout com os dados atuais do cliente.
     * Público: chamado pelo frontend do checkout.
     */
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'session_id' => 'required|string|max:64',
            'step' => 'required|in:dados,entrega,pagamento',
            'customer_name' => 'nullable|string|max:150',
            'customer_email' => 'nullable|email|max:150',
            'cep' => 'nullable|string|max:9',
            'payment_method' => 'nullable|in:pix,credit_card,boleto',
            'total' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.qty' => 'nullable|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'utm_source' => 'nullable|string|max:120',
            'utm_medium' => 'nullable|string|max:120',
            'utm_campaign' => 'nullable|string|max:120',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
        if (! $store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $session = [
            'store_id' => $store->id,
            'session_id' => $validated['session_id'],
            // O checkout no formato /store/{id} envia apenas store_id. A
            // validação permite isso, então não acesse a chave domain sem
            // fallback; ela não estará presente no array validado.
            'domain' => $validated['domain'] ?? $store->custom_domain ?? $store->subdomain,
            'step' => $validated['step'],
            'total' => (float) ($validated['total'] ?? 0),
            'last_seen_at' => now()->toDateTimeString(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 250),
        ];

        foreach (['customer_name', 'cep', 'payment_method', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
            if (array_key_exists($field, $validated)) {
                $session[$field] = $validated[$field];
            }
        }

        if (array_key_exists('customer_email', $validated)) {
            $session['customer_email'] = $validated['customer_email'] === null
                ? null
                : strtolower(trim($validated['customer_email']));
        }

        if (array_key_exists('items', $validated)) {
            $session['items'] = collect($validated['items'] ?? [])
                ->map(function ($item) {
                    return [
                        'name' => (string) ($item['name'] ?? 'Produto'),
                        'qty' => max(1, (int) ($item['qty'] ?? 1)),
                        'unit_price' => (float) ($item['unit_price'] ?? 0),
                    ];
                })
                ->values()
                ->all();
        }

        $previousStep = $this->liveCheckout->heartbeat(
            $store->id,
            $validated['session_id'],
            $session,
        );

        // Persiste somente a entrada e as mudancas de etapa. O heartbeat
        // recorrente permanece inteiramente no Redis.
        if ($previousStep !== $validated['step']) {
            CheckoutFunnelSession::recordStage(
                $store->id,
                $validated['session_id'],
                $validated['step'],
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Remove explicitamente uma sessão ativa (ex.: fechamento da página).
     * Público: chamado pelo frontend do checkout via Beacon API.
     */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
            'session_id' => 'required|string|max:64',
        ]);

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
        if (! $store) {
            return response()->json(['ok' => true]);
        }

        $this->liveCheckout->forget($store->id, $validated['session_id']);

        return response()->json(['ok' => true]);
    }

    /**
     * Lista as sessões ativas de checkout de uma loja.
     * Autenticado (Sanctum).
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $sessions = $this->liveCheckout->activeSessions($store->id);

        return response()->json([
            'sessions' => $sessions,
            'count' => count($sessions),
        ]);
    }
}
