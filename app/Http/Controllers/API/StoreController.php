<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Ssl\CloudflareCustomHostnameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return response()->json($request->user()->stores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'subdomain' => 'nullable|string|max:255|unique:stores,subdomain',
        ]);

        // Auto-gera subdomínio único a partir do nome se não informado.
        // Re-geramos com sufixo crescente em caso de race condition na
        // verificação de unicidade.
        if (empty($validated['subdomain'])) {
            $validated['subdomain'] = Store::generateUniqueSubdomain($validated['name']);
        }

        $attempts = 0;
        $maxAttempts = 10;

        while ($attempts < $maxAttempts) {
            try {
                $store = $request->user()->stores()->create($validated);

                return response()->json($store, 201);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempts++;

                // Só trata colisão de subdomínio; outras UNIQUEs propagam.
                if (! str_contains($e->getMessage(), 'stores_subdomain_unique')) {
                    throw $e;
                }

                $validated['subdomain'] = Store::generateUniqueSubdomain($validated['name'] . ' ' . $attempts);
            }
        }

        return response()->json(['error' => 'Não foi possível gerar um subdomínio único. Tente novamente.'], 500);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($id);
        return response()->json($store);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $store = $request->user()->stores()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|max:255',
            'subdomain' => 'sometimes|required|string|max:255|unique:stores,subdomain,' . $store->id,
            'status' => 'boolean'
        ]);

        $store->update($validated);

        // custom_domain so pode ser alterado pelo fluxo validado da Cloudflare.
        if (array_key_exists('subdomain', $validated)) {
            $store->regenerateProductUrls();
        }

        return response()->json($store->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(
        string $id,
        Request $request,
        CloudflareCustomHostnameService $cloudflare,
    ) {
        $store = $request->user()->stores()->findOrFail($id);

        try {
            foreach ($store->domains as $domain) {
                $cloudflare->delete($domain->cloudflare_custom_hostname_id, $domain->domain);
            }
        } catch (Throwable $exception) {
            Log::warning('Falha ao remover Custom Hostname antes de excluir a loja.', [
                'store_id' => $store->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Nao foi possivel remover o dominio da Cloudflare. Tente novamente.',
            ], 502);
        }

        $store->delete();

        return response()->json(null, 204);
    }
}
