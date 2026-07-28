<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

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
            'custom_domain' => 'nullable|string|max:255|unique:stores,custom_domain',
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
            'custom_domain' => 'nullable|string|max:255|unique:stores,custom_domain,' . $store->id,
            'status' => 'boolean'
        ]);

        $store->update($validated);

        // Se subdomain ou custom_domain mudaram, regenera os links de checkout.
        if (array_key_exists('subdomain', $validated) || array_key_exists('custom_domain', $validated)) {
            $store->regenerateProductUrls();
        }

        return response()->json($store->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($id);
        $store->delete();

        return response()->json(null, 204);
    }
}
