<?php

namespace App\Console\Commands;

use App\Services\WebhookService;
use Illuminate\Console\Command;

class DispatchPendingWebhookEvents extends Command
{
    protected $signature = 'webhooks:dispatch-pending-events';

    protected $description = 'Emite eventos de webhook dependentes de uma janela de inatividade.';

    public function handle(WebhookService $webhooks): int
    {
        $count = $webhooks->dispatchScheduledEvents();
        $this->info("{$count} entrega(s) de webhook criada(s).");

        return self::SUCCESS;
    }
}
