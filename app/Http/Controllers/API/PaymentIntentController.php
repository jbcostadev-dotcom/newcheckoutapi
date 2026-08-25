<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\PaymentIdempotencyService;
use Illuminate\Http\Request;

class PaymentIntentController extends Controller
{
    public function status(Request $request, PaymentIdempotencyService $idempotency)
    {
        $validated = $request->validate([
            'scope' => 'required|in:checkout,upsell',
            'store_id' => 'nullable|integer|exists:stores,id|required_without:domain',
            'domain' => 'nullable|string|max:255|required_without:store_id',
        ]);

        $key = $request->header('Idempotency-Key');
        if (! $idempotency->validKey($key)) {
            return response()->json([
                'error' => 'invalid_idempotency_key',
                'message' => 'Informe um Idempotency-Key válido.',
            ], 422);
        }

        $identifier = $validated['store_id'] ?? $validated['domain'];
        $store = Store::resolveByIdentifier((string) $identifier);
        if (! $store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        return $idempotency->status(
            $store,
            (string) $validated['scope'],
            (string) $key,
        );
    }
}
