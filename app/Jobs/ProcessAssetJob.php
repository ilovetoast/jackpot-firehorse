<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetVersion;
use App\Services\AnalysisStatusLogger;
use App\Services\AssetProcessingFailureService;
use App\Services\Assets\AssetProcessingBudgetService;
use App\Services\Assets\ProcessingBudgetDecision;
use App\Services\FileInspectionService;
use App\Services\SystemIncidentService;
use App\Support\Logging\AssetPipelineTimingLogger;
use App\Support\Logging\PipelineLogger;
use App\Support\Logging\PipelineStepTimer;
use App\Support\Logging\ThumbnailProfilingRecorder;
use App\Support\PipelineQueueResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class ProcessAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    protected ?PipelineStepTimer $pipelineStepTimer = null;

    /**
     * Overridden in constructor from config (default 64). Must cover many throttle release() cycles.
     *
     * @var int
     */
    public $tries = 64;

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
        $this->tries = max(1, (int) config('assets.processing.pipeline_job_max_tries', 64));

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
            $queue = PipelineQueueResolver::forPipeline(
                $bytes,
                $version?->mime_type ?? $asset->mime_type,
                $asset->original_filename
            );
            $this->onQueue($queue);
            $heavyName = (string) config('queue.images_heavy_queue', 'images-heavy');
            $psdName = trim((string) config('queue.images_psd_queue', ''));
            $this->timeout = ($psdName !== '' && $queue === $psdName)
                ? (int) config('assets.processing.process_asset_job_timeout_psd_seconds', 7200)
                : ($queue === $heavyName
                ? (int) config('assets.processing.process_asset_job_timeout_heavy_seconds', 1780)
                : (int) config('assets.processing.process_asset_job_timeout_seconds', 290));
        } else {
            $this->configureImagesQueue();
            $this->timeout = (int) config('assets.processing.process_asset_job_timeout_seconds', 290);
        }
    }

    /**
     * Cap how long throttle + backoff may defer this job (in addition to {@see $tries}).
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(
            max(1, (int) config('assets.processing.pipeline_job_retry_until_minutes', 120))
        );
    }

    /**
     * C9.2: Check if AI tagging should be skipped based on upload-time flag.
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
     */
    /**
     * When ProcessAssetJob dispatches no AI chain (policy or upload opt-out), the pipeline still expects
     * {@see AssetCompletionService} to see ai_tagging_completed.
     */
    protected function markAiTaggingCompleteWhenNoAiJobsDispatched(Asset $asset): void
    {
        $asset->refresh();
        $metadata = $asset->metadata ?? [];
        if (! empty($metadata['ai_tagging_completed'])) {
            return;
        }
        $metadata['ai_tagging_completed'] = true;
        $metadata['ai_tagging_completed_at'] = now()->toIso8601String();
        $asset->update(['metadata' => $metadata]);
    }

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

        if (! $policyCheck['should_proceed']) {
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
        // Order is critical: AiMetadataGenerationJob creates asset_tag_candidates + field candidates (one vision call).
        // IMPORTANT: Tag candidates and structured candidates share AiMetadataGenerationJob. If we only skipped the job
        // when _skip_ai_metadata was set, "AI tagging on + AI metadata off" would never run vision — no tags at all.
        $jobs = [];

        // Run vision when either structured metadata or tag inference is wanted (service respects _skip_ai_* per kind).
        if (! $skipAiMetadata || ! $skipAiTagging) {
            $jobs[] = new AiMetadataGenerationJob($asset->id);
        }

        // C9.2: Auto-apply high-confidence tags (tenant setting enable_ai_tag_auto_apply) — only after candidates exist
        if (! $skipAiTagging) {
            $jobs[] = new AiTagAutoApplyJob($asset->id);
        }

        // Phase 2 – Step 5: suggestions from structured candidates (not tag pills — those come from tag candidates UI)
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
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $sentryTransaction = $this->startSentryAssetProcessTransaction($hub);

        try {
            $this->runProcessAssetHandleBody();
        } finally {
            if ($sentryTransaction !== null) {
                $sentryTransaction->finish();
                $hub->setSpan($parentSpan);
            }
        }
    }

    /**
     * Core handle logic (wrapped by {@see handle()} for optional manual Sentry performance tracing).
     */
    protected function runProcessAssetHandleBody(): void
    {
        // Resolve version-aware or legacy: accept version ID or asset ID
        // Version path: load with lockForUpdate() for race safety
        $version = DB::transaction(fn () => AssetVersion::where('id', $this->assetId)->lockForUpdate()->first());
        $asset = $version ? $version->asset : Asset::findOrFail($this->assetId);
        // When asset ID was passed (e.g. from ProcessAssetOnUpload), resolve current version for pipeline_status updates
        if (! $version && $asset->currentVersion) {
            $version = DB::transaction(fn () => AssetVersion::where('id', $asset->currentVersion->id)->lockForUpdate()->first());
        }
        $thumbnailJobId = $version ? $version->id : $asset->id;
        $this->pipelineStepTimer = PipelineStepTimer::start('ProcessAssetJob', (string) $asset->id, $version?->id);
        $this->pipelineStepTimer->lap('models_resolved', $asset, $version);

        // Phase 7: Idempotent - skip if the main pipeline has already been dispatched and finished this version.
        // Eager {@see GenerateThumbnailsJob} (e.g. studio animation) can set pipeline_status=complete before
        // {@see ProcessAssetJob} runs; in that case version.metadata.processing_started is still false. We must
        // not return early, or analysis_status stays "uploading" and the rest of the chain never runs.
        if ($version && $version->pipeline_status === 'complete') {
            $versionMetaEarly = is_array($version->metadata) ? $version->metadata : [];
            if (($versionMetaEarly['processing_started'] ?? false) === true) {
                Log::info('[ProcessAssetJob] Skipping - version already complete and main pipeline was started', [
                    'version_id' => $version->id,
                    'asset_id' => $asset->id,
                ]);

                return;
            }
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
            $this->pipelineStepTimer?->lap('before_throttle', $asset, $version, [
                'queue_attempt' => $this->attempts(),
            ]);
            $key = $this->assetProcessingThrottleKey($asset);
            Redis::throttle($key)
                ->allow((int) config('assets.processing.throttle_max', 5))
                ->every((int) config('assets.processing.throttle_decay_seconds', 60))
                ->then(
                    function () use ($asset, $version, $thumbnailJobId) {
                        $this->pipelineStepTimer?->lap('throttle_acquired', $asset, $version, [
                            'queue_attempt' => $this->attempts(),
                        ]);
                        $this->runAssetProcessingPipeline($asset, $version, $thumbnailJobId);
                    },
                    function () use ($asset, $version) {
                        $delay = (int) config('assets.processing.throttle_release_seconds', 10);
                        $this->pipelineStepTimer?->lap('throttle_saturated_release', $asset, $version, [
                            'release_delay_seconds' => $delay,
                            'queue_attempt' => $this->attempts(),
                        ]);
                        Log::info('[ProcessAssetJob] Pipeline throttle saturated; releasing job', [
                            'asset_id' => $asset->id,
                            'delay_seconds' => $delay,
                        ]);
                        $this->release($delay);
                    }
                );

            return;
        }

        $this->pipelineStepTimer?->lap('throttle_disabled', $asset, $version);
        $this->runAssetProcessingPipeline($asset, $version, $thumbnailJobId);
    }

    /**
     * When automatic queue transactions are disabled (see config/sentry.php tracing.queue_job_transactions),
     * start a dedicated performance transaction so asset processing stays visible in Sentry.
     */
    protected function startSentryAssetProcessTransaction(HubInterface $hub): ?Transaction
    {
        if (empty(config('sentry.dsn')) && ! config('sentry.spotlight')) {
            return null;
        }
        if (config('sentry.tracing.queue_job_transactions', false)) {
            return null;
        }

        $context = new TransactionContext('asset.process');
        $context->setOp('queue.job');
        $transaction = \Sentry\startTransaction($context);
        $hub->setSpan($transaction);

        return $transaction;
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
            $this->pipelineStepTimer?->lap('pipeline_entry', $asset, $version);

            $budgetService = app(AssetProcessingBudgetService::class);
            $fileSizeForBudget = max((int) ($version?->file_size ?? 0), (int) ($asset->size_bytes ?? 0));
            $mimeForBudget = $version?->mime_type ?? $asset->mime_type;
            if ($version && $fileSizeForBudget <= 0 && $asset->storageBucket) {
                try {
                    $peek = app(FileInspectionService::class)->peekRemoteMetadata($version->file_path, $asset->storageBucket);
                    $fileSizeForBudget = max($fileSizeForBudget, (int) ($peek['file_size'] ?? 0));
                    if (! $mimeForBudget && ! empty($peek['mime_type'])) {
                        $mimeForBudget = $peek['mime_type'];
                    }
                } catch (\Throwable $e) {
                    Log::warning('[ProcessAssetJob] peekRemoteMetadata failed before worker budget', [
                        'asset_id' => $asset->id,
                        'version_id' => $version->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $budgetDecision = $budgetService->classify($asset, $version, [
                'file_size_bytes' => $fileSizeForBudget,
                'mime_type' => $mimeForBudget,
            ]);

            if (! $budgetDecision->isAllowed()) {
                $budgetService->logGuardrail($asset, $version, $budgetDecision, 'ProcessAssetJob');
                $plan = $budgetService->heavyQueueRedispatchPlan($asset, $version, $budgetDecision, $fileSizeForBudget, $mimeForBudget);
                $currentQueueName = (string) ($this->queue ?? config('queue.images_queue', 'images'));
                if ($plan['should_dispatch']
                    && ($plan['target_queue'] ?? '') !== ''
                    && (string) $plan['target_queue'] !== $currentQueueName) {
                    Log::info('[ProcessAssetJob] Worker budget defer — re-dispatching to heavy pipeline queue', [
                        'asset_id' => $asset->id,
                        'version_id' => $version?->id,
                        'target_queue' => $plan['target_queue'],
                        'current_queue' => $currentQueueName,
                    ]);
                    ProcessAssetJob::dispatch($this->assetId)->onQueue((string) $plan['target_queue']);

                    return;
                }

                $this->shortCircuitWorkerBudget($asset, $version, $budgetDecision, $fileSizeForBudget, $mimeForBudget);

                return;
            }

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
                $this->pipelineStepTimer?->lap('file_inspection', $asset->fresh(), $version->fresh());
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

            $this->pipelineStepTimer?->lap('pre_set_generating_thumbnails', $asset, $version);

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

            $vFresh = $version?->fresh();
            $aFresh = $asset->fresh();
            $this->pipelineStepTimer?->lap('processing_marked_started', $aFresh, $vFresh);

            AssetPipelineTimingLogger::record(
                AssetPipelineTimingLogger::EVENT_ORIGINAL_STORED,
                $aFresh,
                $vFresh,
            );

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
            //
            // Time-to-first-thumbnail fast path:
            //   GenerateThumbnailsJob runs FIRST so the asset grid can render the
            //   original-style thumbnail as soon as possible. Width/height/MIME are
            //   already populated on asset+version by FileInspectionService above;
            //   ExtractMetadataJob is not a prerequisite for image thumbnails, and
            //   GenerateThumbnailsJob handles its own EXIF orientation/dimension
            //   discovery for PDF/SVG/PSD/video sources.
            //
            // Main chain (images / images-heavy / images-psd queue):
            //   1.  GenerateThumbnailsJob          - standard/original thumbnails (fast path)
            //   2.  GeneratePreviewJob             - lightweight preview marker (after thumbs)
            //   3.  GenerateVideoPreviewJob        - video hover previews (video only)
            //   4.  ExtractMetadataJob             - canonical / video basics
            //   5.  ExtractEmbeddedMetadataJob     - EXIF/IPTC/PDF tags
            //   6.  EmbeddedUsageRightsSuggestionJob - optional usage_rights from embedded copyright
            //   7.  ComputedMetadataJob            - Phase 5 computed metadata
            //   8.  PopulateAutomaticMetadataJob   - Phase B6/B8 candidates
            //   9.  ResolveMetadataCandidatesJob   - Phase B8 resolve candidates → asset_metadata
            //   10. (removed) AITaggingJob stub — ai_tagging_completed is set in AiMetadataGenerationJob
            //   11. FinalizeAssetJob               - mark asset as completed
            //   12. PromoteAssetJob                - move temp/ → assets/
            //
            // AI follow-up chain (ai / ai-low queue), dispatched in parallel after
            // the main chain. AI vision/suggestion jobs no longer compete with the
            // images queue worker that owns thumbnail generation:
            //   - AiMetadataGenerationJob          - vision call: tag + field candidates
            //   - AiTagAutoApplyJob                - applies high-confidence tags
            //   - AiMetadataSuggestionJob          - structured suggestions from candidates
            // AiMetadataGenerationJob polls until thumbnail paths exist (see assets.processing
            // ai_metadata_thumbnail_max_wait_seconds) because this chain starts in parallel
            // with GenerateThumbnailsJob — slow RAW/PSD jobs can take many minutes.

            // Check if asset is a video to conditionally add video preview job
            // Version path: use version->mime_type only (from FileInspectionService). Legacy: asset->mime_type.
            $fileTypeService = app(\App\Services\FileTypeService::class);
            $mimeForType = $version ? $version->mime_type : $asset->mime_type;
            $extForType = pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION);
            $fileType = $fileTypeService->detectFileType($mimeForType, $extForType);
            $isVideo = $fileType === 'video';

            PipelineLogger::warning('PIPELINE: Dispatching GenerateThumbnailsJob in chain', [
                'asset_id' => $asset->id,
            ]);

            PipelineLogger::info('PROCESS ASSET: ABOUT TO DISPATCH CHILD JOBS', [
                'asset_id' => $asset->id,
            ]);

            // Standard/original thumbnails first — fast path for time-to-first-thumbnail.
            $chainJobs = [
                new GenerateThumbnailsJob($thumbnailJobId), // Version ID when version-aware
                new GeneratePreviewJob($asset->id),
            ];

            // Add video preview generation for video assets (after thumbnails)
            if ($isVideo) {
                $chainJobs[] = new GenerateVideoPreviewJob($asset->id);
            }

            $chainJobs = array_merge($chainJobs, [
                new ExtractMetadataJob($asset->id, $version?->id), // Version ID for version-aware path
                new ExtractEmbeddedMetadataJob($asset->id, $version?->id),
                new EmbeddedUsageRightsSuggestionJob($asset->id),
                new ComputedMetadataJob($asset->id), // Phase 5: Computed metadata
                new PopulateAutomaticMetadataJob($asset->id), // Phase B6/B8: Create metadata candidates
                new ResolveMetadataCandidatesJob($asset->id), // Phase B8: Resolve candidates to asset_metadata
                // AI tagging completion + tag candidates: {@see AiMetadataGenerationJob} on the `ai` queue (parallel).
                // Do not use {@see AITaggingJob} here — it only flipped ai_tagging_completed before vision ran.
                new FinalizeAssetJob($asset->id),
                new PromoteAssetJob($asset->id),
            ]);

            $fileSizeBytes = 0;
            if ($version) {
                $fileSizeBytes = (int) ($version->file_size ?? 0);
            } elseif ($asset->size_bytes) {
                $fileSizeBytes = (int) $asset->size_bytes;
            }
            $pipelineQueue = PipelineQueueResolver::forPipeline(
                $fileSizeBytes,
                $mimeForType,
                $asset->original_filename
            );

            Bus::chain($chainJobs)
                ->onQueue($pipelineQueue)
                ->dispatch();

            AssetPipelineTimingLogger::record(
                AssetPipelineTimingLogger::EVENT_THUMBNAIL_DISPATCHED,
                $asset->fresh(),
                $version?->fresh(),
                [
                    'queue' => $pipelineQueue,
                    'chain_job_count' => count($chainJobs),
                    'is_video' => $isVideo,
                ]
            );

            if (config('assets.quick_grid_thumbnails.enabled', false)) {
                QuickGridThumbnailJob::dispatch(
                    (string) $asset->id,
                    $version?->id !== null ? (string) $version->id : null
                );
            }

            // AI vision/suggestion jobs run in parallel on the dedicated ai queue so
            // they do not compete with thumbnail/preview workers on the images queue.
            // Phase J.2.2 + C9.2: getConditionalAiJobs() already enforces tenant
            // policy + upload-time _skip_ai_* flags.
            $aiJobs = $this->getConditionalAiJobs($asset);
            if (! empty($aiJobs)) {
                $aiQueue = (string) config('queue.ai_queue', 'ai');
                // Belt-and-braces: chain->onQueue() sets the chain queue, but the AI
                // job constructors call configureImagesQueue() via QueuesOnImagesChannel,
                // so we explicitly override each instance to the ai queue too.
                $aiJobsRouted = array_map(
                    static fn ($job) => method_exists($job, 'onQueue') ? $job->onQueue($aiQueue) : $job,
                    $aiJobs
                );

                Bus::chain($aiJobsRouted)
                    ->onQueue($aiQueue)
                    ->dispatch();

                AssetPipelineTimingLogger::record(
                    AssetPipelineTimingLogger::EVENT_AI_CHAIN_DISPATCHED,
                    $asset->fresh(),
                    $version?->fresh(),
                    [
                        'queue' => $aiQueue,
                        'ai_job_count' => count($aiJobsRouted),
                    ]
                );
            } else {
                // Policy/upload skipped all AI jobs — still close the tagging step for AssetCompletionService.
                $this->markAiTaggingCompleteWhenNoAiJobsDispatched($asset);
            }

            $vFresh2 = $version?->fresh();
            $aFresh2 = $asset->fresh();
            $this->pipelineStepTimer?->lap('chain_dispatched', $aFresh2, $vFresh2, [
                'chain_job_count' => count($chainJobs),
                'pipeline_queue' => $pipelineQueue,
                'ai_job_count' => count($aiJobs),
            ]);

            ThumbnailProfilingRecorder::logPipelineJob(
                static::class,
                (string) $asset->id,
                $version?->id,
                'main_chain_dispatched',
                $this->job,
                [
                    'pipeline_queue' => $pipelineQueue,
                    'chain_job_count' => count($chainJobs),
                    'first_chain_job' => GenerateThumbnailsJob::class,
                    'ai_chain_dispatched' => ! empty($aiJobs),
                ]
            );

            if ($isVideo && config('assets.video_ai.enabled', true) && config('assets.video_ai.auto_run_after_upload', false)) {
                $asset->refresh();
                $policyCheck = app(\App\Services\AiTagPolicyService::class)->shouldProceedWithAiTagging($asset);
                $assetMeta = $asset->metadata ?? [];
                $skipVideo = ! empty($assetMeta['_skip_ai_video_insights']);
                if ($policyCheck['should_proceed'] && ! $skipVideo) {
                    $mergedMeta = array_merge($assetMeta, ['ai_video_status' => 'queued']);
                    $asset->update(['metadata' => $mergedMeta]);
                    ProcessVideoInsightsBatchJob::dispatch([(string) $asset->id]);
                }
            }

            // Seed page 1 render for PDFs on dedicated queue.
            if ($fileType === 'pdf') {
                PdfPageRenderJob::dispatch($asset->id, 1)->onQueue($pipelineQueue);
            }

            PipelineLogger::info('[ProcessAssetJob] Job completed - processing chain dispatched', [
                'asset_id' => $asset->id,
                'job_id' => $this->job?->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
                'chain_job_count' => count($chainJobs),
                'chain_jobs' => array_map(fn ($job) => get_class($job), $chainJobs),
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
     * Worker profile / file-size budget exceeded before the heavy pipeline runs.
     * Does not dispatch thumbnail or metadata chains; completes the asset for visibility/download of original.
     */
    protected function shortCircuitWorkerBudget(
        Asset $asset,
        ?AssetVersion $version,
        ProcessingBudgetDecision $decision,
        int $fileSizeBytes,
        ?string $mimeType,
    ): void {
        $budgetService = app(AssetProcessingBudgetService::class);
        $guard = $budgetService->guardrailMetadataPayload($decision);

        Log::info('[ProcessAssetJob] Short-circuiting worker budget — completing without heavy processing', [
            'asset_id' => $asset->id,
            'version_id' => $version?->id,
            'decision' => $decision->kind,
            'code' => $decision->failureCode(),
        ]);
        PipelineLogger::warning('PROCESS ASSET: SHORT_CIRCUIT_WORKER_BUDGET', [
            'asset_id' => $asset->id,
            'decision' => $decision->kind,
        ]);

        $userMsg = $budgetService->humanMessage($decision);
        $assetMetadata = array_merge($asset->metadata ?? [], $guard, [
            'thumbnail_skip_reason' => 'worker_processing_guardrail',
            'thumbnail_skip_message' => $userMsg,
            'thumbnails_generated' => false,
            'metadata_extracted' => true,
            'preview_generated' => false,
            'preview_skipped' => true,
            'preview_skipped_reason' => 'worker_processing_guardrail',
            'ai_tagging_completed' => true,
        ]);

        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => $userMsg,
            'thumbnail_started_at' => null,
            'metadata' => $assetMetadata,
        ]);

        if ($version) {
            $peekData = null;
            if ($asset->storageBucket) {
                try {
                    $peekData = app(FileInspectionService::class)->peekRemoteMetadata($version->file_path, $asset->storageBucket);
                } catch (\Throwable $e) {
                    Log::warning('[ProcessAssetJob] peekRemoteMetadata failed during worker budget short-circuit', [
                        'asset_id' => $asset->id,
                        'version_id' => $version->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $versionMeta = array_merge($version->metadata ?? [], $guard, [
                'thumbnail_skip_reason' => 'worker_processing_guardrail',
                'thumbnail_skip_message' => $userMsg,
                'thumbnails_generated' => false,
            ]);
            $versionUpdate = [
                'metadata' => $versionMeta,
                'pipeline_status' => 'complete',
            ];
            if (is_array($peekData)) {
                $versionUpdate['file_size'] = max((int) ($version->file_size ?? 0), (int) ($peekData['file_size'] ?? 0));
                if ($mimeType || ! empty($peekData['mime_type'])) {
                    $versionUpdate['mime_type'] = $mimeType ?: (string) $peekData['mime_type'];
                }
                if (isset($peekData['storage_class'])) {
                    $versionUpdate['storage_class'] = $peekData['storage_class'];
                }
            }
            $version->update($versionUpdate);
        }

        $q = PipelineQueueResolver::imagesQueueForAsset($asset->fresh());
        Bus::chain([
            new FinalizeAssetJob($asset->id),
            new PromoteAssetJob($asset->id),
        ])->onQueue($q)->dispatch();

        PipelineLogger::info('[ProcessAssetJob] Worker budget short-circuit complete — finalize chain dispatched', [
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
