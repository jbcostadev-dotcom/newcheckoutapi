<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\AbandonedCartController;
use Illuminate\Console\Command;

class UpdateExpiredAbandonedCarts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'abandoned-carts:update-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marca carrinhos abandonados cujo PIX/Boleto expirou (30 minutos).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = AbandonedCartController::markExpiredPayments();
        $this->info("{$count} carrinho(s) marcado(s) como expirado(s).");

        return self::SUCCESS;
    }
}
