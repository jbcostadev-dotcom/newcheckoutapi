<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Ssl\CloudflareCustomHostnameService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCloudflareDomains extends Command
{
    protected $signature = 'domains:sync-cloudflare {--limit=100} {--include-active}';

    protected $description = 'Sincroniza dominios pendentes com o Cloudflare for SaaS';

    public function handle(CloudflareCustomHostnameService $cloudflare): int
    {
        $query = Domain::query();

        if (!$this->option('include-active')) {
            $query->where(function ($query) {
                $query->whereNull('cloudflare_custom_hostname_id')
                    ->orWhere('status', 'pending');
            });
        }

        $domains = $query
            ->oldest('cloudflare_synced_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($domains as $domain) {
            try {
                $cloudflare->syncDomain($domain);
            } catch (Throwable $exception) {
                Log::warning('Falha na sincronizacao automatica de dominio Cloudflare.', [
                    'domain_id' => $domain->id,
                    'hostname' => $domain->domain,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("{$domains->count()} dominio(s) sincronizado(s).");

        return self::SUCCESS;
    }
}
