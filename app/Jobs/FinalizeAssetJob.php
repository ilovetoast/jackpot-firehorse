<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Jobs\GenerateAssetEmbeddingJob;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetCompletionService;
use App\Services\AssetProcessingFailureService;
use App\Services\ImageEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Finalize Asset Job
 *
 * Finalizes asset processing pipeline by checking completion criteria.
 *
 * STATUS CONTRACT:
 * - Asset.status represents VISIBILITY only (VISIBLE/HIDDEN/FAILED), not processing state
 * - This job does NOT mutate Asset.status
 * - Processing progress is tracked via thumbnail_status, metadata flags, and pipeline_completed_at
 * - Completion criteria are evaluated by AssetCompletionService (explicit, testable rules)
 */
class FinalizeAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Never retry forever - enforce maximum attempts.
     *
     * @var int
     */
    public $tries = 3; // Maximum retry attempts (enforced by AssetProcessingFailureService)

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
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $asset = Asset::findOrFail($this->assetId);

        // Check completion criteria via AssetCompletionService
        $completionService = app(AssetCompletionService::class);
        $isComplete = $completionService->isComplete($asset);

        if ($isComplete) {
            // Asset meets all completion criteria
            // Mark pipeline as completed in metadata (do NOT mutate status)
            $metadata = $asset->metadata ?? [];
            $metadata['pipeline_completed_at'] = now()->toIso8601String();
            
            $updates = ['metadata' => $metadata];

            // For non-image assets (PDF, video, etc.): advance analysis_status to complete.
            // Embedding job is only dispatched for images; without it, status stays stuck at generating_embedding.
            if (!ImageEmbeddingService::isImageMimeType($asset->mime_type)) {
                $updates['analysis_status'] = 'complete';
            }

            $asset->update($updates);

            // Emit asset finalized event
            AssetEvent::create([
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'asset_id' => $asset->id,
                'user_id' => null,
                'event_type' => 'asset.finalized',
                'metadata' => [
                    'job' => 'FinalizeAssetJob',
                ],
                'created_at' => now(),
            ]);

            Log::info('[FinalizeAssetJob] Asset pipeline completed successfully', [
                'asset_id' => $asset->id,
                'original_filename' => $asset->original_filename,
            ]);

            if (ImageEmbeddingService::isImageMimeType($asset->mime_type)) {
                GenerateAssetEmbeddingJob::dispatch($asset->id);
            }
        } else {
            // Asset did not meet completion criteria
            Log::warning('[FinalizeAssetJob] Asset completion skipped - criteria not met', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
                'note' => 'AssetCompletionService determined asset is not ready for completion',
            ]);
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
