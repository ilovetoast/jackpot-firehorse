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
 * Stage 3: Scrape discovered pages. Extract meta, h1, h2, colors.
 */
class ScrapeDiscoveredPagesJob implements ShouldQueue
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
            $run->setStage('scraping_pages', 45);
            $run->appendLog('Stage 3: scraping_pages');

            $discovered = $run->raw_payload['discovered_pages'] ?? [];
            $additionalPages = [];

            foreach ($discovered as $url) {
                try {
                    $html = $this->fetchHtml($url);
                    $structured = $this->extractStructured($html, $url);
                    $additionalPages[] = [
                        'url' => $url,
                        'meta' => $structured['meta'],
                        'headlines' => $structured['headlines'],
                        'colors_detected' => $structured['colors_detected'],
                        'font_families' => $structured['font_families'] ?? [],
                    ];
                } catch (\Throwable $e) {
                    Log::info('[ScrapeDiscoveredPagesJob] Skipped page', ['url' => $url, 'error' => $e->getMessage()]);
                }
            }

            $raw = $run->raw_payload ?? [];
            $raw['additional_pages'] = $additionalPages;
            $run->update(['raw_payload' => $raw]);

            $orchestrator->advanceToNextStage($run);
        } catch (\Throwable $e) {
            Log::warning('[ScrapeDiscoveredPagesJob] Failed', [
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
            Log::warning('[ScrapeDiscoveredPagesJob] Tenant mismatch or missing', ['run_id' => $this->runId]);

            return false;
        }

        return true;
    }
}
