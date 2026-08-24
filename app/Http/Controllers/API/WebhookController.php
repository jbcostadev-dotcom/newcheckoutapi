<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Support\WebhookUrlGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        return response()->json(
            $store->webhooks()
                ->withCount('deliveries')
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $validated = $this->validatePayload($request);

        $webhook = $store->webhooks()->create([
            ...$validated,
            'token' => $store->webhook_token,
        ]);

        return response()->json($webhook, 201);
    }

    public function update(Request $request, string $storeId, string $webhookId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $webhook = $store->webhooks()->findOrFail($webhookId);
        $webhook->update($this->validatePayload($request));

        return response()->json($webhook->fresh()->loadCount('deliveries'));
    }

    public function destroy(Request $request, string $storeId, string $webhookId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $store->webhooks()->findOrFail($webhookId)->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'url' => [
                'required',
                'string',
                'max:2048',
                'url:http,https',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_string($value) || ! WebhookUrlGuard::isAllowed($value)) {
                        $fail('Informe uma URL HTTP ou HTTPS pública. Endereços locais e redes privadas não são permitidos.');
                    }
                },
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(Webhook::EVENTS)],
            'is_active' => ['required', 'boolean'],
        ], [
            'events.min' => 'Selecione pelo menos um evento.',
            'events.*.in' => 'Um dos eventos selecionados é inválido.',
        ]);

        return $validator->validate();
    }
}
