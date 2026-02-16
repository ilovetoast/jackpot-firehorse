<?php

namespace App\Jobs;

use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapOrchestrator;
use App\Services\BrandDNA\NormalizeBootstrapSignalsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage 4: Normalize scraped signals.
 */
class NormalizeBootstrapSignalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(BrandBootstrapOrchestrator $orchestrator, NormalizeBootstrapSignalsService $normalizer): void
    {
        $run = BrandBootstrapRun::with('brand.tenant')->find($this->runId);
        if (! $run) {
            return;
        }

        if (! $this->validateTenant($run)) {
            return;
        }

        try {
            $run->setStage('normalizing_signals', 60);
            $run->appendLog('Stage 4: normalizing_signals');

            $normalized = $normalizer->normalize($run->raw_payload ?? []);

            $raw = $run->raw_payload ?? [];
            $raw['normalized'] = $normalized;
            $run->update(['raw_payload' => $raw]);

            $orchestrator->advanceToNextStage($run);
        } catch (\Throwable $e) {
            Log::warning('[NormalizeBootstrapSignalsJob] Failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
            $orchestrator->handleFailure($run, $e->getMessage());
        }
    }

    protected function validateTenant(BrandBootstrapRun $run): bool
    {
        $tenant = $run->brand?->tenant;
        if (! $tenant || $run->brand->tenant_id !== $tenant->id) {
            Log::warning('[NormalizeBootstrapSignalsJob] Tenant mismatch or missing', ['run_id' => $this->runId]);

            return false;
        }

        return true;
    }
}
