<?php

namespace Tests\Feature;

use App\Models\PaymentIdempotency;
use App\Models\Store;
use App\Models\User;
use App\Services\PaymentIdempotencyService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Este teste deve rodar no job de CI que usa MySQL real e a extensão pcntl.
 * O SQLite é deliberadamente ignorado porque não reproduz os locks e conflitos
 * de índice do ambiente de produção.
 */
class PaymentIdempotencyMySqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_execute_the_gateway_operation_only_once(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Teste de concorrência requer MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Teste de concorrência requer a extensão pcntl.');
        }

        config([
            'payment_idempotency.store' => 'array',
            'payment_idempotency.secret' => 'mysql-concurrency-secret',
            'payment_idempotency.wait_milliseconds' => 2000,
        ]);

        Schema::create('payment_gateway_test_calls', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $user = User::factory()->create();
        $store = Store::create([
            'user_id' => $user->id,
            'name' => 'Loja Concorrência',
            'subdomain' => 'loja-concorrencia',
        ]);
        $key = '55555555-5555-4555-8555-555555555555';
        $payload = [
            'items' => [['product_id' => 10, 'qty' => 1]],
            'payment_method' => 'pix',
        ];

        DB::disconnect();
        $children = [];

        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Não foi possível criar o processo concorrente.');
            }

            if ($pid === 0) {
                try {
                    DB::reconnect();
                    $childStore = Store::findOrFail($store->id);
                    $service = app(PaymentIdempotencyService::class);
                    $hash = $service->requestHash('checkout', $childStore, $payload);
                    $response = $service->execute(
                        Request::create('/api/checkout/process', 'POST'),
                        $childStore,
                        'checkout',
                        $key,
                        $hash,
                        function () {
                            DB::table('payment_gateway_test_calls')->insert([
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            usleep(400000);

                            return response()->json(['order_id' => 987, 'status' => 'waiting_payment']);
                        },
                    );

                    exit($response->getStatusCode() === 200 ? 0 : 1);
                } catch (\Throwable) {
                    exit(2);
                }
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::reconnect();
        $this->assertSame(1, DB::table('payment_gateway_test_calls')->count());
        $this->assertSame(1, PaymentIdempotency::query()->count());
        $this->assertSame(PaymentIdempotency::STATE_COMPLETED, PaymentIdempotency::firstOrFail()->state);
    }
}
