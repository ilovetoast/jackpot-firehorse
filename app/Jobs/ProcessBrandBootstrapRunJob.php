<?php

namespace App\Jobs;

use App\Jobs\Concerns\ScrapesBootstrapHtml;
use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage 1: Scrape homepage. Phase 7 multi-stage pipeline.
 */
class ProcessBrandBootstrapRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ScrapesBootstrapHtml, SerializesModels;

    protected int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(BrandBootstrapOrchestrator $orchestrator): void
    {
        $run = BrandBootstrapRun::with('brand.tenant')->find($this->runId);
        if (! $run) {
            return;
        }

        if (! $this->validateTenant($run)) {
            return;
        }

        try {
            $run->update(['status' => 'running']);
            $run->setStage('scraping_homepage', 10);
            $run->update(['current_stage_index' => 1]);
            $run->appendLog('Stage 1: scraping_homepage');

            $url = $this->normalizeUrl($run->source_url);
            $html = $this->fetchHtml($url);
            $structured = $this->extractStructured($html, $url);

            $raw = $run->raw_payload ?? [];
            $raw['homepage'] = $structured;
            $run->update(['raw_payload' => $raw]);

            $orchestrator->advanceToNextStage($run);
        } catch (\Throwable $e) {
            Log::warning('[ProcessBrandBootstrapRunJob] Failed', [
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
            Log::warning('[ProcessBrandBootstrapRunJob] Tenant mismatch or missing', ['run_id' => $this->runId]);

            return false;
        }

        return true;
    }
}
