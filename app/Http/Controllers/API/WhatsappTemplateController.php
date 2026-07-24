<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;

class WhatsappTemplateController extends Controller
{
    /**
     * Lista os templates da loja (opcionalmente filtrados por evento).
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $query = $store->whatsappTemplates()->latest();

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        return $query->get();
    }

    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'event' => 'required|in:' . implode(',', WhatsappTemplate::EVENTS),
            'name' => 'required|string|max:150',
            'message' => 'required|string|max:4000',
            'is_active' => 'boolean',
        ]);

        $template = $store->whatsappTemplates()->create($validated);

        return response()->json($template, 201);
    }

    public function update(Request $request, string $storeId, string $templateId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $template = $store->whatsappTemplates()->findOrFail($templateId);

        $validated = $request->validate([
            'event' => 'sometimes|in:' . implode(',', WhatsappTemplate::EVENTS),
            'name' => 'sometimes|string|max:150',
            'message' => 'sometimes|string|max:4000',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    public function destroy(Request $request, string $storeId, string $templateId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $template = $store->whatsappTemplates()->findOrFail($templateId);
        $template->delete();

        return response()->json(null, 204);
    }
}