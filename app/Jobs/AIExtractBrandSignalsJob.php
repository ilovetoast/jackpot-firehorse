<?php

namespace App\Jobs;

use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapOrchestrator;
use App\Services\BrandDNA\BrandBootstrapSignalExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage 5: AI extract brand signals from normalized data.
 */
class AIExtractBrandSignalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(BrandBootstrapOrchestrator $orchestrator, BrandBootstrapSignalExtractionService $extractor): void
    {
        $run = BrandBootstrapRun::with('brand.tenant')->find($this->runId);
        if (! $run) {
            return;
        }

        if (! $this->validateTenant($run)) {
            return;
        }

        $tenant = $run->brand->tenant;
        $normalized = $run->raw_payload['normalized'] ?? [];
        if (empty($normalized)) {
            $orchestrator->handleFailure($run, 'No normalized data for signal extraction');

            return;
        }

        try {
            $run->setStage('ai_extracting_signals', 80);
            $run->appendLog('Stage 5: ai_extracting_signals');

            $result = $extractor->extract(
                $normalized,
                $run->source_url ?? '',
                $tenant,
                $run->id
            );

            $raw = $run->raw_payload ?? [];
            $raw['ai_signals'] = $result['ai_signals'];
            $run->update(['raw_payload' => $raw]);

            $orchestrator->advanceToNextStage($run);
        } catch (\Throwable $e) {
            Log::warning('[AIExtractBrandSignalsJob] Failed', [
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
            Log::warning('[AIExtractBrandSignalsJob] Tenant mismatch or missing', ['run_id' => $this->runId]);

            return false;
        }

        return true;
    }
}
