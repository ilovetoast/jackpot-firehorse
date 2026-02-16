<?php

namespace App\Jobs;

use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapInferenceService;
use App\Services\BrandDNA\BrandBootstrapOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage 6: AI synthesize full Brand DNA from normalized + ai_signals.
 */
class AISynthesizeBrandDNAJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(BrandBootstrapOrchestrator $orchestrator, BrandBootstrapInferenceService $inferenceService): void
    {
        $run = BrandBootstrapRun::with('brand.tenant')->find($this->runId);
        if (! $run) {
            return;
        }

        if (! $this->validateTenant($run)) {
            return;
        }

        $tenant = $run->brand->tenant;
        $normalized = $run->raw_payload['normalized'] ?? null;
        $aiSignals = $run->raw_payload['ai_signals'] ?? null;
        if (! $normalized || ! $aiSignals) {
            $orchestrator->handleFailure($run, 'Missing normalized or ai_signals for synthesis');

            return;
        }

        try {
            $run->setStage('ai_synthesizing_brand', 95);
            $run->appendLog('Stage 6: ai_synthesizing_brand');

            $result = $inferenceService->infer($run, $tenant);

            $run->update(['ai_output_payload' => $result['ai_response_json']]);

            $orchestrator->advanceToNextStage($run);
        } catch (\Throwable $e) {
            Log::warning('[AISynthesizeBrandDNAJob] Failed', [
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
            Log::warning('[AISynthesizeBrandDNAJob] Tenant mismatch or missing', ['run_id' => $this->runId]);

            return false;
        }

        return true;
    }
}
