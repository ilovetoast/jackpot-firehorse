<?php

namespace App\Jobs;

use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapInferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Infer Brand DNA from a completed bootstrap run.
 * Uses existing AI agent infrastructure. Status: completed → inferred | failed.
 */
class InferBrandFromBootstrapRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(BrandBootstrapInferenceService $inferenceService): void
    {
        $run = BrandBootstrapRun::with(['brand.tenant'])->find($this->runId);
        if (! $run) {
            return;
        }

        if ($run->status !== 'completed') {
            Log::info('[InferBrandFromBootstrapRunJob] Skipping - run not completed', [
                'run_id' => $this->runId,
                'status' => $run->status,
            ]);
            return;
        }

        $tenant = $run->brand?->tenant;
        if (! $tenant) {
            Log::warning('[InferBrandFromBootstrapRunJob] No tenant for run', ['run_id' => $this->runId]);
            $this->markFailed($run, 'Brand has no tenant');
            return;
        }

        // Multi-tenant isolation: ensure run belongs to tenant
        if ($run->brand->tenant_id !== $tenant->id) {
            Log::warning('[InferBrandFromBootstrapRunJob] Tenant mismatch', [
                'run_id' => $this->runId,
                'brand_tenant_id' => $run->brand->tenant_id,
            ]);
            return;
        }

        try {
            $result = $inferenceService->infer($run, $tenant);

            $run->update([
                'status' => 'inferred',
                'ai_output_payload' => $result['ai_response_json'],
            ]);

            Log::info('[InferBrandFromBootstrapRunJob] Inference completed', [
                'run_id' => $this->runId,
                'tokens_in' => $result['tokens_in'],
                'tokens_out' => $result['tokens_out'],
                'cost' => $result['cost'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[InferBrandFromBootstrapRunJob] Failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($run, $e->getMessage());
        }
    }

    protected function markFailed(BrandBootstrapRun $run, string $message): void
    {
        $raw = $run->raw_payload ?? [];
        $raw['error'] = $message;
        $run->update([
            'status' => 'failed',
            'raw_payload' => $raw,
        ]);
    }
}
