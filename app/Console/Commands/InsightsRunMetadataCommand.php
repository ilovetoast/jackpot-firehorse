<?php

namespace App\Console\Commands;

use App\Jobs\RunMetadataInsightsJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Queue metadata insights sync (value + field suggestion engines) per tenant.
 *
 * @see RunMetadataInsightsJob
 */
class InsightsRunMetadataCommand extends Command
{
    protected $signature = 'insights:run-metadata
                            {tenant_id? : Limit to a single tenant ID}
                            {--force : Bypass the 24h per-tenant cooldown (monthly cap still applies)}
                            {--sync : Run synchronously when tenant_id is set (for debugging)}';

    protected $description = 'Run metadata insights suggestion sync for tenants (scheduled daily; respects caps and cooldown).';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $tenantArg = $this->argument('tenant_id');

        if ($tenantArg !== null && $tenantArg !== '') {
            $tid = (int) $tenantArg;
            if ($tid < 1) {
                $this->error('tenant_id must be a positive integer.');

                return self::FAILURE;
            }

            if ($this->option('sync')) {
                RunMetadataInsightsJob::dispatchSync($tid, $force);
                $this->info("Completed metadata insights sync for tenant {$tid}.");
            } else {
                RunMetadataInsightsJob::dispatch($tid, $force);
                $this->info("Queued metadata insights job for tenant {$tid}.");
            }

            return self::SUCCESS;
        }

        $ids = Tenant::query()
            ->where('ai_insights_enabled', true)
            ->orderBy('id')
            ->pluck('id');

        $n = 0;
        foreach ($ids as $id) {
            RunMetadataInsightsJob::dispatch((int) $id, $force);
            $n++;
        }

        $this->info("Queued {$n} metadata insights job(s) for tenants with ai_insights_enabled.");

        return self::SUCCESS;
    }
}
