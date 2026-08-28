<?php

namespace App\Http\Middleware;

use App\Models\PaymentIdempotency;
use App\Models\Store;
use App\Services\PaymentIdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsurePaymentIdempotency
{
    public function __construct(private readonly PaymentIdempotencyService $idempotency)
    {
    }

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (! in_array($scope, [
            PaymentIdempotency::SCOPE_CHECKOUT,
            PaymentIdempotency::SCOPE_UPSELL,
            PaymentIdempotency::SCOPE_DOWNSELL,
        ], true)) {
            return response()->json(['error' => 'invalid_idempotency_scope'], 500);
        }

        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            if ($this->idempotency->isRequired()) {
                return response()->json([
                    'error' => 'idempotency_key_required',
                    'message' => 'O header Idempotency-Key é obrigatório para processar pagamentos.',
                ], 400);
            }

            Log::notice('Pagamento recebido sem Idempotency-Key durante rollout gradual.', [
                'scope' => $scope,
                'ip' => $request->ip(),
            ]);

            return $next($request);
        }

        if (! $this->idempotency->validKey($key)) {
            return response()->json([
                'error' => 'invalid_idempotency_key',
                'message' => 'Idempotency-Key deve ter de 16 a 128 caracteres ASCII seguros.',
            ], 422);
        }

        $identifier = $request->input('store_id') ?? $request->input('domain');
        $store = $identifier !== null
            ? Store::resolveByIdentifier((string) $identifier)
            : null;

        // Mantém as respostas de validação/not-found já existentes no controller.
        if (! $store) {
            return $next($request);
        }

        $requestHash = $this->idempotency->requestHash($scope, $store, $request->all());

        return $this->idempotency->execute(
            $request,
            $store,
            $scope,
            $key,
            $requestHash,
            fn () => $next($request),
        );
    }
}
