<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * Lista os templates da loja (opcionalmente filtrados por evento).
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $query = $store->emailTemplates()->latest();

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        return $query->get();
    }

    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'event' => 'required|in:' . implode(',', EmailTemplate::EVENTS),
            'name' => 'required|string|max:150',
            'subject' => 'required|string|max:255',
            'body_html' => 'required|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $template = $store->emailTemplates()->create($validated);

        return response()->json($template, 201);
    }

    public function update(Request $request, string $storeId, string $templateId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $template = $store->emailTemplates()->findOrFail($templateId);

        $validated = $request->validate([
            'event' => 'sometimes|in:' . implode(',', EmailTemplate::EVENTS),
            'name' => 'sometimes|string|max:150',
            'subject' => 'sometimes|string|max:255',
            'body_html' => 'sometimes|string|max:65535',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    public function destroy(Request $request, string $storeId, string $templateId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $template = $store->emailTemplates()->findOrFail($templateId);
        $template->delete();

        return response()->json(null, 204);
    }
}
