<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetProcessingFailureService;
use App\Support\Logging\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessAssetJob implements ShouldQueue
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
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Get AI jobs conditionally based on tenant policy.
     * 
     * Phase J.2.2: Enforcement guard for AI tagging controls
     *
     * @param Asset $asset
     * @return array
     */
    protected function getConditionalAiJobs(Asset $asset): array
    {
        $policyService = app(\App\Services\AiTagPolicyService::class);
        $policyCheck = $policyService->shouldProceedWithAiTagging($asset);
        
        if (!$policyCheck['should_proceed']) {
            Log::info('[ProcessAssetJob] AI tagging skipped due to policy', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'reason' => $policyCheck['reason'] ?? 'policy_denied',
            ]);
            return []; // Skip AI jobs entirely
        }

        // Policy allows AI tagging - proceed with normal AI pipeline
        return [
            new AiMetadataGenerationJob($asset->id), // Phase I: AI metadata generation (creates candidates)
            new AiTagAutoApplyJob($asset->id), // Phase J.2.2: Auto-apply high-confidence tags (if enabled)
            new AiMetadataSuggestionJob($asset->id), // Phase 2 – Step 5: AI metadata suggestions (creates suggestions from candidates)
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $assetAtStart = Asset::find($this->assetId);
        PipelineLogger::error('PROCESS ASSET: HANDLE START', [
            'asset_id' => $this->assetId,
            'thumbnail_status' => $assetAtStart?->thumbnail_status?->value ?? null,
        ]);

        try {
            $asset = Asset::findOrFail($this->assetId);

        // Skip if failed (don't reprocess failed assets automatically)
        if ($asset->status === AssetStatus::FAILED) {
            Log::warning('Asset processing skipped - asset is in failed state', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Only process assets that are VISIBLE (not hidden or failed)
        // Asset.status represents VISIBILITY, not processing progress
        // Processing jobs must NOT mutate Asset.status (assets must remain visible in grid)
        // Processing progress is tracked via thumbnail_status, metadata flags, and activity events
        if ($asset->status !== AssetStatus::VISIBLE) {
            Log::info('Asset processing skipped - asset is not visible', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Idempotency: Check if processing has already started (via metadata)
        // Individual jobs in the chain have their own idempotency checks
        $metadata = $asset->metadata ?? [];
        if (isset($metadata['processing_started'])) {
            Log::info('Asset processing skipped - processing already started', [
                'asset_id' => $asset->id,
            ]);
            return;
        }
        
        // Check if processing has already been started
        // If thumbnails are completed but other jobs haven't run, we should still run the chain
        // Only skip if processing_started flag exists (prevents duplicate chains)
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['processing_started']) && $existingMetadata['processing_started'] === true) {
            PipelineLogger::info('[ProcessAssetJob] Processing already started - skipping to prevent duplicate chain', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);
            return;
        }

        // Mark processing as started in metadata (for idempotency)
        // IMPORTANT: Asset.status must NOT be mutated here.
        // Asset.status represents VISIBILITY (UPLOADED = visible in grid, COMPLETED = visible in dashboard),
        // not processing progress. Mutating status would cause assets to disappear from the asset grid
        // (AssetController queries status = UPLOADED). Processing progress is tracked via:
        // - thumbnail_status (for thumbnail generation)
        // - metadata flags (processing_started, metadata_extracted, thumbnails_generated, etc.)
        // - activity events (asset.processing.started, etc.)
        // Only FinalizeAssetJob is authorized to change status from UPLOADED to COMPLETED.
        $metadata['processing_started'] = true;
        $metadata['processing_started_at'] = now()->toIso8601String();
        $asset->update(['metadata' => $metadata]);

        // Emit processing started event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null, // System event
            'event_type' => 'asset.processing.started',
            'metadata' => [
                'job' => 'ProcessAssetJob',
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset processing started', [
            'asset_id' => $asset->id,
            'original_filename' => $asset->original_filename,
        ]);

        // Dispatch processing chain using Bus::chain()
        // Processing pipeline:
        // 1. ExtractMetadataJob - Extract file metadata
        // 2. GenerateThumbnailsJob - Generate thumbnail styles
        // 3. GeneratePreviewJob - Generate preview images
        // 4. GenerateVideoPreviewJob - Generate video hover previews (video assets only)
        // 5. ComputedMetadataJob - Compute technical metadata (Phase 5)
        // 6. PopulateAutomaticMetadataJob - Create metadata candidates (Phase B6/B8)
        // 7. ResolveMetadataCandidatesJob - Resolve candidates to asset_metadata (Phase B8)
        // 8. AITaggingJob - AI-powered tagging
        // 9. AiMetadataGenerationJob - AI metadata generation (Phase I) - creates candidates
        // 10. AiMetadataSuggestionJob - AI metadata suggestions (Phase 2 – Step 5) - creates suggestions from candidates
        // 11. FinalizeAssetJob - Mark asset as completed
        // 12. PromoteAssetJob - Move from temp/ to canonical assets/ location
        //    (runs after thumbnail generation, requires COMPLETED status)
        
        // Check if asset is a video to conditionally add video preview job
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        $isVideo = $fileType === 'video';
        
        // TASK 4: Prove job dispatch chain is intact
        // GenerateThumbnailsJob is part of the processing chain
        PipelineLogger::warning('PIPELINE: Dispatching GenerateThumbnailsJob in chain', [
            'asset_id' => $asset->id,
        ]);

        PipelineLogger::error('PROCESS ASSET: ABOUT TO DISPATCH CHILD JOBS', [
            'asset_id' => $asset->id,
        ]);

        $chainJobs = [
            new ExtractMetadataJob($asset->id),
            new GenerateThumbnailsJob($asset->id),
            new GeneratePreviewJob($asset->id),
        ];
        
        // Add video preview generation for video assets (after thumbnails)
        if ($isVideo) {
            $chainJobs[] = new GenerateVideoPreviewJob($asset->id);
        }
        
        $chainJobs = array_merge($chainJobs, [
            new ComputedMetadataJob($asset->id), // Phase 5: Computed metadata
            new PopulateAutomaticMetadataJob($asset->id), // Phase B6/B8: Create metadata candidates
            new ResolveMetadataCandidatesJob($asset->id), // Phase B8: Resolve candidates to asset_metadata
            new AITaggingJob($asset->id),
            // Phase J.2.2: Check AI tagging policy before proceeding
            ...$this->getConditionalAiJobs($asset),
            new FinalizeAssetJob($asset->id),
            new PromoteAssetJob($asset->id),
        ]);
        
        Bus::chain($chainJobs)->dispatch();

        PipelineLogger::info('[ProcessAssetJob] Job completed - processing chain dispatched', [
            'asset_id' => $asset->id,
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
            'chain_job_count' => count($chainJobs),
            'chain_jobs' => array_map(fn($job) => get_class($job), $chainJobs),
        ]);

        PipelineLogger::error('PROCESS ASSET: HANDLE END', [
            'asset_id' => $asset->id,
        ]);
        } catch (\Throwable $e) {
            PipelineLogger::error('PROCESS ASSET: EXCEPTION', [
                'asset_id' => $this->assetId,
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => collect($e->getTrace())->take(5),
            ]);

            Log::error('[ProcessAssetJob] Job failed with exception', [
                'asset_id' => $this->assetId,
                'job_id' => $this->job->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
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
