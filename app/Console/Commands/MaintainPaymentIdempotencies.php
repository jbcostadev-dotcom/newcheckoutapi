<?php

namespace App\Console\Commands;

use App\Models\PaymentIdempotency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaintainPaymentIdempotencies extends Command
{
    protected $signature = 'payments:idempotency-maintain';

    protected $description = 'Promove pagamentos travados, emite alertas e remove registros terminais expirados';

    public function handle(): int
    {
        $promoted = 0;
        $alerted = 0;

        PaymentIdempotency::query()
            ->where('state', PaymentIdempotency::STATE_PROCESSING)
            ->whereNotNull('gateway_started_at')
            ->where('gateway_started_at', '<=', now()->subSeconds(
                (int) config('payment_idempotency.processing_stale_seconds', 60)
            ))
            ->select('id')
            ->chunkById(100, function ($records) use (&$promoted) {
                foreach ($records as $candidate) {
                    $record = DB::transaction(function () use ($candidate) {
                        $locked = PaymentIdempotency::query()->lockForUpdate()->find($candidate->id);
                        if (! $locked || $locked->state !== PaymentIdempotency::STATE_PROCESSING) {
                            return null;
                        }

                        $locked->update([
                            'state' => PaymentIdempotency::STATE_INDETERMINATE,
                            'locked_until' => null,
                            'processing_alerted_at' => now(),
                        ]);

                        return $locked;
                    });

                    if (! $record) {
                        continue;
                    }

                    $promoted++;
                    Log::critical('Pagamento em processamento por mais de 60 segundos.', [
                        'payment_idempotency_id' => $record->id,
                        'store_id' => $record->store_id,
                        'scope' => $record->scope,
                        'order_id' => $record->order_id,
                    ]);
                }
            });

        PaymentIdempotency::query()
            ->where('state', PaymentIdempotency::STATE_INDETERMINATE)
            ->whereNull('indeterminate_alerted_at')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->select('id')
            ->chunkById(100, function ($records) use (&$alerted) {
                foreach ($records as $candidate) {
                    $record = PaymentIdempotency::query()->find($candidate->id);
                    if (! $record || $record->state !== PaymentIdempotency::STATE_INDETERMINATE) {
                        continue;
                    }

                    $updated = PaymentIdempotency::query()
                        ->whereKey($record->id)
                        ->whereNull('indeterminate_alerted_at')
                        ->update(['indeterminate_alerted_at' => now()]);

                    if ($updated !== 1) {
                        continue;
                    }

                    $alerted++;
                    Log::critical('Pagamento com resultado indeterminado por mais de 2 minutos.', [
                        'payment_idempotency_id' => $record->id,
                        'store_id' => $record->store_id,
                        'scope' => $record->scope,
                        'order_id' => $record->order_id,
                        'gateway_transaction_id' => $record->gateway_transaction_id,
                    ]);
                }
            });

        $deleted = PaymentIdempotency::query()
            ->whereIn('state', [PaymentIdempotency::STATE_COMPLETED, PaymentIdempotency::STATE_FAILED])
            ->where('updated_at', '<', now()->subDays(
                (int) config('payment_idempotency.retention_days', 90)
            ))
            ->delete();

        $this->info("Promovidos: {$promoted}; alertados: {$alerted}; removidos: {$deleted}.");

        return self::SUCCESS;
    }
}
