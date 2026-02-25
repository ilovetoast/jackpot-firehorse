<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetVersion;
use App\Services\AnalysisStatusLogger;
use App\Services\AssetProcessingFailureService;
use App\Services\FileInspectionService;
use App\Support\Logging\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
     *
     * Accepts either asset ID (legacy) or version ID (version-aware).
     * When version ID: resolves to version, uses version->asset, passes version ID to GenerateThumbnailsJob.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * C9.2: Check if AI tagging should be skipped based on upload-time flag.
     *
     * @param Asset $asset
     * @return bool
     */
    protected function shouldSkipAiTagging(Asset $asset): bool
    {
        $metadata = $asset->metadata ?? [];
        return (bool) ($metadata['_skip_ai_tagging'] ?? false);
    }

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
        // C9.2: Check upload-time AI skip flags (upload-level override)
        $metadata = $asset->metadata ?? [];
        $skipAiTagging = $metadata['_skip_ai_tagging'] ?? false;
        $skipAiMetadata = $metadata['_skip_ai_metadata'] ?? false;
        
        if ($skipAiTagging && $skipAiMetadata) {
            // Both skipped - return empty array (no AI jobs)
            Log::info('[ProcessAssetJob] AI jobs skipped due to upload-time flags', [
                'asset_id' => $asset->id,
                'skip_ai_tagging' => true,
                'skip_ai_metadata' => true,
            ]);
            return [];
        }
        
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

        // Policy allows AI tagging - build job array based on skip flags
        $jobs = [];
        
        // C9.2: Only add AI tagging jobs if not skipped
        if (!$skipAiTagging) {
            $jobs[] = new AiTagAutoApplyJob($asset->id); // Phase J.2.2: Auto-apply high-confidence tags (if enabled)
        }
        
        // C9.2: Only add AI metadata jobs if not skipped
        if (!$skipAiMetadata) {
            $jobs[] = new AiMetadataGenerationJob($asset->id); // Phase I: AI metadata generation (creates candidates)
            $jobs[] = new AiMetadataSuggestionJob($asset->id); // Phase 2 – Step 5: AI metadata suggestions (creates suggestions from candidates)
        }
        
        if (empty($jobs)) {
            Log::info('[ProcessAssetJob] AI jobs skipped due to upload-time flags', [
                'asset_id' => $asset->id,
                'skip_ai_tagging' => $skipAiTagging,
                'skip_ai_metadata' => $skipAiMetadata,
            ]);
        }
        
        return $jobs;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Resolve version-aware or legacy: accept version ID or asset ID
        // Version path: load with lockForUpdate() for race safety
        $version = DB::transaction(fn () => AssetVersion::where('id', $this->assetId)->lockForUpdate()->first());
        $asset = $version ? $version->asset : Asset::findOrFail($this->assetId);
        // When asset ID was passed (e.g. from ProcessAssetOnUpload), resolve current version for pipeline_status updates
        if (!$version && $asset->currentVersion) {
            $version = DB::transaction(fn () => AssetVersion::where('id', $asset->currentVersion->id)->lockForUpdate()->first());
        }
        $thumbnailJobId = $version ? $version->id : $asset->id;

        // Phase 7: Idempotent - skip if version already complete
        if ($version && $version->pipeline_status === 'complete') {
            Log::info('[ProcessAssetJob] Skipping - version already complete', [
                'version_id' => $version->id,
                'asset_id' => $asset->id,
            ]);
            return;
        }

        PipelineLogger::info('PROCESS ASSET: HANDLE START', [
            'asset_id' => $asset->id,
            'version_id' => $version?->id,
            'thumbnail_status' => $asset->thumbnail_status?->value ?? null,
        ]);
        \App\Services\UploadDiagnosticLogger::assetSnapshot($asset, 'ProcessAssetJob START', [
            'version_id' => $version?->id,
            'chain_will_dispatch' => true,
        ]);

        try {
        // Version-aware: Run FileInspectionService for deterministic metadata (no S3 Content-Type, no extension guessing)
        if ($version) {
            $bucket = $asset->storageBucket; // null = use default s3 disk (legacy)
            $inspection = app(FileInspectionService::class)->inspect($version->file_path, $bucket);
            Log::info('[ProcessAssetJob] Version MIME from FileInspectionService', [
                'version_id' => $version->id,
                'mime' => $inspection['mime_type'],
                'file_size' => $inspection['file_size'],
                'is_image' => $inspection['is_image'] ?? null,
            ]);
            $versionUpdate = [
                'mime_type' => $inspection['mime_type'],
                'file_size' => $inspection['file_size'],
                'width' => $inspection['width'],
                'height' => $inspection['height'],
                'pipeline_status' => 'processing',
            ];
            if (isset($inspection['storage_class'])) {
                $versionUpdate['storage_class'] = $inspection['storage_class'];
            }
            $version->update($versionUpdate);
        }

        // ZIP/archive short-circuit: never run full pipeline — complete immediately to avoid indefinite processing
        // These types cannot generate thumbnails, previews, or image-derived metadata; running the chain wastes
        // queue capacity and can cause stuck states if any job fails or retries indefinitely.
        $mimeForCheck = $version ? $version->mime_type : $asset->mime_type;
        $extForCheck = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $unsupported = $fileTypeService->getUnsupportedReason($mimeForCheck, $extForCheck);
        if ($unsupported) {
            $this->shortCircuitUnsupportedType($asset, $version, $unsupported);
            return;
        }

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
        // Version path: use version metadata. Legacy (Starter): use asset metadata.
        $existingMetadata = $version ? ($version->metadata ?? []) : ($asset->metadata ?? []);
        if (isset($existingMetadata['processing_started']) && $existingMetadata['processing_started'] === true) {
            Log::info('Asset processing skipped - processing already started', [
                'asset_id' => $asset->id,
                'version_id' => $version?->id,
            ]);
            return;
        }

        // Guard: only mutate analysis_status when in expected previous state
        $expectedStatus = 'uploading';
        $currentStatus = $asset->analysis_status ?? 'uploading';
        if ($currentStatus !== $expectedStatus) {
            Log::warning('[ProcessAssetJob] Invalid analysis_status transition aborted', [
                'asset_id' => $asset->id,
                'expected' => $expectedStatus,
                'actual' => $currentStatus,
            ]);
            return;
        }

        // 1. When upload finishes: set analysis_status = 'generating_thumbnails'
        $asset->update(['analysis_status' => 'generating_thumbnails']);
        AnalysisStatusLogger::log($asset, 'uploading', 'generating_thumbnails', 'ProcessAssetJob');

        // Mark processing as started in metadata (for idempotency)
        // Version path: persist to version only. Legacy (Starter): persist to asset.
        $processingStarted = [
            'processing_started' => true,
            'processing_started_at' => now()->toIso8601String(),
        ];
        if ($version) {
            $version->update([
                'metadata' => array_merge($version->metadata ?? [], $processingStarted),
            ]);
        } else {
            $asset->update([
                'metadata' => array_merge($asset->metadata ?? [], $processingStarted),
            ]);
        }

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
        // Version path: use version->mime_type only (from FileInspectionService). Legacy: asset->mime_type.
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $mimeForType = $version ? $version->mime_type : $asset->mime_type;
        $extForType = pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION);
        $fileType = $fileTypeService->detectFileType($mimeForType, $extForType);
        $isVideo = $fileType === 'video';
        
        // TASK 4: Prove job dispatch chain is intact
        // GenerateThumbnailsJob is part of the processing chain
        PipelineLogger::warning('PIPELINE: Dispatching GenerateThumbnailsJob in chain', [
            'asset_id' => $asset->id,
        ]);

        PipelineLogger::info('PROCESS ASSET: ABOUT TO DISPATCH CHILD JOBS', [
            'asset_id' => $asset->id,
        ]);

        $chainJobs = [
            new ExtractMetadataJob($asset->id, $version?->id), // Version ID for version-aware path
            new GenerateThumbnailsJob($thumbnailJobId), // Version ID when version-aware
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
            // C9.2: Conditionally add AITaggingJob based on upload-time skip flag
            ...($this->shouldSkipAiTagging($asset) ? [] : [new AITaggingJob($asset->id)]),
            // Phase J.2.2: Check AI tagging policy before proceeding (also respects upload-time skip flags)
            ...$this->getConditionalAiJobs($asset),
            new FinalizeAssetJob($asset->id),
            new PromoteAssetJob($asset->id),
        ]);
        
        Bus::chain($chainJobs)->dispatch();

        // Seed page 1 render for PDFs on dedicated queue.
        if ($fileType === 'pdf') {
            PdfPageRenderJob::dispatch($asset->id, 1);
        }

        PipelineLogger::info('[ProcessAssetJob] Job completed - processing chain dispatched', [
            'asset_id' => $asset->id,
            'job_id' => $this->job?->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
            'chain_job_count' => count($chainJobs),
            'chain_jobs' => array_map(fn($job) => get_class($job), $chainJobs),
        ]);

        // pipeline_status = 'complete' is set by chain jobs (e.g. GenerateThumbnailsJob) when they finish

        PipelineLogger::info('PROCESS ASSET: HANDLE END', [
            'asset_id' => $asset->id,
        ]);
        } catch (\Throwable $e) {
            PipelineLogger::error('PROCESS ASSET: EXCEPTION', [
                'asset_id' => $this->assetId,
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => collect($e->getTrace())->take(5),
            ]);

            // On failure: pipeline_status changes apply to version only
            if ($version && $version->pipeline_status === 'processing') {
                $version->update(['pipeline_status' => 'failed']);
            }

            Log::error('[ProcessAssetJob] Job failed with exception', [
                'asset_id' => $this->assetId,
                'job_id' => $this->job?->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Short-circuit for ZIP, archives, and other unsupported types.
     * Marks thumbnail as SKIPPED, sets pipeline complete, and dispatches FinalizeAssetJob directly.
     * Prevents indefinite processing and queue waste.
     */
    protected function shortCircuitUnsupportedType(Asset $asset, ?AssetVersion $version, array $unsupported): void
    {
        $skipReason = $unsupported['skip_reason'] ?? 'unsupported_file_type';
        $skipMessage = $unsupported['message'] ?? 'Thumbnail generation is not supported for this file type.';

        Log::info('[ProcessAssetJob] Short-circuiting unsupported file type (ZIP/archive) — completing immediately', [
            'asset_id' => $asset->id,
            'version_id' => $version?->id,
            'skip_reason' => $skipReason,
            'mime_type' => $version ? $version->mime_type : $asset->mime_type,
        ]);
        PipelineLogger::warning('PROCESS ASSET: SHORT_CIRCUIT_UNSUPPORTED', [
            'asset_id' => $asset->id,
            'skip_reason' => $skipReason,
        ]);

        $assetMetadata = $asset->metadata ?? [];
        $assetMetadata['thumbnail_skip_reason'] = $skipReason;
        $assetMetadata['thumbnail_skip_message'] = $skipMessage;
        $assetMetadata['thumbnails_generated'] = false;
        $assetMetadata['metadata_extracted'] = true;
        $assetMetadata['preview_generated'] = false;
        $assetMetadata['preview_skipped'] = true;
        $assetMetadata['preview_skipped_reason'] = 'unsupported_file_type';
        $assetMetadata['ai_tagging_completed'] = true;

        $asset->update([
            'thumbnail_status' => \App\Enums\ThumbnailStatus::SKIPPED,
            'thumbnail_error' => $skipMessage,
            'thumbnail_started_at' => null,
            'metadata' => $assetMetadata,
        ]);

        if ($version) {
            $versionMetadata = $version->metadata ?? [];
            $versionMetadata['thumbnail_skip_reason'] = $skipReason;
            $versionMetadata['thumbnail_skip_message'] = $skipMessage;
            $versionMetadata['thumbnails_generated'] = false;
            $version->update([
                'metadata' => $versionMetadata,
                'pipeline_status' => 'complete',
            ]);
        }

        FinalizeAssetJob::dispatch($asset->id);

        PipelineLogger::info('[ProcessAssetJob] Short-circuit complete — FinalizeAssetJob dispatched', [
            'asset_id' => $asset->id,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $version = AssetVersion::find($this->assetId);
        $asset = $version ? $version->asset : Asset::find($this->assetId);

        if ($asset) {
            // Use centralized failure recording service
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts(),
                true // preserveVisibility: uploaded assets must never disappear from grid
            );
        }
    }
}
