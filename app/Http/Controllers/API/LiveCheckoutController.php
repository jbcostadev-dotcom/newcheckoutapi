<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CheckoutFunnelSession;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LiveCheckoutController extends Controller
{
    /**
     * TTL em segundos que uma sessão permanece ativa sem heartbeat.
     * O heartbeat é enviado a cada 3s; mantemos 10s de folga para
     * que a sessão suma rapidamente quando o cliente fecha a página.
     */
    private const TTL_SECONDS = 10;

    /**
     * Prefixo das chaves de cache para uma sessão.
     */
    private const SESSION_PREFIX = 'live_checkout';

    /**
     * Prefixo das chaves de índice de sessões por loja.
     */
    private const INDEX_PREFIX = 'live_checkout_index';

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

        $items = collect($validated['items'] ?? [])
            ->map(function ($item) {
                return [
                    'name' => (string) ($item['name'] ?? 'Produto'),
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                ];
            })
            ->values()
            ->all();

        $session = [
            'store_id' => $store->id,
            'session_id' => $validated['session_id'],
            // O checkout no formato /store/{id} envia apenas store_id. A
            // validação permite isso, então não acesse a chave domain sem
            // fallback; ela não estará presente no array validado.
            'domain' => $validated['domain'] ?? $store->custom_domain ?? $store->subdomain,
            'step' => $validated['step'],
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_email' => isset($validated['customer_email']) ? strtolower(trim($validated['customer_email'])) : null,
            'cep' => $validated['cep'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
            'total' => (float) ($validated['total'] ?? 0),
            'items' => $items,
            'utm_source' => $validated['utm_source'] ?? null,
            'utm_medium' => $validated['utm_medium'] ?? null,
            'utm_campaign' => $validated['utm_campaign'] ?? null,
            'last_seen_at' => now()->toDateTimeString(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent() ?? '', 250),
        ];

        $sessionKey = $this->sessionKey($store->id, $validated['session_id']);
        $indexKey = $this->indexKey($store->id);

        // Faz merge com os dados já existentes para não perder informações
        // (nome, e-mail, CEP, itens) quando um heartbeat enviar apenas uma
        // atualização parcial (ex.: apenas a etapa ou forma de pagamento).
        $existing = Cache::get($sessionKey, []);
        $session = array_merge($existing, $session);

        // Persiste somente a entrada e as mudancas de etapa. O heartbeat
        // continua no cache e nao gera uma escrita no banco a cada 3s.
        if (($existing['step'] ?? null) !== $validated['step']) {
            CheckoutFunnelSession::recordStage(
                $store->id,
                $validated['session_id'],
                $validated['step'],
            );
        }

        // Campos que devem ser atualizados mesmo quando vierem vazios/null.
        $session['step'] = $validated['step'];
        $session['total'] = (float) ($validated['total'] ?? 0);
        $session['last_seen_at'] = now()->toDateTimeString();
        $session['ip_address'] = $request->ip();
        $session['user_agent'] = Str::limit($request->userAgent() ?? '', 250);

        Cache::put($sessionKey, $session, self::TTL_SECONDS);

        // Mantém o índice de sessões ativas sincronizado.
        $index = Cache::get($indexKey, []);
        if (! in_array($validated['session_id'], $index, true)) {
            $index[] = $validated['session_id'];
        }
        Cache::put($indexKey, $index, self::TTL_SECONDS);

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

        $this->forgetSession($store->id, $validated['session_id']);

        return response()->json(['ok' => true]);
    }

    /**
     * Lista as sessões ativas de checkout de uma loja.
     * Autenticado (Sanctum).
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $indexKey = $this->indexKey($store->id);
        $index = Cache::get($indexKey, []);

        $sessions = [];
        $cleanIndex = [];
        foreach ($index as $sessionId) {
            $session = Cache::get($this->sessionKey($store->id, $sessionId));
            if ($session) {
                $sessions[] = $session;
                $cleanIndex[] = $sessionId;
            }
        }

        // Remove do índice sessões que já expiraram.
        if ($cleanIndex !== $index) {
            Cache::put($indexKey, $cleanIndex, self::TTL_SECONDS);
        }

        // Ordena pelas mais recentes.
        usort($sessions, function ($a, $b) {
            return ($b['last_seen_at'] ?? '') <=> ($a['last_seen_at'] ?? '');
        });

        return response()->json([
            'sessions' => $sessions,
            'count' => count($sessions),
        ]);
    }

    /**
     * Remove uma sessão do cache e do índice.
     */
    private function forgetSession(int $storeId, string $sessionId): void
    {
        $sessionKey = $this->sessionKey($storeId, $sessionId);
        $indexKey = $this->indexKey($storeId);

        Cache::forget($sessionKey);

        $index = Cache::get($indexKey, []);
        $index = array_values(array_filter($index, fn ($id) => $id !== $sessionId));
        Cache::put($indexKey, $index, self::TTL_SECONDS);
    }

    /**
     * Gera a chave de cache para uma sessão.
     */
    private function sessionKey(int $storeId, string $sessionId): string
    {
        return self::SESSION_PREFIX.':'.$storeId.':'.$sessionId;
    }

    /**
     * Gera a chave de cache para o índice de sessões de uma loja.
     */
    private function indexKey(int $storeId): string
    {
        return self::INDEX_PREFIX.':'.$storeId;
    }
}
