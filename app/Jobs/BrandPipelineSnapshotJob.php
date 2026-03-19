<?php

namespace App\Jobs;

use App\Models\BrandPipelineRun;
use App\Services\BrandDNA\BrandResearchNotificationService;
use App\Services\BrandDNA\BrandSnapshotService;
use App\Services\BrandDNA\BrandVersionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates snapshot from merged extraction and marks pipeline complete.
 * Triggered by BrandPipelineRunnerJob after Claude extraction completes.
 */
class BrandPipelineSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $runId
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(BrandSnapshotService $snapshotService): void
    {
        $run = BrandPipelineRun::with(['brand', 'brandModelVersion'])->findOrFail($this->runId);

        $snapshot = $snapshotService->generate($run);

        $run->update([
            'stage' => BrandPipelineRun::STAGE_COMPLETED,
            'status' => BrandPipelineRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $draft = $run->brandModelVersion;
        if ($draft && $snapshot) {
            $draft->getOrCreateInsightState($snapshot->id);
            app(BrandResearchNotificationService::class)->maybeNotifyResearchReady($run->brand, $draft);
            app(BrandVersionService::class)->markResearchComplete($draft);
        }

        Log::channel('pipeline')->info('[BrandPipelineSnapshotJob] Snapshot generated — progression gate will now allow user to view next page', [
            'run_id' => $run->id,
            'draft_id' => $run->brand_model_version_id,
            'pages_total' => $run->pages_total,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $run = BrandPipelineRun::find($this->runId);
        if (! $run || $run->status === BrandPipelineRun::STATUS_COMPLETED) {
            return;
        }

        $run->update([
            'stage' => BrandPipelineRun::STAGE_FAILED,
            'status' => BrandPipelineRun::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Snapshot generation failed',
        ]);

        Log::channel('pipeline')->error('[BrandPipelineSnapshotJob] Job failed permanently', [
            'run_id' => $this->runId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
