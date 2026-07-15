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

        // Auto-gera subdomínio único a partir do nome se não informado
        if (empty($validated['subdomain'])) {
            $validated['subdomain'] = Store::generateUniqueSubdomain($validated['name']);
        }

        $store = $request->user()->stores()->create($validated);

        return response()->json($store, 201);
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

        return response()->json($store);
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
