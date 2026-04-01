<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetVersion;
use App\Services\AnalysisStatusLogger;
use App\Services\AssetProcessingFailureService;
use App\Services\FileInspectionService;
use App\Services\SystemIncidentService;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Support\Logging\PipelineLogger;
use App\Support\PipelineQueueResolver;
use App\Enums\ThumbnailStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    /**
     * High enough for Redis throttle + queue safe-mode release() cycles (each pickup counts as an attempt).
     *
     * @var int
     */
    public $tries = 32;

    /** Stop uncaught-exception retry loops; releases do not count as exceptions. */
    public int $maxExceptions = 1;

    /**
     * Must stay at or under the Horizon supervisor timeout for the queue this job uses.
     *
     * @var int
     */
    public $timeout = 290;

    /** @var int|array<int, int> */
    public $backoff = [60, 300];

    /**
     * Create a new job instance..
     *
     * Accepts either asset ID (legacy) or version ID (version-aware).
     * When version ID: resolves to version, uses version->asset, passes version ID to GenerateThumbnailsJob.
     */
    public function __construct(
        public readonly string $assetId
    ) {
        $this->tries = max(1, (int) config('assets.processing.pipeline_job_max_tries', 5));

        $version = AssetVersion::query()->find($this->assetId);
        $asset = $version?->asset ?? Asset::query()->find($this->assetId);

        if ($asset) {
            $bytes = (int) ($asset->size_bytes ?? 0);
            if ($version) {
                $bytes = max($bytes, (int) ($version->file_size ?? 0));
            } else {
                $cv = $asset->currentVersion()->first();
                if ($cv) {
                    $bytes = max($bytes, (int) ($cv->file_size ?? 0));
                }
            }
            $queue = PipelineQueueResolver::forByteSize($bytes);
            $this->onQueue($queue);
            $heavyName = (string) config('queue.images_heavy_queue', 'images-heavy');
            $this->timeout = $queue === $heavyName
                ? (int) config('assets.processing.process_asset_job_timeout_heavy_seconds', 1780)
                : (int) config('assets.processing.process_asset_job_timeout_seconds', 290);
        } else {
            $this->configureImagesQueue();
            $this->timeout = (int) config('assets.processing.process_asset_job_timeout_seconds', 290);
        }
    }

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
                'user_id' => $asset->user_id,
                'brand_id' => $asset->brand_id,
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
                'user_id' => $asset->user_id,
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'reason' => $policyCheck['reason'] ?? 'policy_denied',
            ]);
            return []; // Skip AI jobs entirely
        }

        // Policy allows AI tagging - build job array based on skip flags.
        // Order is critical: AiMetadataGenerationJob creates asset_tag_candidates; AiTagAutoApplyJob must run after that.
        $jobs = [];

        // C9.2: AI metadata generation (creates tag + field candidates)
        if (! $skipAiMetadata) {
            $jobs[] = new AiMetadataGenerationJob($asset->id);
        }

        // C9.2: Auto-apply high-confidence tags (tenant setting enable_ai_tag_auto_apply) — only after candidates exist
        if (! $skipAiTagging) {
            $jobs[] = new AiTagAutoApplyJob($asset->id);
        }

        // Phase 2 – Step 5: suggestions from structured candidates
        if (! $skipAiMetadata) {
            $jobs[] = new AiMetadataSuggestionJob($asset->id);
        }
        
        if (empty($jobs)) {
            Log::info('[ProcessAssetJob] AI jobs skipped due to upload-time flags', [
                'asset_id' => $asset->id,
                'user_id' => $asset->user_id,
                'brand_id' => $asset->brand_id,
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

        if (config('assets.processing.throttle_enabled', true)) {
            $key = $this->assetProcessingThrottleKey($asset);
            Redis::throttle($key)
                ->allow((int) config('assets.processing.throttle_max', 5))
                ->every((int) config('assets.processing.throttle_decay_seconds', 60))
                ->then(
                    fn () => $this->runAssetProcessingPipeline($asset, $version, $thumbnailJobId),
                    function () use ($asset) {
                        $delay = (int) config('assets.processing.throttle_release_seconds', 10);
                        Log::info('[ProcessAssetJob] Pipeline throttle saturated; releasing job', [
                            'asset_id' => $asset->id,
                            'delay_seconds' => $delay,
                        ]);
                        $this->release($delay);
                    }
                );

            return;
        }

        $this->runAssetProcessingPipeline($asset, $version, $thumbnailJobId);
    }

    /**
     * Redis throttle key. Global by default; optional per-tenant bucket so one tenant cannot exhaust the cluster cap.
     */
    protected function assetProcessingThrottleKey(Asset $asset): string
    {
        $base = (string) config('assets.processing.throttle_key', 'asset-processing');
        if (config('assets.processing.throttle_per_tenant')) {
            return $base.':'.$asset->tenant_id;
        }

        return $base;
    }

    /**
     * Heavy pipeline: storage inspection, guards, and child job chain.
     */
    protected function runAssetProcessingPipeline(Asset $asset, ?AssetVersion $version, string $thumbnailJobId): void
    {
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

            if (($inspection['width'] ?? null) && ($inspection['height'] ?? null)) {
                $asset->update([
                    'width' => $inspection['width'],
                    'height' => $inspection['height'],
                ]);
            }
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

        // Only process assets that are VISIBLE (not hidden or failed).
        // Exception: category-based approval (requires_approval on Category) sets status to HIDDEN
        // before publish, while analysis_status is still the initial "uploading" value. Those assets
        // must still run thumbnails + AI so approvers can review; visibility stays HIDDEN until published.
        if ($asset->status !== AssetStatus::VISIBLE) {
            $hiddenAwaitingFirstPipeline = $asset->status === AssetStatus::HIDDEN
                && ($asset->analysis_status ?? 'uploading') === 'uploading';
            if (! $hiddenAwaitingFirstPipeline) {
                Log::info('Asset processing skipped - asset is not visible', [
                    'asset_id' => $asset->id,
                    'status' => $asset->status->value,
                ]);
                return;
            }
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
        // 1. ExtractMetadataJob - Extract file metadata (canonical / video basics)
        // 2. ExtractEmbeddedMetadataJob - EXIF/IPTC/PDF tags → payload + governed index (best-effort)
        // 2b. EmbeddedUsageRightsSuggestionJob - optional usage_rights suggestion from embedded copyright (no AI quota)
        // 3. GenerateThumbnailsJob - Generate thumbnail styles
        // 4. GeneratePreviewJob - Generate preview images
        // 5. GenerateVideoPreviewJob - Generate video hover previews (video assets only)
        // 6. ComputedMetadataJob - Compute technical metadata (Phase 5)
        // 7. PopulateAutomaticMetadataJob - Create metadata candidates (Phase B6/B8)
        // 8. ResolveMetadataCandidatesJob - Resolve candidates to asset_metadata (Phase B8)
        // 9. AITaggingJob - pipeline completion flag for tagging step
        // 10. AiMetadataGenerationJob - vision: creates asset_tag_candidates + field candidates
        // 11. AiTagAutoApplyJob - applies tags when enable_ai_tag_auto_apply (must run after step 10)
        // 12. AiMetadataSuggestionJob - suggestions from candidates (after generation)
        // 13. FinalizeAssetJob - Mark asset as completed
        // 14. PromoteAssetJob - Move from temp/ to canonical assets/ location
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
            new ExtractEmbeddedMetadataJob($asset->id, $version?->id),
            new EmbeddedUsageRightsSuggestionJob($asset->id),
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

        $fileSizeBytes = 0;
        if ($version) {
            $fileSizeBytes = (int) ($version->file_size ?? 0);
        } elseif ($asset->size_bytes) {
            $fileSizeBytes = (int) $asset->size_bytes;
        }
        $pipelineQueue = PipelineQueueResolver::forByteSize($fileSizeBytes);

        Bus::chain($chainJobs)
            ->onQueue($pipelineQueue)
            ->dispatch();

        // Seed page 1 render for PDFs on dedicated queue.
        if ($fileType === 'pdf') {
            PdfPageRenderJob::dispatch($asset->id, 1)->onQueue($pipelineQueue);
        }

        PipelineLogger::info('[ProcessAssetJob] Job completed - processing chain dispatched', [
            'asset_id' => $asset->id,
            'job_id' => $this->job?->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
            'chain_job_count' => count($chainJobs),
            'chain_jobs' => array_map(fn($job) => get_class($job), $chainJobs),
            'pipeline_queue' => $pipelineQueue,
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
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
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

        $q = PipelineQueueResolver::imagesQueueForAsset($asset);
        FinalizeAssetJob::dispatch($asset->id)->onQueue($q);

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

        if (! $asset) {
            return;
        }

        app(SystemIncidentService::class)->resolveOpenQueueJobFailuresForAsset((string) $asset->id);

        $analysis = $asset->analysis_status ?? 'uploading';
        if (in_array($analysis, ['uploading', 'generating_thumbnails'], true)) {
            $meta = array_merge($asset->metadata ?? [], [
                'preview_unavailable_user_message' => 'We could not finish processing this file on the server (for example it may be too large or timed out). You can still download the original.',
                'thumbnail_skip_reason' => 'pipeline_start_failed',
                'thumbnail_skip_message' => 'Processing did not complete.',
                'pipeline_process_asset_exhausted_at' => now()->toIso8601String(),
            ]);
            $asset->update([
                'analysis_status' => 'complete',
                'thumbnail_status' => ThumbnailStatus::SKIPPED,
                'thumbnail_error' => null,
                'thumbnail_started_at' => null,
                'metadata' => $meta,
            ]);
            if ($version) {
                $version->update([
                    'pipeline_status' => 'complete',
                    'metadata' => array_merge($version->metadata ?? [], [
                        'thumbnails_generated' => false,
                        'pipeline_aborted_after_process_failure' => true,
                    ]),
                ]);
            }
            $q = PipelineQueueResolver::imagesQueueForAsset($asset->fresh());
            Bus::chain([
                new FinalizeAssetJob($asset->id),
                new PromoteAssetJob($asset->id),
            ])->onQueue($q)->dispatch();

            return;
        }

        app(AssetProcessingFailureService::class)->recordFailure(
            $asset,
            self::class,
            $exception,
            $this->attempts(),
            true
        );
    }
}
