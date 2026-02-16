<?php

namespace App\Services\BrandDNA;

use App\Jobs\AIExtractBrandSignalsJob;
use App\Jobs\AISynthesizeBrandDNAJob;
use App\Jobs\DiscoverBrandPagesJob;
use App\Jobs\NormalizeBootstrapSignalsJob;
use App\Jobs\ProcessBrandBootstrapRunJob;
use App\Jobs\ScrapeDiscoveredPagesJob;
use App\Models\BrandBootstrapRun;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7: Multi-stage Brand Bootstrap Orchestrator.
 * Advances runs through pipeline stages and dispatches appropriate jobs.
 */
class BrandBootstrapOrchestrator
{
    public const STAGES = [
        1 => 'scraping_homepage',
        2 => 'discovering_pages',
        3 => 'scraping_pages',
        4 => 'normalizing_signals',
        5 => 'ai_extracting_signals',
        6 => 'ai_synthesizing_brand',
    ];

    public const PROGRESS = [
        1 => 10,
        2 => 25,
        3 => 45,
        4 => 60,
        5 => 80,
        6 => 95,
    ];

    /**
     * Advance run to next stage. Dispatch appropriate job or mark complete.
     * Called by each stage job on success. current_stage_index tracks which stage we just completed.
     */
    public function advanceToNextStage(BrandBootstrapRun $run): void
    {
        $run->refresh();

        $completedIndex = (int) ($run->current_stage_index ?? 0);
        $nextIndex = $completedIndex + 1;

        if ($nextIndex > count(self::STAGES)) {
            $this->handleCompletion($run);

            return;
        }

        $nextStage = self::determineNextStage($nextIndex);
        if (! $nextStage) {
            $this->handleCompletion($run);

            return;
        }

        $run->incrementStage();
        $run->setStage($nextStage, self::PROGRESS[$nextIndex] ?? 0);
        $run->appendLog("Stage {$nextIndex}: {$nextStage}");

        $this->dispatchJobForStage($nextIndex, $run);
    }

    /**
     * Determine next stage name by index.
     */
    public static function determineNextStage(int $stageIndex): ?string
    {
        return self::STAGES[$stageIndex] ?? null;
    }

    /**
     * Dispatch the job for the given stage index.
     */
    protected function dispatchJobForStage(int $stageIndex, BrandBootstrapRun $run): void
    {
        match ($stageIndex) {
            1 => ProcessBrandBootstrapRunJob::dispatch($run->id),
            2 => DiscoverBrandPagesJob::dispatch($run->id),
            3 => ScrapeDiscoveredPagesJob::dispatch($run->id),
            4 => NormalizeBootstrapSignalsJob::dispatch($run->id),
            5 => AIExtractBrandSignalsJob::dispatch($run->id),
            6 => AISynthesizeBrandDNAJob::dispatch($run->id),
            default => null,
        };
    }

    /**
     * Mark run as fully inferred.
     */
    protected function handleCompletion(BrandBootstrapRun $run): void
    {
        $run->update([
            'status' => 'inferred',
            'stage' => 'inferred',
            'progress_percent' => 100,
        ]);
        $run->appendLog('Pipeline completed.');

        Log::info('[BrandBootstrapOrchestrator] Pipeline completed', ['run_id' => $run->id]);
    }

    /**
     * Handle failure. Set status and log.
     */
    public function handleFailure(BrandBootstrapRun $run, string $message): void
    {
        $raw = $run->raw_payload ?? [];
        $raw['error'] = $message;
        $run->update([
            'status' => 'failed',
            'raw_payload' => $raw,
        ]);
        $run->appendLog("Failed: {$message}");

        Log::warning('[BrandBootstrapOrchestrator] Pipeline failed', [
            'run_id' => $run->id,
            'error' => $message,
        ]);
    }
}
