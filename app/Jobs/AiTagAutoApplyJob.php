<?php

namespace App\Jobs;

use App\Enums\EventType;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\AiTagAutoApplyService;
use App\Services\AssetProcessingFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AI Tag Auto-Apply Job
 *
 * Phase J.2.2: Automatically applies high-confidence AI tag candidates based on
 * tenant policy settings. Runs after AI metadata generation and before suggestions.
 */
class AiTagAutoApplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AiTagAutoApplyService $autoApplyService): void
    {
        $asset = Asset::findOrFail($this->assetId);

        Log::info('[AiTagAutoApplyJob] Starting auto-apply processing', [
            'asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
        ]);

        try {
            // Check if auto-apply should be processed for this asset
            if (!$autoApplyService->shouldProcessAutoApply($asset)) {
                Log::info('[AiTagAutoApplyJob] Auto-apply not needed or disabled', [
                    'asset_id' => $asset->id,
                    'tenant_id' => $asset->tenant_id,
                ]);
                return;
            }

            // Process auto-apply
            $results = $autoApplyService->processAutoApply($asset);

            // Log activity if any tags were auto-applied
            if ($results['auto_applied'] > 0) {
                ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAGS_AUTO_APPLIED, [
                    'auto_applied_count' => $results['auto_applied'],
                    'total_candidates' => $results['total_candidates'] ?? 0,
                    'skipped_count' => $results['skipped'] ?? 0,
                ]);
            }

            Log::info('[AiTagAutoApplyJob] Completed', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'auto_applied' => $results['auto_applied'],
                'skipped' => $results['skipped'],
            ]);

        } catch (\Throwable $e) {
            // Auto-apply failures must not affect the processing pipeline
            Log::error('[AiTagAutoApplyJob] Failed', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Log failure event for timeline display
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAG_AUTO_APPLY_FAILED, [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            // Don't throw - allow job to complete successfully
            // This ensures auto-apply failures don't block the processing pipeline
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Use centralized failure recording service
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts()
            );
        }
    }
}