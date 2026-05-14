<?php

namespace App\Jobs;

use App\Enums\DerivativeProcessor;
use App\Enums\DerivativeType;
use App\Enums\ThumbnailStatus;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetPdfPage;
use App\Models\AssetVersion;
use App\Services\AssetDerivativeFailureService;
use App\Services\AssetPathGenerator;
use App\Services\Assets\AssetProcessingBudgetService;
use App\Services\Assets\ProcessingBudgetDecision;
use App\Services\PdfPageRenderingService;
use App\Services\Reliability\ReliabilityEngine;
use App\Services\SystemIncidentService;
use App\Services\ThumbnailGenerationService;
use App\Support\AdminLogStream;
use App\Support\Logging\PipelineLogger;
use App\Support\Logging\PipelineStepTimer;
use App\Support\Logging\ThumbnailProfilingRecorder;
use App\Support\PipelineQueueResolver;
use App\Support\Preview3dMetadata;
use App\Support\ProcessingMetrics;
use App\Support\DerivativeFailureUserMessaging;
use App\Support\ThumbnailMetadata;
use App\Support\ThumbnailMode;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generate Asset Thumbnails Job
 *
 * Background job that generates all configured thumbnail styles for an asset atomically.
 * One job per asset (not per style) - generates all styles in a single execution.
 *
 * The job:
 * - Downloads the asset from S3
 * - Generates all thumbnail styles (thumb, medium, large)
 * - Uploads thumbnails to S3
 * - Updates asset metadata with thumbnail paths
 * - Tracks thumbnail generation status independently
 *
 * Asset remains usable even if thumbnail generation fails.
 *
 * ⚠️ STATUS MUTATION CONTRACT:
 * - Asset.status represents VISIBILITY, not processing progress
 * - This job MUST NOT mutate Asset.status
 * - Asset.status must remain UPLOADED throughout processing (for grid visibility)
 * - Processing progress is tracked via thumbnail_status, metadata flags, and activity events
 * - Only FinalizeAssetJob should change Asset.status to COMPLETED (for dashboard stats)
 *
 * 🔒 THUMBNAIL SYSTEM LOCK:
 * This system is intentionally NON-REALTIME. Thumbnails do NOT auto-update in the grid.
 * Users must refresh the page to see final thumbnails after processing completes.
 * This design prioritizes stability and prevents UI flicker/re-render thrash.
 *
 * Terminal state guarantees:
 * - Every asset MUST reach one of: COMPLETED, FAILED, or SKIPPED
 * - ThumbnailTimeoutGuard enforces 5-minute timeout (prevents infinite PROCESSING)
 * - All execution paths explicitly set terminal state
 *
 * Live updates are a DEFERRED FEATURE. See docs/MEDIA_PIPELINE.md for details.
 *
 * TODO (future): Allow manual thumbnail regeneration per asset.
 * TODO (future): Consider websocket-based thumbnail update broadcasting.
 * TODO (future): Consider thumbnail_version field for live UI refresh.
 */
class GenerateThumbnailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public $tries = 32;

    public int $maxExceptions = 1;

    /**
     * Job timeout in seconds. Queue workers (Horizon default 90s) kill jobs after this.
     * Thumbnail generation for large TIFF/AI/PDF/video can take 2–5+ minutes.
     * Configurable via config('assets.thumbnail.job_timeout_seconds') or THUMBNAIL_JOB_TIMEOUT_SECONDS.
     *
     * @var int
     */
    public $timeout;

    /**
     * Create a new job instance.
     * Phase 3A: Accepts assetVersionId. Falls back to legacy (asset ID) when version not found.
     *
     * @param  string  $assetVersionId  Version ID (or asset ID when legacy)
     * @param  bool  $force  If true, regenerate even when thumbnails already exist
     */
    public function __construct(
        public readonly string $assetVersionId,
        public readonly bool $force = false
    ) {
        // Worker reads this when job starts; handle() may set a lower value for small assets.
        // Use the larger of the two so large-asset jobs are never killed early.
        $job = (int) config('assets.thumbnail.job_timeout_seconds', 900);
        $large = (int) config('assets.thumbnail.large_asset_timeout_seconds', 1800);
        $this->timeout = max($job, $large);
        $this->tries = max(1, (int) config('assets.processing.pipeline_job_max_tries', 64));
        $this->configureImagesQueue();

        try {
            $version = AssetVersion::query()->find($this->assetVersionId);
            $asset = $version?->asset ?? Asset::query()->find($this->assetVersionId);
            if ($asset) {
                $mime = $version?->mime_type ?? $asset->mime_type;
                if (PipelineQueueResolver::isPsdLike($mime, $asset->original_filename)) {
                    $psdT = (int) config('assets.thumbnail.psd_timeout_seconds', 7200);
                    $this->timeout = max($this->timeout, $psdT);
                }
            }
        } catch (\Throwable) {
            // Constructor must not throw; fallback to default timeout
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(
            max(1, (int) config('assets.processing.pipeline_job_retry_until_minutes', 120))
        );
    }

    /**
     * Execute the job.
     *
     * Generates all thumbnail styles for the asset atomically.
     * Updates thumbnail_status and metadata on success or failure.
     */
    public function handle(ThumbnailGenerationService $thumbnailService, PdfPageRenderingService $pdfPageService): void
    {
        // TASK 1: Prove whether GenerateThumbnailsJob runs at all
        // This log MUST appear if the job is dispatched
        PipelineLogger::warning('THUMBNAILS: HANDLE START', [
            'asset_version_id' => $this->assetVersionId,
            'job_id' => $this->job?->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
        ]);

        Log::info('[GenerateThumbnailsJob] Job started', [
            'asset_version_id' => $this->assetVersionId,
            'job_id' => $this->job?->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
        ]);
        $assetForDiag = AssetVersion::find($this->assetVersionId)?->asset ?? Asset::find($this->assetVersionId);
        if ($assetForDiag) {
            \App\Services\UploadDiagnosticLogger::jobStart('GenerateThumbnailsJob', $assetForDiag->id, null, [
                'asset_version_id' => $this->assetVersionId,
            ]);
        }

        // TASK 2: Guarantee thumbnail job NEVER leaves PROCESSING
        // Wrap all thumbnail logic in try/catch and enforce a terminal state
        // Phase 3A: Load version first, fallback to legacy (asset ID) when version not found
        try {
            $pipelineTimer = null;
            $thumbnailGenResult = null;
            $version = AssetVersion::find($this->assetVersionId);
            if ($version) {
                $version->loadMissing('asset');
                if (! $version->asset) {
                    Log::info('[GenerateThumbnailsJob] Skipping — parent asset missing (likely deleted during processing)', [
                        'version_id' => $version->id,
                    ]);

                    return;
                }
                // Phase 7: Idempotent - skip if version already failed
                if ($version->pipeline_status === 'failed') {
                    Log::info('[GenerateThumbnailsJob] Skipping - version pipeline_status is failed', [
                        'version_id' => $version->id,
                        'asset_id' => $version->asset_id,
                    ]);

                    return;
                }

                // Glacier: skip if archived (getObject would fail or trigger restore)
                $archived = in_array($version->storage_class ?? '', ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'], true);
                if ($archived) {
                    Log::info('[GenerateThumbnailsJob] Skipping - version is archived in Glacier', [
                        'version_id' => $version->id,
                        'storage_class' => $version->storage_class,
                    ]);

                    return;
                }

                // Idempotency: skip if thumbnails already exist and force not requested
                $versionMeta = $version->metadata ?? [];
                $hasThumbnails = ThumbnailMetadata::hasThumb($versionMeta);
                if ($hasThumbnails && ! $this->force) {
                    Log::info('[GenerateThumbnailsJob] Skipping - thumbnails already exist (use force to regenerate)', [
                        'version_id' => $version->id,
                        'asset_id' => $version->asset_id,
                    ]);

                    // {@see ProcessAssetJob} set version.pipeline_status=processing before the chain.
                    // The full success path below sets complete here; without that, {@see FinalizeAssetJob} exits
                    // ("version pipeline not complete") and the asset never gets pipeline_completed_at.
                    $asset = $version->asset;
                    $version->update(['pipeline_status' => 'complete']);
                    $currentAnalysis = $asset->analysis_status ?? 'uploading';
                    if ($currentAnalysis === 'generating_thumbnails') {
                        $asset->update(['analysis_status' => 'extracting_metadata']);
                        \App\Services\AnalysisStatusLogger::log(
                            $asset,
                            'generating_thumbnails',
                            'extracting_metadata',
                            'GenerateThumbnailsJob'
                        );
                    }

                    return;
                }

                $asset = $version->asset;
                $sourcePath = $version->file_path;

                // Phase 7: Delete existing thumbnails for this version (idempotent rerun).
                // PDF page derivatives are NOT deleted — they are permanent deterministic variants (assets/.../pdf-pages/).
                $thumbnailsPrefix = dirname($version->file_path).'/thumbnails/';
                if ($asset->storageBucket) {
                    $s3Client = $this->createS3Client();
                    $this->deleteS3Prefix($s3Client, $asset->storageBucket->name, $thumbnailsPrefix);
                }

                Log::info('[GenerateThumbnailsJob] Version-aware mode', [
                    'asset_id' => $asset->id,
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'source_path' => $sourcePath,
                ]);
            } else {
                // Legacy fallback: treat ID as asset ID
                $asset = Asset::query()->find($this->assetVersionId);
                if (! $asset) {
                    Log::info('[GenerateThumbnailsJob] Skipping — asset no longer exists (likely deleted during processing)', [
                        'asset_id' => $this->assetVersionId,
                    ]);

                    return;
                }
                $sourcePath = $asset->storage_root_path;
                Log::info('[GenerateThumbnailsJob] Legacy mode (no version)', [
                    'asset_id' => $asset->id,
                    'source_path' => $sourcePath,
                ]);
            }

            $pipelineTimer = PipelineStepTimer::start('GenerateThumbnailsJob', (string) $asset->id, $version?->id);
            $pipelineTimer->lap('source_resolved', $asset, $version, [
                'queue_job_id' => $this->job?->getJobId() ?? 'unknown',
                'queue_attempt' => $this->attempts(),
            ]);

            \App\Support\Logging\AssetPipelineTimingLogger::record(
                \App\Support\Logging\AssetPipelineTimingLogger::EVENT_THUMBNAIL_STARTED,
                $asset,
                $version,
                ['attempt' => $this->attempts()]
            );

            // Log asset state at start (after asset lookup)
            PipelineLogger::warning('THUMBNAILS: ASSET LOADED', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
                'thumbnail_started_at' => $asset->thumbnail_started_at?->toIso8601String() ?? 'null',
                'storage_bucket_id' => $asset->storage_bucket_id,
                'storage_root_path' => $asset->storage_root_path,
            ]);

            // Permanent-failure guard: if the asset has no storage path or bucket there is
            // nothing to thumbnail, and retrying will never help. Mark SKIPPED with a clear
            // reason and return cleanly so:
            //   - Sentry is not spammed with "Asset missing storage path or bucket"
            //   - The queue doesn't chew through 32 retries on a hopeless job
            //   - Watchdog / ReliabilityEngine won't keep re-dispatching (status is terminal)
            $sourcePathForCheck = $version ? ($version->file_path ?? null) : ($asset->storage_root_path ?? null);
            if (! $sourcePathForCheck || ! $asset->storageBucket) {
                $reason = 'missing_storage';
                $userMsg = 'We could not build a preview because the source file reference is missing. Please re-upload the file.';
                PipelineLogger::warning('THUMBNAILS: MISSING STORAGE — SKIPPING TERMINAL', [
                    'asset_id' => $asset->id,
                    'version_id' => $version?->id,
                    'storage_bucket_id' => $asset->storage_bucket_id,
                    'storage_root_path' => $asset->storage_root_path,
                    'version_file_path' => $version?->file_path,
                ]);
                Log::warning('[GenerateThumbnailsJob] Asset missing storage path or bucket — marking SKIPPED (non-retryable)', [
                    'asset_id' => $asset->id,
                    'version_id' => $version?->id,
                    'has_storage_bucket_id' => (bool) $asset->storage_bucket_id,
                    'has_storage_root_path' => (bool) $asset->storage_root_path,
                ]);
                $meta = array_merge($asset->metadata ?? [], [
                    'thumbnail_skip_reason' => $reason,
                    'thumbnail_skip_message' => $userMsg,
                    'preview_unavailable_user_message' => $userMsg,
                    'thumbnails_generated' => false,
                    'missing_storage_detected_at' => now()->toIso8601String(),
                ]);
                $analysis = $asset->analysis_status ?? 'uploading';
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::SKIPPED,
                    'thumbnail_error' => null,
                    'thumbnail_started_at' => null,
                    'metadata' => $meta,
                    'analysis_status' => in_array($analysis, ['uploading', 'generating_thumbnails'], true)
                        ? 'complete'
                        : $analysis,
                ]);
                if ($version) {
                    $version->update([
                        'pipeline_status' => 'complete',
                        'metadata' => array_merge($version->metadata ?? [], [
                            'thumbnail_skip_reason' => $reason,
                            'thumbnails_generated' => false,
                        ]),
                    ]);
                }

                return;
            }

            // Idempotency: Skip only for legacy path; version-aware always regenerates (Phase 5)
            if (! $version && $asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
                Log::info('[GenerateThumbnailsJob] Thumbnail generation skipped - already completed', [
                    'asset_id' => $asset->id,
                ]);

                return;
            }

            $fileSizeBytes = $version
                ? (int) ($version->file_size ?? $asset->size_bytes ?? 0)
                : (int) ($asset->size_bytes ?? 0);
            $maxSourceBytes = (int) config('assets.thumbnail.max_source_bytes', 0);
            if ($maxSourceBytes > 0 && $fileSizeBytes > $maxSourceBytes) {
                $this->applyMaxSourceBytesSkip($asset, $version, $fileSizeBytes, $maxSourceBytes);

                return;
            }

            $mimeForBudget = $version ? $version->mime_type : $asset->mime_type;
            if ($version && $fileSizeBytes <= 0 && $asset->storageBucket) {
                try {
                    $peekB = app(\App\Services\FileInspectionService::class)->peekRemoteMetadata($version->file_path, $asset->storageBucket);
                    $fileSizeBytes = max($fileSizeBytes, (int) ($peekB['file_size'] ?? 0));
                    if (! $mimeForBudget && ! empty($peekB['mime_type'])) {
                        $mimeForBudget = $peekB['mime_type'];
                    }
                } catch (\Throwable) {
                    // keep DB values
                }
            }
            $budgetService = app(AssetProcessingBudgetService::class);
            $budgetDecision = $budgetService->classify($asset, $version, [
                'file_size_bytes' => $fileSizeBytes,
                'mime_type' => $mimeForBudget,
            ]);
            if (! $budgetDecision->isAllowed()) {
                $budgetService->logGuardrail($asset, $version, $budgetDecision, 'GenerateThumbnailsJob');
                $this->applyWorkerBudgetThumbnailSkip($asset, $version, $budgetDecision);

                return;
            }

            // Dynamic timeout and pixel guardrails (before any heavy work)
            // Require dimensions for plain raster images (safety: avoid melting worker on huge unknown-size images).
            // PDF/video/SVG/PSD/PSB/office/HEIC: dimensions often missing on the row until we decode (or LibreOffice
            // converts Office → PDF → raster); ThumbnailGenerationService gets width/height from the renderer.
            // HEIC: width/height often missing when EXIF/getimagesize did not run or Imagick could not probe during
            // metadata extraction (e.g. worker without HEIF delegate); ThumbnailGenerationService still reads size
            // via Imagick at decode time when HEIF support is present — do not soft-skip as dimensions_unknown first.
            $fileTypeService = app(\App\Services\FileTypeService::class);
            // Match {@see ThumbnailGenerationService::detectFileType} for extension; also consider
            // `metadata.metadata` from ExtractMetadataJob when the version path has no extension and the asset
            // row lost original_filename — otherwise wrong version MIME + empty ext hits dimensions_unknown for PPTX.
            $ext = $this->resolveThumbnailPipelineExtension($asset, $version);
            $mimeCandidates = $this->resolveThumbnailPipelineMimeCandidates($asset, $version);
            if ($mimeCandidates === []) {
                $mimeCandidates = [''];
            }
            $dimensionsFromRendering = false;
            foreach ($mimeCandidates as $candidateMime) {
                $m = is_string($candidateMime) && $candidateMime !== '' ? strtolower($candidateMime) : '';
                $ft = $fileTypeService->detectFileType($m !== '' ? $m : null, $ext !== '' ? $ext : null);
                if (in_array($ft, ['pdf', 'video', 'svg', 'psd', 'psb', 'heic', 'office'], true)) {
                    $dimensionsFromRendering = true;
                    break;
                }
                if ($fileTypeService->isModel3dRegistryType((string) $ft)) {
                    $dimensionsFromRendering = true;
                    break;
                }
                if ($fileTypeService->isOfficeDocument($m !== '' ? $m : null, $ext !== '' ? $ext : null)) {
                    $dimensionsFromRendering = true;
                    break;
                }
            }
            // `detectFileType` checks MIME before extension. A mis-sniffed early candidate (e.g. `image/jpeg`)
            // wins over `.heic` / `.heif` / `.cr2` / `.avif` on the extension, so the loop above never sets this
            // flag — yet dimensions are legitimately unknown until Imagick decodes. Same pattern as
            // {@see ThumbnailGenerationService::detectFileType} fallbacks for HEIC/CR2/AVIF.
            if (in_array($ext, ['heic', 'heif', 'cr2', 'avif'], true)) {
                $dimensionsFromRendering = true;
            }
            // SVG (and optional compressed SVG): no reliable width/height on the asset row until rsvg-convert
            // rasterizes; do not soft-skip as dimensions_unknown when extension is present but MIME/gate loop
            // did not classify the row as `svg` (e.g. empty extension from storage path + sparse version row).
            if (in_array($ext, ['svg', 'svgz'], true)) {
                $dimensionsFromRendering = true;
            }
            if (in_array($ext, ['glb', 'gltf', 'obj', 'stl', 'fbx', 'blend'], true)) {
                $dimensionsFromRendering = true;
            }

            $assetWidth = $asset->width;
            $assetHeight = $asset->height;
            if ((! $assetWidth || ! $assetHeight) && $version && ($version->width || $version->height)) {
                $assetWidth = $assetWidth ?: $version->width;
                $assetHeight = $assetHeight ?: $version->height;
                $asset->update(['width' => $assetWidth, 'height' => $assetHeight]);
            }

            if (! $dimensionsFromRendering && (! $assetWidth || ! $assetHeight)) {
                Log::warning('[GenerateThumbnailsJob] Skipping thumbnail generation - dimensions unknown (soft fail)', [
                    'asset_id' => $asset->id,
                    'width' => $asset->width,
                    'height' => $asset->height,
                ]);
                $metadata = $asset->metadata ?? [];
                $metadata['thumbnail_skip_reason'] = 'dimensions_unknown';
                $metadata['thumbnail_skip_message'] = 'Asset dimensions are not available; thumbnail generation skipped for safety.';
                $metadata['thumbnails_generated'] = false;
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::SKIPPED,
                    'thumbnail_error' => $metadata['thumbnail_skip_message'],
                    'thumbnail_started_at' => null,
                    'metadata' => $metadata,
                ]);
                if ($version) {
                    $version->update([
                        'metadata' => array_merge($version->metadata ?? [], [
                            'thumbnail_skip_reason' => 'dimensions_unknown',
                            'thumbnails_generated' => false,
                        ]),
                        'pipeline_status' => 'complete',
                    ]);
                }

                return;
            }

            $width = $assetWidth ? (int) $assetWidth : 0;
            $height = $assetHeight ? (int) $assetHeight : 0;
            $pixelCount = $width * $height;
            $maxPixels = (int) config('assets.thumbnail.max_pixels', 100_000_000);
            $largeThreshold = (int) config('assets.thumbnail.large_asset_threshold_pixels', 30_000_000);

            if ($pixelCount > $maxPixels) {
                Log::warning('[GenerateThumbnailsJob] Skipping thumbnail generation - pixel count exceeds max_pixels (soft fail)', [
                    'asset_id' => $asset->id,
                    'pixel_count' => $pixelCount,
                    'max_pixels' => $maxPixels,
                    'width' => $width,
                    'height' => $height,
                ]);
                $metadata = $asset->metadata ?? [];
                $metadata['thumbnail_skip_reason'] = 'pixel_limit_exceeded';
                $metadata['thumbnail_skip_message'] = "Asset dimensions ({$width}×{$height}) exceed maximum allowed for thumbnails.";
                $metadata['thumbnails_generated'] = false;
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::SKIPPED,
                    'thumbnail_error' => $metadata['thumbnail_skip_message'],
                    'thumbnail_started_at' => null,
                    'metadata' => $metadata,
                ]);
                if ($version) {
                    $version->update([
                        'metadata' => array_merge($version->metadata ?? [], [
                            'thumbnail_skip_reason' => 'pixel_limit_exceeded',
                            'thumbnails_generated' => false,
                        ]),
                        'pipeline_status' => 'complete',
                    ]);
                }

                return;
            }

            $timeout = (int) config('assets.thumbnail.job_timeout_seconds', 900);
            if ($pixelCount > $largeThreshold) {
                $timeout = (int) config('assets.thumbnail.large_asset_timeout_seconds', 1800);
            }
            $this->timeout = $timeout;

            // TASK 2: Safety guard - if asset is in PROCESSING from a previous failed attempt,
            // we should set it to a terminal state (FAILED) before proceeding to prevent stuck state
            // This handles the case where a job was interrupted and left PROCESSING
            if ($asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
                $workerSeconds = (int) config('assets.thumbnail.worker_timeout_seconds', 900);
                $timeoutMinutes = (int) ceil($workerSeconds / 60) + 5;
                PipelineLogger::warning('THUMBNAILS: DETECTED STUCK PROCESSING', [
                    'asset_id' => $asset->id,
                    'thumbnail_started_at' => $asset->thumbnail_started_at?->toIso8601String() ?? 'null',
                ]);

                $startedAt = $asset->thumbnail_started_at;
                $minutesElapsed = $startedAt ? now()->diffInMinutes($startedAt, false) : 0;
                PipelineLogger::warning('THUMBNAILS: CHECKING TIMEOUT', [
                    'asset_id' => $asset->id,
                    'started_at' => $startedAt?->toIso8601String() ?? 'null',
                    'minutes_elapsed' => $minutesElapsed,
                    'threshold' => $timeoutMinutes,
                    'is_past' => $startedAt ? $startedAt->isPast() : 'null',
                    'now' => now()->toIso8601String(),
                ]);
                if ($startedAt && $startedAt->isPast() && $minutesElapsed > $timeoutMinutes) {
                    PipelineLogger::warning('THUMBNAILS: TIMEOUT DETECTED - SETTING FAILED', [
                        'asset_id' => $asset->id,
                        'started_at' => $startedAt->toIso8601String(),
                        'minutes_elapsed' => now()->diffInMinutes($startedAt),
                    ]);
                    Log::warning('[GenerateThumbnailsJob] Asset stuck in PROCESSING - setting to FAILED', [
                        'asset_id' => $asset->id,
                        'started_at' => $startedAt,
                        'minutes_elapsed' => now()->diffInMinutes($startedAt),
                    ]);
                    $asset->update([
                        'thumbnail_status' => ThumbnailStatus::FAILED,
                        'thumbnail_error' => "Thumbnail generation timed out (processing started more than {$timeoutMinutes} minutes ago)",
                        'thumbnail_started_at' => null,
                    ]);

                    // Return early - asset is now in terminal state (FAILED)
                    return;
                } elseif (! $startedAt) {
                    // PROCESSING but no started_at - this is invalid state, set to FAILED
                    PipelineLogger::warning('THUMBNAILS: INVALID STATE - PROCESSING WITHOUT started_at - SETTING FAILED', [
                        'asset_id' => $asset->id,
                    ]);
                    Log::warning('[GenerateThumbnailsJob] Asset in PROCESSING without started_at - setting to FAILED', [
                        'asset_id' => $asset->id,
                    ]);
                    $asset->update([
                        'thumbnail_status' => ThumbnailStatus::FAILED,
                        'thumbnail_error' => 'Thumbnail generation in invalid state (PROCESSING without started_at)',
                        'thumbnail_started_at' => null,
                    ]);

                    // Return early - asset is now in terminal state (FAILED)
                    return;
                }
            }

            // Step 5: Defensive check - Skip if file type doesn't support thumbnails
            $mimeCandidatesForGate = $this->resolveThumbnailPipelineMimeCandidates($asset, $version);
            $mimeForCheck = $mimeCandidatesForGate[0] ?? ($version ? $version->mime_type : $asset->mime_type);
            $extForCheck = $this->resolveThumbnailPipelineExtension($asset, $version);

            // PDF guardrail: do not process extremely large page-count documents.
            $isPdf = strtolower($extForCheck) === 'pdf';
            if (! $isPdf) {
                foreach ($mimeCandidatesForGate as $cMime) {
                    if (strtolower((string) $cMime) === 'application/pdf') {
                        $isPdf = true;
                        break;
                    }
                }
            }

            if ($isPdf) {
                $pageCount = (int) ($asset->pdf_page_count ?? 0);
                if ($pageCount < 1) {
                    try {
                        $pageCount = $pdfPageService->getPdfPageCount($asset, true);
                    } catch (\Throwable $countEx) {
                        Log::warning('[GenerateThumbnailsJob] Failed to inspect PDF page count; continuing with thumbnail generation', [
                            'asset_id' => $asset->id,
                            'error' => $countEx->getMessage(),
                        ]);
                    }
                }

                $maxAllowedPages = (int) config('pdf.max_allowed_pages', 500);
                if ($pageCount > $maxAllowedPages) {
                    $metadata = $asset->metadata ?? [];
                    $metadata['pdf_page_count'] = $pageCount;
                    $metadata['pdf_guardrail'] = 'pdf_unsupported_large';
                    $metadata['thumbnails_generated'] = false;

                    $asset->update([
                        'pdf_page_count' => $pageCount,
                        'pdf_unsupported_large' => true,
                        'thumbnail_status' => ThumbnailStatus::SKIPPED,
                        'thumbnail_error' => "PDF has {$pageCount} pages and exceeds allowed limit ({$maxAllowedPages}).",
                        'thumbnail_started_at' => null,
                        'metadata' => $metadata,
                    ]);

                    if ($version) {
                        $version->update([
                            'metadata' => array_merge($version->metadata ?? [], [
                                'pdf_page_count' => $pageCount,
                                'pdf_guardrail' => 'pdf_unsupported_large',
                                'thumbnails_generated' => false,
                            ]),
                            'pipeline_status' => 'complete',
                        ]);
                    }

                    Log::warning('[GenerateThumbnailsJob] Skipping PDF thumbnail generation due to page-count guardrail', [
                        'asset_id' => $asset->id,
                        'page_count' => $pageCount,
                        'max_allowed_pages' => $maxAllowedPages,
                    ]);

                    return;
                }

                if ($pageCount > 0) {
                    $asset->update([
                        'pdf_page_count' => $pageCount,
                        'pdf_unsupported_large' => false,
                    ]);
                }
            }

            if (! $this->supportsThumbnailGeneration($asset, $version)) {
                // Determine skip reason and user-facing message based on file type
                $mimeType = strtolower($mimeForCheck ?? '');
                $extension = strtolower($extForCheck);
                $skipReason = $this->determineSkipReason($mimeType, $extension);
                $skipMessage = $this->getThumbnailSkipMessage($mimeType, $extension, $skipReason);

                // Store skip reason and user-facing message in metadata for UI display
                $metadata = $asset->metadata ?? [];
                $metadata['thumbnail_skip_reason'] = $skipReason;
                $metadata['thumbnail_skip_message'] = $skipMessage;
                $metadata['thumbnails_generated'] = false;

                $skipFt = $fileTypeService->detectFileType($mimeType !== '' ? $mimeType : null, $extension !== '' ? $extension : null);
                if ($skipFt && $fileTypeService->isModel3dRegistryType($skipFt)) {
                    $metadata['preview_3d'] = \App\Support\Preview3dMetadata::merge(
                        $metadata['preview_3d'] ?? [],
                        \App\Support\Preview3dMetadata::forThumbnailJobSkipped($skipReason, $skipMessage)
                    );
                    Log::info('preview_3d.preview_pipeline_skipped', [
                        'event' => 'preview_3d.preview_pipeline_skipped',
                        'asset_id' => $asset->id,
                        'tenant_id' => $asset->tenant_id,
                        'registry_type' => $skipFt,
                        'skip_reason' => $skipReason,
                    ]);
                }

                // Guard: only mutate analysis_status when in expected previous state
                $expectedStatus = 'generating_thumbnails';
                $currentStatus = $asset->analysis_status ?? 'uploading';
                $updateData = [
                    'thumbnail_status' => ThumbnailStatus::SKIPPED,
                    'thumbnail_error' => $skipMessage,
                    'thumbnail_started_at' => null, // SKIPPED never started, so no start time
                    'metadata' => $metadata,
                ];
                if ($currentStatus === $expectedStatus) {
                    $updateData['analysis_status'] = 'extracting_metadata';
                }

                // Mark as skipped with clear error message
                // SKIPPED assets never started processing, so no thumbnail_started_at needed
                $asset->update($updateData);

                if ($currentStatus === $expectedStatus) {
                    \App\Services\AnalysisStatusLogger::log($asset, 'generating_thumbnails', 'extracting_metadata', 'GenerateThumbnailsJob');
                }

                Log::info('[GenerateThumbnailsJob] Marked asset as SKIPPED', [
                    'asset_id' => $asset->id,
                    'skip_reason' => $skipReason,
                    'skip_message' => $skipMessage,
                ]);
                \App\Services\UploadDiagnosticLogger::jobSkip('GenerateThumbnailsJob', $asset->id, $skipReason, [
                    'skip_message' => $skipMessage,
                ]);

                // Log skipped event (truthful - work never happened)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_SKIPPED,
                        [
                            'reason' => $skipReason,
                            'message' => $skipMessage,
                            'mime_type' => $asset->mime_type,
                            'file_extension' => $extension,
                        ]
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to log thumbnail skipped event', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('Thumbnail generation skipped - unsupported file type', [
                    'asset_id' => $asset->id,
                    'mime_type' => $asset->mime_type,
                    'extension' => $extension,
                    'skip_reason' => $skipReason,
                ]);

                if ($skipReason === 'office_libreoffice_missing') {
                    try {
                        app(ReliabilityEngine::class)->report([
                            'source_type' => 'system',
                            'source_id' => null,
                            'tenant_id' => $asset->tenant_id,
                            'severity' => 'warning',
                            'title' => 'LibreOffice missing on workers — Office previews skipped',
                            'message' => 'Thumbnail workers need the `soffice` binary (Ubuntu: libreoffice-nogui). Office uploads stay in a placeholder state until workers match docs/environments/PRODUCTION_WORKER_SOFTWARE.md.',
                            'retryable' => false,
                            'requires_support' => false,
                            'unique_signature' => 'worker:libreoffice_missing:'.app()->environment(),
                            'metadata' => [
                                'skip_reason' => $skipReason,
                                'first_asset_id' => $asset->id,
                            ],
                        ]);
                    } catch (\Throwable $reliabilityEx) {
                        Log::warning('[GenerateThumbnailsJob] ReliabilityEngine report failed (LibreOffice missing)', [
                            'asset_id' => $asset->id,
                            'error' => $reliabilityEx->getMessage(),
                        ]);
                    }
                }

                // Version path: set pipeline_status=complete so FinalizeAssetJob can run (ZIP, ICO, etc.)
                // Without this, version stays at 'processing' and analysis_status never reaches 'complete'
                if ($version) {
                    $version->update([
                        'metadata' => array_merge($version->metadata ?? [], [
                            'thumbnail_skip_reason' => $skipReason,
                            'thumbnail_skip_message' => $skipMessage,
                            'thumbnails_generated' => false,
                        ]),
                        'pipeline_status' => 'complete',
                    ]);
                }

                return;
            }

            // Step 5.5: Clear old skip reasons for formats that are now supported
            // This handles cases where support was added after assets were marked as skipped
            // (e.g., TIFF/AVIF support added via Imagick)
            $metadata = $asset->metadata ?? [];
            if (isset($metadata['thumbnail_skip_reason'])) {
                $skipReason = $metadata['thumbnail_skip_reason'];
                $mimeType = strtolower($asset->mime_type ?? '');
                $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));

                // Check if this skip reason is for a format that's now supported
                $isNowSupported = false;
                if ($skipReason === 'unsupported_format:tiff' &&
                    ($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif') &&
                    extension_loaded('imagick')) {
                    $isNowSupported = true;
                } elseif ($skipReason === 'unsupported_format:avif' &&
                          ($mimeType === 'image/avif' || $extension === 'avif') &&
                          extension_loaded('imagick')) {
                    $isNowSupported = true;
                } elseif ($skipReason === 'unsupported_format:heic' &&
                          ($mimeType === 'image/heic' || $mimeType === 'image/heif' || $extension === 'heic' || $extension === 'heif') &&
                          extension_loaded('imagick')) {
                    $isNowSupported = true;
                } elseif (($skipReason === 'unsupported_format:psd' || $skipReason === 'unsupported_file_type') &&
                          ($mimeType === 'image/vnd.adobe.photoshop' || $extension === 'psd' || $extension === 'psb') &&
                          extension_loaded('imagick')) {
                    // PSD files are now supported via Imagick
                    $isNowSupported = true;
                } elseif ($skipReason === 'unsupported_format:cr2' &&
                          ($mimeType === 'image/x-canon-cr2' || $extension === 'cr2') &&
                          extension_loaded('imagick')) {
                    $isNowSupported = true;
                } elseif ($skipReason === 'unsupported_format:svg' &&
                          ($mimeType === 'image/svg+xml' || $extension === 'svg')) {
                    // SVG is now supported via passthrough (no GD/Imagick needed)
                    $isNowSupported = true;
                } elseif (in_array($skipReason, ['model_3d_thumbnail_pipeline_pending', 'dam_3d_preview_disabled'], true)
                    && $fileTypeService->supportsThumbnailPipelineForMimeAndExtension(
                        $mimeType !== '' ? $mimeType : null,
                        $extension !== '' ? $extension : null,
                    )) {
                    $isNowSupported = true;
                }

                if ($isNowSupported) {
                    // Clear the skip reason and reset status to allow regeneration
                    unset($metadata['thumbnail_skip_reason'], $metadata['thumbnail_skip_message']);
                    $asset->update([
                        'thumbnail_status' => ThumbnailStatus::PENDING,
                        'thumbnail_error' => null,
                        'metadata' => $metadata,
                    ]);

                    Log::info('[GenerateThumbnailsJob] Cleared old skip reason - format now supported', [
                        'asset_id' => $asset->id,
                        'old_skip_reason' => $skipReason,
                        'format' => $mimeType.'/'.$extension,
                    ]);
                }
            }

            // TASK 2: Update status to processing and record start time for timeout detection
            // CRITICAL: This sets PROCESSING - the catch block MUST set a terminal state if exception occurs
            $pipelineTimer?->lap('before_mark_processing', $asset, $version);

            $asset->update([
                'thumbnail_status' => ThumbnailStatus::PROCESSING,
                'thumbnail_error' => null,
                'thumbnail_started_at' => now(),
            ]);

            PipelineLogger::warning('THUMBNAILS: SET PROCESSING', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);

            // Log thumbnail generation started (non-blocking)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_STARTED,
                    [
                        'styles' => array_keys(config('assets.thumbnail_styles', [])),
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log thumbnail started event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Step 6: Generate all thumbnail styles atomically (includes preview + final)
            // Phase 3A: Version-aware path via generateThumbnailsForVersion (no model mutation)
            // Legacy: generateThumbnails($asset) uses asset.storage_root_path
            // Note: Thumbnail generation errors are caught by outer catch block
            // TASK 2: If this throws, catch block MUST set terminal state (FAILED)
            PipelineLogger::warning('THUMBNAILS: CALLING generateThumbnails', [
                'asset_id' => $asset->id,
            ]);
            $mode = ThumbnailMode::default();
            $pipelineTimer?->lap('before_thumbnail_service', $asset, $version);
            if (ThumbnailProfilingRecorder::enabled()) {
                ThumbnailProfilingRecorder::setJobContext([
                    'queue_wait_ms' => ThumbnailProfilingRecorder::resolveQueueWaitMs($this->job),
                    'worker_queue' => $this->queue ?? null,
                    'job_class' => static::class,
                    'queue_job_id' => $this->job?->getJobId(),
                ]);
            }
            $result = $version
            ? $thumbnailService->generateThumbnailsForVersion($version, $mode)
            : $thumbnailService->generateThumbnails($asset, null, null, null, $mode);
            $thumbnailGenResult = $result;
            $pipelineTimer?->lap('after_thumbnail_service', $asset, $version);

            // Service returns structured array keyed by mode: thumbnails[mode][style], etc.
            $previewThumbnails = $result['preview_thumbnails'][$mode] ?? [];
            $finalThumbnails = $result['thumbnails'][$mode] ?? [];
            $thumbnailDimensions = $result['thumbnail_dimensions'][$mode] ?? [];
            $imageWidth = $result['image_width'] ?? null;
            $imageHeight = $result['image_height'] ?? null;
            $detectedFileType = app(\App\Services\FileTypeService::class)->detectFileType(
                $version?->mime_type ?? $asset->mime_type,
                pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION)
            );
            $isPdf = $detectedFileType === 'pdf';
            $isOffice = $detectedFileType === 'office';
            $officePreviewPath = is_string($result['office_preview_pdf_path'] ?? null) ? $result['office_preview_pdf_path'] : null;
            if ($isPdf) {
                $pdfPageCount = max(1, (int) ($result['pdf_page_count'] ?? 1));
            } elseif ($isOffice && $officePreviewPath !== null && $officePreviewPath !== '') {
                $pdfPageCount = max(1, (int) ($result['pdf_page_count'] ?? 0));
                if ($pdfPageCount < 1) {
                    $pdfPageCount = null;
                }
            } else {
                $pdfPageCount = null;
            }

            // CRITICAL: If NO final thumbnails were generated, mark as FAILED immediately
            // This prevents marking as COMPLETED when all thumbnail generation failed
            // (e.g., PDF conversion failed, all styles failed, etc.)
            if (empty($finalThumbnails)) {
                $errorMessage = 'Thumbnail generation failed: No thumbnails were generated (all styles failed)';
                $enginePatch = $this->thumbnailEngineFailureMetadataPatch($thumbnailGenResult ?? []);
                $fullThumbnailError = $errorMessage;
                if (($enginePatch['thumbnail_engine_error_summary'] ?? '') !== '') {
                    $fullThumbnailError = $errorMessage."\n\n".$enginePatch['thumbnail_engine_error_summary'];
                }

                Log::warning('Thumbnail generation produced no final thumbnails (terminal — pipeline continues)', [
                    'asset_id' => $asset->id,
                    'preview_thumbnails' => count($previewThumbnails),
                    'final_thumbnails' => count($finalThumbnails),
                    'thumbnail_engine_diagnostics' => $enginePatch['thumbnail_engine_diagnostics'] ?? [],
                ]);

                // Mark as FAILED immediately - job failed, not transient issue
                // Clear thumbnail_started_at when failed (no longer needed)
                // Record failure: version gets metadata; asset gets status + merged engine diagnostics
                $assetMetaMerged = array_merge($asset->metadata ?? [], $enginePatch);
                $officeTerminalPreviewPatch = [];
                if ($isOffice && ($thumbnailGenResult['office_pdf_conversion_failed'] ?? false)) {
                    $officeTerminalPreviewPatch = [
                        'preview_skipped' => true,
                        'preview_skipped_reason' => 'office_pdf_conversion_failed',
                    ];
                    $snippet = (string) ($enginePatch['thumbnail_engine_error_summary'] ?? '');
                    if ($snippet !== '') {
                        $officeTerminalPreviewPatch['office_thumbnail_conversion_summary'] = \Illuminate\Support\Str::limit($snippet, 500);
                    }
                }
                if ($officeTerminalPreviewPatch !== []) {
                    $assetMetaMerged = array_merge($assetMetaMerged, $officeTerminalPreviewPatch);
                }
                if ($version) {
                    $version->update([
                        'metadata' => array_merge($version->metadata ?? [], [
                            'thumbnail_generation_failed' => true,
                            'thumbnail_generation_failed_at' => now()->toIso8601String(),
                            'thumbnail_generation_error' => $errorMessage,
                        ], $enginePatch, $officeTerminalPreviewPatch),
                        'pipeline_status' => 'complete',
                    ]);
                } else {
                    $assetMetaMerged['thumbnail_generation_failed'] = true;
                    $assetMetaMerged['thumbnail_generation_failed_at'] = now()->toIso8601String();
                    $assetMetaMerged['thumbnail_generation_error'] = $errorMessage;
                }

                $assetMetaMerged['thumbnail_error_technical'] = $fullThumbnailError;

                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::FAILED,
                    'thumbnail_error' => DerivativeFailureUserMessaging::persistedThumbnailError($errorMessage),
                    'thumbnail_started_at' => null,
                    'metadata' => $assetMetaMerged,
                ]);

                Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (no thumbnails generated)', [
                    'asset_id' => $asset->id,
                    'error' => $errorMessage,
                    'engine_summary' => $enginePatch['thumbnail_engine_error_summary'] ?? null,
                ]);

                // Log failure event (truthful - job failed)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                        [
                            'error' => DerivativeFailureUserMessaging::persistedThumbnailError($errorMessage),
                            'reason' => 'No thumbnails were generated - all styles failed',
                        ]
                    );
                } catch (\Exception $logException) {
                    Log::error('Failed to log thumbnail failed event', [
                        'asset_id' => $asset->id,
                        'error' => $logException->getMessage(),
                    ]);
                }

                $asset->refresh();
                $this->finalizeTerminalThumbnailFailureAndContinuePipeline($asset, $fullThumbnailError);
                $pipelineTimer?->lap('terminal_no_final_thumbnails', $asset, $version);

                return;
            }

            // CRITICAL: Verify FINAL thumbnail files exist AND are valid before marking as completed
            // Step 4: Job truth enforcement - never mark COMPLETED unless files are real and readable
            // Step 6: Preview thumbnails are EXCLUDED from verification - they don't mark COMPLETED
            //
            // Verification requirements (FINAL thumbnails only):
            // 1. File must exist in S3
            // 2. File size must be > minimum threshold (1KB) - prevents 1x1 pixel placeholders
            // 3. File must be readable (headObject succeeds)
            //
            // If ANY verification fails:
            // - Mark as FAILED (not PROCESSING) - job failed, not transient issue
            // - Persist actual error message
            // - Do NOT mark as COMPLETED
            // - Do NOT record "completed" event
            // - Return without throwing so Bus::chain continues (finalize still runs when version pipeline is complete)
            // - Preview thumbnails may still exist (non-fatal)
            $bucket = $asset->storageBucket;
            $s3Client = $this->createS3Client();
            $allThumbnailsValid = true;
            $verificationErrors = [];
            // Minimum size: 50 bytes - only catches truly broken/corrupted files
            // Small valid thumbnails (e.g., 710 bytes for compressed WebP) are acceptable
            $minValidSize = 50;

            // Step 6: Only verify FINAL thumbnails (exclude preview)
            foreach ($finalThumbnails as $styleName => $thumbnailData) {
                if ($styleName === 'preview') {
                    continue;
                }
                $thumbnailPath = $thumbnailData['path'] ?? null;
                if (! $thumbnailPath) {
                    $allThumbnailsValid = false;
                    $verificationErrors[] = "Thumbnail path missing for style '{$styleName}'";
                    Log::error('Thumbnail path missing in generated metadata', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                    ]);

                    continue;
                }

                // Verify thumbnail file exists in S3 and is valid.
                // Retry headObject on 404 (up to 2 retries, 2s delay) to handle S3/network eventual consistency after upload.
                $headResult = null;
                $headAttempts = 0;
                $headMaxAttempts = 3;
                while ($headAttempts < $headMaxAttempts) {
                    try {
                        $headResult = $s3Client->headObject([
                            'Bucket' => $bucket->name,
                            'Key' => $thumbnailPath,
                        ]);
                        break;
                    } catch (S3Exception $e) {
                        $headAttempts++;
                        if ($e->getStatusCode() === 404 && $headAttempts < $headMaxAttempts) {
                            Log::warning('Thumbnail headObject 404, retrying (S3 eventual consistency)', [
                                'asset_id' => $asset->id,
                                'style' => $styleName,
                                'attempt' => $headAttempts,
                                'thumbnail_path' => $thumbnailPath,
                            ]);
                            sleep(2);

                            continue;
                        }
                        if ($e->getStatusCode() === 404) {
                            $allThumbnailsValid = false;
                            $errorMsg = "Thumbnail file not found in S3 for style '{$styleName}'";
                            $verificationErrors[] = $errorMsg;
                            Log::error('Thumbnail file not found in S3 after generation', [
                                'asset_id' => $asset->id,
                                'style' => $styleName,
                                'thumbnail_path' => $thumbnailPath,
                                'bucket' => $bucket->name,
                            ]);
                            break;
                        }
                        throw $e;
                    }
                }

                if ($headResult !== null) {
                    // Verify file size > minimum threshold (only catch broken/corrupted files)
                    $contentLength = $headResult['ContentLength'] ?? 0;
                    if ($contentLength < $minValidSize) {
                        $allThumbnailsValid = false;
                        $errorMsg = "Thumbnail file too small for style '{$styleName}' (size: {$contentLength} bytes, minimum: {$minValidSize} bytes)";
                        $verificationErrors[] = $errorMsg;
                        Log::error('Thumbnail file too small (likely corrupted or empty)', [
                            'asset_id' => $asset->id,
                            'style' => $styleName,
                            'thumbnail_path' => $thumbnailPath,
                            'bucket' => $bucket->name,
                            'content_length' => $contentLength,
                            'expected_min' => $minValidSize,
                        ]);
                    }
                }
            }

            $pipelineTimer?->lap('s3_head_verification_done', $asset, $version, [
                'final_thumb_styles' => array_keys($finalThumbnails),
            ]);

            // CRITICAL: Only mark as COMPLETED if ALL thumbnails are valid
            // Step 4: Job truth enforcement - never mark COMPLETED unless files are real and readable
            //
            // If verification fails:
            // - Mark as FAILED immediately (not PROCESSING) - job failed, not transient
            // - Persist actual error message with details
            // - Do NOT mark as COMPLETED
            // - Do NOT record "completed" event
            // - Return without throwing so chain continues; see finalizeTerminalThumbnailFailureAndContinuePipeline
            if (! $allThumbnailsValid) {
                $errorMessage = 'Thumbnail generation failed: '.implode('; ', $verificationErrors);
                $enginePatch = $this->thumbnailEngineFailureMetadataPatch($thumbnailGenResult ?? []);
                $fullThumbnailError = $errorMessage;
                if (($enginePatch['thumbnail_engine_error_summary'] ?? '') !== '') {
                    $fullThumbnailError = $errorMessage."\n\n".$enginePatch['thumbnail_engine_error_summary'];
                }

                Log::warning('Thumbnail verification failed (terminal — pipeline continues)', [
                    'asset_id' => $asset->id,
                    'thumbnail_count' => count($finalThumbnails),
                    'errors' => $verificationErrors,
                    'thumbnail_engine_diagnostics' => $enginePatch['thumbnail_engine_diagnostics'] ?? [],
                ]);

                // Record failure: version gets metadata; asset gets status + merged engine diagnostics
                $assetMetaMerged = array_merge($asset->metadata ?? [], $enginePatch);
                if ($version) {
                    $version->update([
                        'metadata' => array_merge($version->metadata ?? [], [
                            'thumbnail_generation_failed' => true,
                            'thumbnail_generation_failed_at' => now()->toIso8601String(),
                            'thumbnail_generation_error' => $errorMessage,
                        ], $enginePatch),
                        'pipeline_status' => 'complete',
                    ]);
                } else {
                    $assetMetaMerged['thumbnail_generation_failed'] = true;
                    $assetMetaMerged['thumbnail_generation_failed_at'] = now()->toIso8601String();
                    $assetMetaMerged['thumbnail_generation_error'] = $errorMessage;
                }

                $assetMetaMerged['thumbnail_error_technical'] = $fullThumbnailError;

                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::FAILED,
                    'thumbnail_error' => DerivativeFailureUserMessaging::persistedThumbnailError($errorMessage),
                    'thumbnail_started_at' => null,
                    'metadata' => $assetMetaMerged,
                ]);

                Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (verification failed)', [
                    'asset_id' => $asset->id,
                    'error' => $errorMessage,
                    'engine_summary' => $enginePatch['thumbnail_engine_error_summary'] ?? null,
                ]);

                // Log failure event (truthful - job failed)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                        [
                            'error' => DerivativeFailureUserMessaging::persistedThumbnailError($errorMessage),
                            'verification_errors' => $verificationErrors,
                        ]
                    );
                } catch (\Exception $logException) {
                    Log::error('Failed to log thumbnail failed event', [
                        'asset_id' => $asset->id,
                        'error' => $logException->getMessage(),
                    ]);
                }

                $asset->refresh();
                $this->finalizeTerminalThumbnailFailureAndContinuePipeline($asset, $fullThumbnailError);
                $pipelineTimer?->lap('terminal_verification_failed', $asset, $version);

                return;
            }

            // Step 6: Persist metadata - version only when version exists; asset for legacy
            $modeBucket = $finalThumbnails;
            if (! empty($previewThumbnails['preview'])) {
                $modeBucket['preview'] = $previewThumbnails['preview'];
            }

            $metaBaseForMerge = $version ? ($version->metadata ?? []) : ($asset->metadata ?? []);

            $currentThumbMeta = $metaBaseForMerge['thumbnails'] ?? [];
            if (! is_array($currentThumbMeta)) {
                $currentThumbMeta = [];
            }
            $currentThumbMeta[$mode] = $modeBucket;

            $currentPreviewMeta = $metaBaseForMerge['preview_thumbnails'] ?? [];
            if (! is_array($currentPreviewMeta)) {
                $currentPreviewMeta = [];
            }
            if (! empty($previewThumbnails)) {
                $currentPreviewMeta[$mode] = $previewThumbnails;
            }

            $currentDimMeta = $metaBaseForMerge['thumbnail_dimensions'] ?? [];
            if (! is_array($currentDimMeta)) {
                $currentDimMeta = [];
            }
            $currentDimMeta[$mode] = $thumbnailDimensions;

            $thumbnailMetadata = [
                'thumbnails' => $currentThumbMeta,
                'preview_thumbnails' => $currentPreviewMeta,
                'thumbnail_dimensions' => $currentDimMeta,
                'image_width' => $imageWidth,
                'image_height' => $imageHeight,
                'thumbnails_generated' => true,
                'thumbnails_generated_at' => now()->toIso8601String(),
                'thumbnail_timeout' => false,
                'thumbnail_timeout_reason' => null,
            ];
            if (! empty($result['thumbnail_quality'])) {
                $thumbnailMetadata['thumbnail_quality'] = $result['thumbnail_quality'];
            }
            $hasPdfStylePaging = ($isPdf || ($isOffice && $officePreviewPath)) && $pdfPageCount !== null;
            if ($hasPdfStylePaging) {
                $thumbnailMetadata['pdf_page_count'] = $pdfPageCount;
                $thumbnailMetadata['pdf_pages_rendered'] = $pdfPageCount <= 1;
            }
            if ($isOffice) {
                $prevOffice = is_array($metaBaseForMerge['office'] ?? null) ? $metaBaseForMerge['office'] : [];
                if ($officePreviewPath) {
                    $thumbnailMetadata['office'] = array_merge($prevOffice, [
                        'preview_pdf_path' => $officePreviewPath,
                        'preview_pdf_generated_at' => now()->toIso8601String(),
                    ]);
                } else {
                    unset($prevOffice['preview_pdf_path'], $prevOffice['preview_pdf_generated_at']);
                    if ($prevOffice !== []) {
                        $thumbnailMetadata['office'] = $prevOffice;
                    }
                }
            }

            if ($detectedFileType !== null
                && in_array($detectedFileType, ['model_glb', 'model_stl', 'model_obj', 'model_fbx', 'model_blend'], true)
                && (bool) config('dam_3d.enabled')) {
                $posterPath = $finalThumbnails['medium']['path'] ?? $finalThumbnails['large']['path'] ?? null;
                $thumbP = $finalThumbnails['thumb']['path'] ?? null;
                if (is_string($posterPath) && $posterPath !== '' && is_string($thumbP) && $thumbP !== '') {
                    $prevDbg = is_array(($metaBaseForMerge['preview_3d']['debug'] ?? null))
                        ? $metaBaseForMerge['preview_3d']['debug']
                        : [];
                    $m3 = is_array(($thumbnailGenResult ?? [])['model_3d_preview'] ?? null)
                        ? ($thumbnailGenResult['model_3d_preview'] ?? [])
                        : [];
                    $posterStub = (bool) ($m3['poster_stub'] ?? false);
                    $blenderUsed = (bool) ($m3['blender_used'] ?? false);
                    $blenderVersion = isset($m3['blender_version']) && is_string($m3['blender_version']) ? $m3['blender_version'] : null;
                    $convertedViewerKey = isset($m3['viewer_storage_key']) && is_string($m3['viewer_storage_key']) && trim($m3['viewer_storage_key']) !== ''
                        ? trim($m3['viewer_storage_key'])
                        : null;

                    $existingNativeGlbViewer = $detectedFileType === 'model_glb'
                        ? ($metaBaseForMerge['preview_3d']['viewer_path'] ?? null)
                        : null;
                    $preservedNative = is_string($existingNativeGlbViewer) && trim($existingNativeGlbViewer) !== ''
                        ? trim($existingNativeGlbViewer)
                        : null;

                    $invalidGlb = $detectedFileType === 'model_glb' && (bool) ($m3['invalid_glb_source'] ?? false);

                    $mergedViewer = $invalidGlb
                        ? null
                        : ($convertedViewerKey !== null && $convertedViewerKey !== ''
                        ? $convertedViewerKey
                        : ($detectedFileType === 'model_glb' ? $preservedNative : null));

                    $dbg = array_merge($prevDbg, [
                        'poster_generated_at' => now()->toIso8601String(),
                        'poster_stub' => $posterStub,
                        'blender_used' => $blenderUsed,
                    ]);
                    if (! empty($m3['blender_render_debug']) && is_array($m3['blender_render_debug'])) {
                        $dbg['blender_render_debug'] = $m3['blender_render_debug'];
                    }
                    if ($blenderVersion !== null && $blenderVersion !== '') {
                        $dbg['blender_version'] = $blenderVersion;
                    }
                    if (array_key_exists('render_seconds', $m3)) {
                        $dbg['render_seconds'] = $m3['render_seconds'];
                    }
                    if (array_key_exists('conversion_seconds', $m3)) {
                        $dbg['conversion_seconds'] = $m3['conversion_seconds'];
                    }

                    $failure = null;
                    if (! empty($m3['failure_message']) && is_string($m3['failure_message'])) {
                        $failure = \Illuminate\Support\Str::limit(trim($m3['failure_message']), 500);
                    }

                    $thumbnailMetadata['preview_3d'] = Preview3dMetadata::merge(
                        $metaBaseForMerge['preview_3d'] ?? [],
                        [
                            'status' => $invalidGlb ? Preview3dMetadata::STATUS_SKIPPED : Preview3dMetadata::STATUS_READY,
                            'poster_path' => $posterPath,
                            'thumbnail_path' => $thumbP,
                            'viewer_path' => $mergedViewer,
                            'skip_reason' => $invalidGlb ? 'invalid_glb_source' : null,
                            'failure_message' => $failure,
                            'disable_realtime_viewer' => $invalidGlb,
                            'debug' => $dbg,
                        ]
                    );
                    if ($invalidGlb) {
                        Log::warning('preview_3d.invalid_glb_source', [
                            'event' => 'preview_3d.invalid_glb_source',
                            'asset_id' => $asset->id,
                            'tenant_id' => $asset->tenant_id,
                            'registry_type' => $detectedFileType,
                        ]);
                    } else {
                        Log::info('preview_3d.poster_generated', [
                            'event' => 'preview_3d.poster_generated',
                            'asset_id' => $asset->id,
                            'tenant_id' => $asset->tenant_id,
                            'registry_type' => $detectedFileType,
                            'poster_stub' => $posterStub,
                            'blender_used' => $blenderUsed,
                        ]);
                    }
                }
            }

            if (! empty($result['_thumbnail_profiling']) && config('assets.thumbnail_profiling.store_in_version_metadata', true)) {
                $thumbnailMetadata['thumbnail_profiling'] = $result['_thumbnail_profiling'];
            }

            if ($version) {
                // Version path: persist metadata onto version
                $mergedVersionMeta = array_merge($version->metadata ?? [], $thumbnailMetadata);
                $mergedVersionMeta = $this->stripStaleThumbnailFailureMetadataAfterSuccess($mergedVersionMeta);
                $version->update([
                    'metadata' => $mergedVersionMeta,
                    'pipeline_status' => 'complete',
                ]);
                // CRITICAL: Also sync thumbnail metadata to asset so thumbnailPathForStyle, batch endpoint, and UI work.
                // Asset is the display entity; version stores source. Thumbnails must be readable from asset.
                $currentMetadata = $asset->metadata ?? [];
                $currentMetadata = array_merge($currentMetadata, $thumbnailMetadata);
                $currentMetadata = $this->stripStaleThumbnailFailureMetadataAfterSuccess($currentMetadata);
                $asset->update(['metadata' => $currentMetadata]);
            } else {
                // Legacy path: persist to asset metadata
                $currentMetadata = $asset->metadata ?? [];
                $currentMetadata = array_merge($currentMetadata, $thumbnailMetadata);
                $currentMetadata = $this->stripStaleThumbnailFailureMetadataAfterSuccess($currentMetadata);
            }

            // Asset pipeline state (thumbnail_status, analysis_status) - always updated for pipeline progression
            $updateData = [
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
                'thumbnail_error' => null,
                'thumbnail_ready_duration_ms' => ProcessingMetrics::thumbnailReadyDurationMs($asset),
                'thumbnail_started_at' => null,
            ];
            if ($hasPdfStylePaging) {
                $updateData['pdf_page_count'] = $pdfPageCount;
                $updateData['pdf_pages_rendered'] = $pdfPageCount <= 1;
            }
            if (! $version) {
                $updateData['metadata'] = $currentMetadata ?? $asset->metadata;
            }

            $expectedStatus = 'generating_thumbnails';
            $currentStatus = $asset->analysis_status ?? 'uploading';
            if ($currentStatus === $expectedStatus) {
                $updateData['analysis_status'] = 'extracting_metadata';
            } else {
                Log::warning('[GenerateThumbnailsJob] Skipping analysis_status transition', [
                    'asset_id' => $asset->id,
                    'expected' => $expectedStatus,
                    'actual' => $currentStatus,
                ]);
            }

            $fileTypeService = app(\App\Services\FileTypeService::class);
            $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
            if ($fileType === 'video' && isset($finalThumbnails['thumb'])) {
                $posterPath = $finalThumbnails['thumb']['path'] ?? null;
                if ($posterPath) {
                    $updateData['video_poster_url'] = $posterPath;
                }
            }

            $asset->update($updateData);
            if (isset($updateData['analysis_status'])) {
                \App\Services\AnalysisStatusLogger::log($asset, 'generating_thumbnails', 'extracting_metadata', 'GenerateThumbnailsJob');
            }

            $asset->refresh();
            if ($version) {
                $version->refresh();
            }

            if ($hasPdfStylePaging) {
                $this->syncFirstPdfPageRecord($asset, $version, $finalThumbnails, $pdfPageCount);
            }

            if ($isPdf && $pdfPageCount !== null && $this->shouldScheduleFullPdfExtraction($asset, $pdfPageCount)) {
                FullPdfExtractionJob::dispatch($asset->id, $version?->id);
            }

            // Verify metadata was saved correctly (defensive check)
            $savedMetadata = $version ? ($version->metadata ?? []) : ($asset->metadata ?? []);
            $savedModeThumbnails = $savedMetadata['thumbnails'][$mode] ?? [];
            $thumbPath = $asset->thumbnailPathForStyle('thumb');

            Log::info('[GenerateThumbnailsJob] Marked asset as COMPLETED', [
                'asset_id' => $asset->id,
                'thumbnail_count' => count($finalThumbnails),
                'saved_thumbnail_styles' => array_keys(is_array($savedModeThumbnails) ? $savedModeThumbnails : []),
                'thumb_path_exists' => $thumbPath !== null,
                'thumb_path' => $thumbPath,
            ]);
            \App\Services\UploadDiagnosticLogger::jobComplete('GenerateThumbnailsJob', $asset->id, [
                'thumbnail_styles' => array_keys($finalThumbnails),
                'thumbnail_quality' => $result['thumbnail_quality'] ?? null,
            ]);

            // If metadata wasn't saved correctly, log warning (but don't fail - status is already set)
            if (! ThumbnailMetadata::hasThumb($savedMetadata) || ! $thumbPath) {
                Log::warning('[GenerateThumbnailsJob] Thumbnail metadata may not have saved correctly', [
                    'asset_id' => $asset->id,
                    'expected_thumbnails' => array_keys($finalThumbnails),
                    'saved_thumbnails' => array_keys(is_array($savedModeThumbnails) ? $savedModeThumbnails : []),
                    'metadata_structure' => $savedMetadata,
                ]);
            }

            // Emit thumbnails generated event
            AssetEvent::create([
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'asset_id' => $asset->id,
                'user_id' => null,
                'event_type' => 'asset.thumbnails.generated',
                'metadata' => [
                    'job' => 'GenerateThumbnailsJob',
                    'thumbnail_count' => count($finalThumbnails),
                    'styles' => array_keys($finalThumbnails),
                ],
                'created_at' => now(),
            ]);

            // TODO: Preferred thumbnails temporarily off by default (see config assets.thumbnail.preferred.enabled).
            // Re-enable for an environment with: THUMBNAIL_PREFERRED_ENABLED=true
            if ($version && config('assets.thumbnail.preferred.enabled', false)) {
                GeneratePreferredThumbnailJob::markPreferredProcessing($asset, $version);
                GeneratePreferredThumbnailJob::dispatch($asset->id, $version->id, false)
                    ->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));
            }

            // Log thumbnail generation completed (non-blocking)
            // Track which final styles were generated (exclude preview)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_COMPLETED,
                    [
                        'styles' => array_keys($finalThumbnails), // Only final styles (exclude preview)
                        'preview_styles' => array_keys($previewThumbnails), // Preview styles separately
                        'thumbnail_count' => count($finalThumbnails),
                        'preview_count' => count($previewThumbnails),
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log thumbnail completed event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('[GenerateThumbnailsJob] Thumbnails generated successfully', [
                'asset_id' => $asset->id,
                'thumbnail_count' => count($finalThumbnails),
                'styles' => array_keys($finalThumbnails),
            ]);

            Log::info('[GenerateThumbnailsJob] Job completed successfully', [
                'asset_id' => $asset->id,
                'job_id' => $this->job?->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
            ]);
            $pipelineTimer?->lap('handle_success', $asset->fresh(), $version?->fresh(), [
                'queue_job_id' => $this->job?->getJobId() ?? 'unknown',
            ]);

            \App\Support\Logging\AssetPipelineTimingLogger::record(
                \App\Support\Logging\AssetPipelineTimingLogger::EVENT_THUMBNAIL_COMPLETED,
                $asset->fresh(),
                $version?->fresh(),
                [
                    'thumbnail_status' => $asset->thumbnail_status?->value ?? null,
                    'attempt' => $this->attempts(),
                ]
            );

            // TASK 2: Terminal state guarantee - COMPLETED
            // Asset is already updated to COMPLETED above (line 466)
            PipelineLogger::warning('THUMBNAILS: COMPLETED', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);

        } catch (\Throwable $e) {
            try {
                $thumbnailService->abandonProfilingAfterJobFailure();
            } catch (\Throwable) {
            }
            // TASK 2: Terminal state guarantee - FAILED
            // This catch block MUST set a terminal state (FAILED) to prevent PROCESSING forever
            // The catch block below will update thumbnail_status to FAILED
            PipelineLogger::error('THUMBNAILS: FAILED', [
                'asset_version_id' => $this->assetVersionId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'attempt' => $this->attempts(),
            ]);
            Log::channel('admin_worker')->error('Thumbnail generation failed', [
                'asset_version_id' => $this->assetVersionId,
                'job' => self::class,
                'attempt' => $this->attempts(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            AdminLogStream::push('worker', [
                'level' => 'error',
                'message' => 'Thumbnail generation failed',
                'asset_version_id' => $this->assetVersionId,
                'job' => self::class,
                'attempt' => $this->attempts(),
            ]);

            Log::error('[GenerateThumbnailsJob] Job failed with exception', [
                'asset_version_id' => $this->assetVersionId,
                'job_id' => $this->job?->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            // Step 4: Capture ALL errors and persist with actual error messages
            // This includes:
            // - ThumbnailGenerationService exceptions (TIFF conversion errors, etc.)
            // - S3 errors (upload/download failures)
            // - Verification failures (missing files, invalid size)
            // - Any other unexpected errors

            $errorMessage = $e->getMessage();

            // Include previous exception message if available (for nested errors)
            $previous = $e->getPrevious();
            if ($previous) {
                $errorMessage .= ' (Previous: '.$previous->getMessage().')';
            }

            // Include exception class name for better debugging (for logs only)
            $fullErrorMessage = get_class($e).': '.$errorMessage;

            // TASK 2: Terminal state guarantee - ensure asset is loaded
            // $asset may not be defined if exception occurred before findOrFail
            // Phase 3A: Resolve asset from version or legacy
            if (! isset($asset)) {
                $v = AssetVersion::find($this->assetVersionId);
                $asset = $v ? $v->asset : Asset::find($this->assetVersionId);
            }

            if (! isset($version)) {
                $version = AssetVersion::find($this->assetVersionId);
            }

            // OOM / ImageMagick resource limits: retrying the same job on the same hardware will not help.
            // Mark SKIPPED, complete the job successfully so Bus::chain continues (no 32x retry / Sentry spam).
            if (
                $asset
                && (bool) config('assets.thumbnail.resource_exhaustion_terminal', true)
                && $this->isResourceExhaustionException($e)
            ) {
                $this->applyResourceExhaustionSkip($asset, $version, $e);
                $asset->refresh();
                $this->finalizeTerminalThumbnailFailureAndContinuePipeline($asset, $e->getMessage());
                \App\Services\UploadDiagnosticLogger::jobFail('GenerateThumbnailsJob', $asset->id, $e->getMessage(), [
                    'server_resource_limit' => true,
                    'terminal_no_retry' => true,
                ]);
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_SKIPPED,
                        [
                            'reason' => 'server_resource_limit',
                            'message' => $e->getMessage(),
                        ]
                    );
                } catch (\Throwable $logEx) {
                    Log::warning('[GenerateThumbnailsJob] Activity log failed (resource skip)', ['error' => $logEx->getMessage()]);
                }
                Log::warning('[GenerateThumbnailsJob] Terminal skip — server resource limit (no retry)', [
                    'asset_id' => $asset->id,
                    'exception' => get_class($e),
                ]);

                return;
            }

            if ($asset) {
                Log::error('[GenerateThumbnailsJob] Thumbnail generation failed', [
                    'asset_id' => $asset->id,
                    'error' => $fullErrorMessage,
                    'exception_class' => get_class($e),
                    'attempt' => $this->attempts(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Sanitize error message for user display (remove technical details)
                $userFriendlyError = $this->sanitizeErrorMessage($errorMessage);

                // P2: Never leave failed if thumbnails exist — overwrite with completed.
                // Forced regeneration replaces existing files; treat failures as real failures even when old paths remain.
                $versionMeta = ($version instanceof AssetVersion) ? ($version->metadata ?? []) : [];
                $assetMeta = $asset->metadata ?? [];
                $hasExistingThumbnails = ThumbnailMetadata::hasThumb($versionMeta) || ThumbnailMetadata::hasThumb($assetMeta);
                $hasThumbnails = ! $this->force && $hasExistingThumbnails;

                // TASK 2: Terminal state guarantee - ALWAYS set FAILED in catch block (unless thumbnails exist)
                // This prevents assets from remaining in PROCESSING forever
                // CRITICAL: Use direct property assignment + save() to ensure commit
                // Even if we re-throw for retry, we set FAILED now as a safety guard
                // If the job retries and succeeds, it will set COMPLETED (overriding FAILED)
                // If it retries and fails again, at least we have a terminal state
                $asset->thumbnail_status = $hasThumbnails ? ThumbnailStatus::COMPLETED : ThumbnailStatus::FAILED;
                $asset->thumbnail_error = $hasThumbnails ? null : $userFriendlyError;
                if ($hasThumbnails) {
                    $asset->thumbnail_ready_duration_ms = ProcessingMetrics::thumbnailReadyDurationMs($asset);
                }
                $asset->thumbnail_started_at = null;
                $asset->save(); // Explicit save to ensure commit before re-throw

                // TASK 2: Verify terminal state was set (defensive check)
                $asset->refresh();
                if ($asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
                    // This should never happen, but if it does, force terminal state with direct DB update
                    Log::error('[GenerateThumbnailsJob] CRITICAL: Asset still in PROCESSING after save - forcing FAILED via direct DB', [
                        'asset_id' => $asset->id,
                    ]);
                    DB::table('assets')
                        ->where('id', $asset->id)
                        ->update([
                            'thumbnail_status' => ThumbnailStatus::FAILED->value,
                            'thumbnail_error' => 'Thumbnail generation failed (forced terminal state)',
                            'thumbnail_started_at' => null,
                        ]);
                }

                Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (exception)', [
                    'asset_id' => $asset->id,
                    'error' => $fullErrorMessage,
                    'user_friendly_error' => $userFriendlyError,
                    'exception_class' => get_class($e),
                ]);
                \App\Services\UploadDiagnosticLogger::jobFail('GenerateThumbnailsJob', $asset->id, $userFriendlyError, [
                    'exception_class' => get_class($e),
                ]);

                // Log thumbnail generation failed event (truthful - job failed)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                        [
                            'error' => $errorMessage,
                            'exception_class' => get_class($e),
                            'attempt' => $this->attempts(),
                        ]
                    );
                } catch (\Exception $logException) {
                    // Activity logging must never break processing
                    Log::error('Failed to log thumbnail failed event', [
                        'asset_id' => $asset->id,
                        'error' => $logException->getMessage(),
                    ]);
                }

                // Update metadata to record failure
                $currentMetadata = $asset->metadata ?? [];
                $currentMetadata['thumbnail_generation_failed'] = true;
                $currentMetadata['thumbnail_generation_failed_at'] = now()->toIso8601String();
                $currentMetadata['thumbnail_generation_error'] = $errorMessage;
                $currentMetadata = array_merge(
                    $currentMetadata,
                    $this->thumbnailEngineFailureMetadataPatch($thumbnailGenResult ?? [])
                );

                // CRITICAL: Detect DEAD asset — source file missing from storage (NoSuchKey)
                // This is the most severe state: asset cannot be recovered without re-upload
                $isStorageMissing = $e instanceof S3Exception && $e->getAwsErrorCode() === 'NoSuchKey'
                    || str_contains($errorMessage, 'NoSuchKey')
                    || str_contains($errorMessage, '404')
                    || str_contains(strtolower($errorMessage), 'specified key does not exist');
                if ($isStorageMissing) {
                    $currentMetadata['storage_missing'] = true;
                    $currentMetadata['storage_missing_detected_at'] = now()->toIso8601String();
                    Log::critical('[GenerateThumbnailsJob] DEAD ASSET — source file missing from storage', [
                        'asset_id' => $asset->id,
                        'storage_root_path' => $asset->storage_root_path,
                    ]);
                }
                $asset->update(['metadata' => $currentMetadata]);

                // Phase T-1: Record derivative failure for observability (never affects Asset.status)
                // firstOrCreate(asset_id, derivative_type) prevents double-record on retries
                try {
                    $mime = $asset->metadata['mime_type'] ?? $asset->mime_type ?? null;
                    app(AssetDerivativeFailureService::class)->recordFailure(
                        $asset,
                        DerivativeType::THUMBNAIL,
                        AssetDerivativeFailureService::inferProcessorFromException($e),
                        $e,
                        null,
                        null,
                        $mime
                    );
                } catch (\Throwable $t1Ex) {
                    Log::warning('[GenerateThumbnailsJob] AssetDerivativeFailureService recording failed', [
                        'asset_id' => $asset->id,
                        'error' => $t1Ex->getMessage(),
                    ]);
                }

                // Unified Operations: Record system incident for visibility
                try {
                    $reportPayload = [
                        'source_type' => 'asset',
                        'source_id' => $asset->id,
                        'tenant_id' => $asset->tenant_id,
                        'message' => $e->getMessage(),
                        'metadata' => [
                            'exception_class' => get_class($e),
                            'exception_message' => $e->getMessage(),
                            'attempts' => $this->attempts(),
                            'derivative_failure' => true,
                        ],
                    ];
                    if ($isStorageMissing ?? false) {
                        // DEAD asset — highest severity, requires immediate attention
                        $reportPayload['severity'] = 'critical';
                        $reportPayload['title'] = 'Source file missing (DEAD asset)';
                        $reportPayload['requires_support'] = true;
                        $reportPayload['retryable'] = false;
                        $reportPayload['unique_signature'] = "storage_missing:{$asset->id}";
                        $reportPayload['metadata']['storage_missing'] = true;
                        $reportPayload['metadata']['storage_root_path'] = $asset->storage_root_path;
                    } else {
                        $reportPayload['severity'] = 'error';
                        $reportPayload['title'] = 'Thumbnail generation failed';
                        $reportPayload['retryable'] = true;
                        $reportPayload['unique_signature'] = "thumbnail_generation_failed:{$asset->id}";
                    }
                    app(ReliabilityEngine::class)->report($reportPayload);
                } catch (\Throwable $t2Ex) {
                    Log::warning('[GenerateThumbnailsJob] ReliabilityEngine recording failed', [
                        'asset_id' => $asset->id,
                        'error' => $t2Ex->getMessage(),
                    ]);
                }
            } else {
                // Asset not found - log error but can't update
                // TASK 2: Even if asset not found, we've logged the failure
                // The failed() method will be called after retries exhausted
                Log::error('[GenerateThumbnailsJob] Thumbnail generation failed - asset not found', [
                    'asset_version_id' => $this->assetVersionId,
                    'error' => $errorMessage,
                    'exception_class' => get_class($e),
                    'attempt' => $this->attempts(),
                ]);
                // Unified Operations: Record incident even when asset not found
                try {
                    app(ReliabilityEngine::class)->report([
                        'source_type' => 'job',
                        'source_id' => $this->assetVersionId,
                        'tenant_id' => null,
                        'severity' => 'error',
                        'title' => 'Thumbnail generation failed',
                        'message' => $errorMessage,
                        'retryable' => true,
                        'unique_signature' => 'thumbnail_gen_job_failed:'.$this->assetVersionId,
                        'metadata' => [
                            'exception_class' => get_class($e),
                            'attempts' => $this->attempts(),
                            'asset_not_found' => true,
                        ],
                    ]);
                } catch (\Throwable $t2Ex) {
                    Log::warning('[GenerateThumbnailsJob] ReliabilityEngine recording failed (asset not found)', [
                        'asset_version_id' => $this->assetVersionId,
                        'error' => $t2Ex->getMessage(),
                    ]);
                }
            }

            // TASK 2: Terminal state guarantee
            // If asset exists and is in PROCESSING, we MUST set a terminal state
            // Even if we re-throw for retry, the catch block above should have set FAILED
            // The failed() method (called after retries) will also set FAILED as final safety

            // TASK 2: Final safety check - ensure terminal state is set before re-throwing
            // Even if we're going to retry, we MUST have a terminal state now
            if (isset($asset) && $asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
                // This should never happen, but if it does, force terminal state with direct DB update
                PipelineLogger::error('THUMBNAILS: CRITICAL - Still PROCESSING after catch block - forcing FAILED via DB', [
                    'asset_id' => $asset->id,
                ]);
                DB::table('assets')
                    ->where('id', $asset->id)
                    ->update([
                        'thumbnail_status' => ThumbnailStatus::FAILED->value,
                        'thumbnail_error' => 'Thumbnail generation failed (forced terminal state in catch block)',
                        'thumbnail_started_at' => null,
                    ]);
            }

            // All styles produced no output: retries will not help; rethrow would spam Sentry and the queue.
            // Complete the job successfully so Bus::chain continues (same intent as the in-process empty-thumbnails branch).
            if (isset($asset) && $this->isTerminalNoThumbnailsAllStylesFailed($e)) {
                $asset->refresh();
                $expectedStatus = 'generating_thumbnails';
                $currentStatus = $asset->analysis_status ?? 'uploading';
                if ($currentStatus === $expectedStatus) {
                    $asset->update(['analysis_status' => 'extracting_metadata']);
                    $asset->refresh();
                    \App\Services\AnalysisStatusLogger::log($asset, 'generating_thumbnails', 'extracting_metadata', 'GenerateThumbnailsJob');
                }
                Log::warning('[GenerateThumbnailsJob] Terminal no-thumbnails failure — job completed without rethrow', [
                    'asset_id' => $asset->id,
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            // Fail immediately for non-retryable exceptions (asset deleted, 4xx client errors)
            // Prevents wasted retries and MaxAttemptsExceededException spam
            if ($this->isNonRetryableException($e)) {
                Log::info('[GenerateThumbnailsJob] Failing immediately (non-retryable)', [
                    'asset_version_id' => $this->assetVersionId,
                    'exception_class' => get_class($e),
                ]);
                $this->fail($e);

                return;
            }

            // Re-throw to trigger job retry mechanism
            // After all retries exhausted, failed() method will be called
            throw $e;
        }

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Build metadata keys from {@see ThumbnailGenerationService} payload so admin UIs can show
     * root causes (for example LibreOffice conversion errors) instead of only generic summaries.
     *
     * @param  array<string, mixed>  $serviceResult
     * @return array<string, mixed>
     */
    private function thumbnailEngineFailureMetadataPatch(array $serviceResult): array
    {
        $diag = $serviceResult['thumbnail_engine_diagnostics'] ?? null;
        if (! is_array($diag) || $diag === []) {
            return [];
        }

        $normalized = \App\Support\ThumbnailEngineDiagnostics::normalizeForMetadata($diag);
        if ($normalized['rows'] === [] || $normalized['summary'] === '') {
            return [];
        }

        return [
            'thumbnail_engine_diagnostics' => $normalized['rows'],
            'thumbnail_engine_error_summary' => $normalized['summary'],
        ];
    }

    /**
     * True when generation failed with no output (terminal). Retrying the job will not help.
     */
    private function isTerminalNoThumbnailsAllStylesFailed(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'No thumbnails were generated (all styles failed)');
    }

    /**
     * After a handled in-process thumbnail failure (no output or S3 verification failed).
     * Do not throw: the job completes successfully so Bus::chain continues; asset stays thumbnail FAILED.
     * Avoids queue retries and Sentry noise for sources that will not improve on retry.
     */
    private function finalizeTerminalThumbnailFailureAndContinuePipeline(Asset $asset, string $technicalMessage): void
    {
        $expectedStatus = 'generating_thumbnails';
        $currentStatus = $asset->analysis_status ?? 'uploading';
        if ($currentStatus === $expectedStatus) {
            $asset->update(['analysis_status' => 'extracting_metadata']);
            $asset->refresh();
            \App\Services\AnalysisStatusLogger::log($asset, 'generating_thumbnails', 'extracting_metadata', 'GenerateThumbnailsJob');
        }

        try {
            $mime = $asset->metadata['mime_type'] ?? $asset->mime_type ?? null;
            // Use base Exception so observability does not treat this as an unhandled RuntimeException fingerprint.
            app(AssetDerivativeFailureService::class)->recordFailure(
                $asset,
                DerivativeType::THUMBNAIL,
                DerivativeProcessor::THUMBNAIL_GENERATOR,
                new \Exception($technicalMessage),
                null,
                null,
                $mime
            );
        } catch (\Throwable $t) {
            Log::warning('[GenerateThumbnailsJob] AssetDerivativeFailureService recording failed (terminal path)', [
                'asset_id' => $asset->id,
                'error' => $t->getMessage(),
            ]);
        }

        try {
            app(ReliabilityEngine::class)->report([
                'source_type' => 'asset',
                'source_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'severity' => 'warning',
                'title' => 'Thumbnail preview not generated (terminal)',
                'message' => $technicalMessage,
                'retryable' => false,
                'unique_signature' => "thumbnail_terminal_no_preview:{$asset->id}",
                'metadata' => [
                    'derivative_failure' => true,
                    'terminal_no_retry' => true,
                ],
            ]);
        } catch (\Throwable $t) {
            Log::warning('[GenerateThumbnailsJob] ReliabilityEngine report failed (terminal path)', [
                'asset_id' => $asset->id,
                'error' => $t->getMessage(),
            ]);
        }

        \App\Services\UploadDiagnosticLogger::jobFail('GenerateThumbnailsJob', $asset->id, $technicalMessage, [
            'terminal_thumbnail_failure' => true,
        ]);

        Log::warning('[GenerateThumbnailsJob] Terminal thumbnail failure — queue success, pipeline continues', [
            'asset_id' => $asset->id,
            'message' => $technicalMessage,
        ]);

        PipelineLogger::warning('THUMBNAILS: TERMINAL HANDLED (NO EXCEPTION)', [
            'asset_id' => $asset->id,
            'message' => $technicalMessage,
        ]);
    }

    /**
     * Whether the exception is non-retryable (retrying would never succeed).
     * These jobs should fail immediately to avoid MaxAttemptsExceededException spam.
     */
    protected function isNonRetryableException(\Throwable $e): bool
    {
        if ($e instanceof ModelNotFoundException) {
            return true; // Asset was deleted
        }
        if ($e instanceof ClientException) {
            return true; // 4xx client errors (bad request, not found, etc.)
        }
        // Permanent-data failures: source path/bucket gone — retrying will never succeed.
        // Belt-and-braces for the early guard in handle(); if that ever misses an edge
        // case the exception still fails fast instead of burning 32 retries + Sentry events.
        $msg = $e->getMessage();
        if (str_contains($msg, 'Asset missing storage path or bucket')) {
            return true;
        }
        // Check for nested Guzzle ClientException (e.g. wrapped by AWS SDK)
        $current = $e;
        while ($current) {
            if ($current instanceof ClientException) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * Handle a job failure after all retries exhausted.
     *
     * Records the failure but asset remains usable.
     */
    public function failed(\Throwable $exception): void
    {
        $version = AssetVersion::find($this->assetVersionId);
        $asset = $version ? $version->asset : Asset::find($this->assetVersionId);

        if (! $asset) {
            return;
        }

        app(SystemIncidentService::class)->resolveOpenQueueJobFailuresForAsset((string) $asset->id);

        $isTimeout = $this->isLikelyTimeoutOrExhaustion($exception);
        $isResource = $this->isResourceExhaustionException($exception);
        $userLine = $isResource
            ? 'This file is too large or complex for our preview service to process on this server. You can still download the original.'
            : ($isTimeout
                ? 'Processing this file took too long, so we stopped trying to build a preview. You can still download the original.'
                : 'We could not generate a preview for this file after several attempts. You can still download the original.');

        $meta = array_merge($asset->metadata ?? [], [
            'thumbnail_skip_reason' => $isResource ? 'server_resource_limit' : 'generation_exhausted',
            'thumbnail_skip_message' => $userLine,
            'preview_unavailable_user_message' => $userLine,
            'pipeline_preview_exhausted_at' => now()->toIso8601String(),
            'pipeline_preview_last_error' => $this->sanitizeErrorMessage($exception->getMessage()),
            'thumbnails_generated' => false,
        ]);

        $analysis = $asset->analysis_status ?? 'uploading';
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
            'metadata' => $meta,
            'analysis_status' => in_array($analysis, ['uploading', 'generating_thumbnails'], true) ? 'complete' : $analysis,
        ]);

        if ($version) {
            $version->update([
                'pipeline_status' => 'complete',
                'metadata' => array_merge($version->metadata ?? [], [
                    'thumbnail_skip_reason' => $isResource ? 'server_resource_limit' : 'generation_exhausted',
                    'thumbnails_generated' => false,
                ]),
            ]);
        }

        $q = PipelineQueueResolver::imagesQueueForAsset($asset->fresh());
        Bus::chain([
            new FinalizeAssetJob($asset->id),
            new PromoteAssetJob($asset->id),
        ])->onQueue($q)->dispatch();

        PipelineLogger::error('THUMBNAILS: terminal SKIPPED after retries (preview unavailable)', [
            'asset_id' => $asset->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'is_resource' => $isResource,
        ]);

        try {
            \App\Services\ActivityRecorder::logAsset(
                $asset,
                \App\Enums\EventType::ASSET_THUMBNAIL_SKIPPED,
                [
                    'reason' => $isResource ? 'server_resource_limit' : 'generation_exhausted',
                    'attempts' => $this->attempts(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[GenerateThumbnailsJob] Activity log failed', ['error' => $e->getMessage()]);
        }

        Log::error('Thumbnail generation exhausted retries — marked SKIPPED and resumed finalize', [
            'asset_id' => $asset->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * True when the worker or ImageMagick hit a hard resource limit (retrying the same work will not help
     * without different hardware, policy, or a smaller source file).
     */
    protected function isResourceExhaustionException(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage().' ');
        $p = $e->getPrevious();
        while ($p) {
            $msg .= strtolower($p->getMessage().' ');
            $p = $p->getPrevious();
        }

        return str_contains($msg, 'allowed memory size')
            || str_contains($msg, 'out of memory')
            || str_contains($msg, 'memory exhausted')
            || str_contains($msg, 'memory allocation failed')
            || str_contains($msg, 'cannot allocate memory')
            || str_contains($msg, 'resource temporarily unavailable')
            || str_contains($msg, 'cache resources exhausted')
            || str_contains($msg, 'cache resources are exhausted')
            || str_contains($msg, 'limitdisk')
            || str_contains($msg, 'map cache')
            || (str_contains($msg, 'magick:')
                && (str_contains($msg, 'memory') || str_contains($msg, 'resource') || str_contains($msg, 'limit')));
    }

    /**
     * Terminal SKIPPED after OOM / IM resource error — same UX intent as over max_source_bytes.
     */
    protected function applyResourceExhaustionSkip(Asset $asset, ?AssetVersion $version, \Throwable $e): void
    {
        $userMsg = 'This file is too large or complex for our preview service to process on this server. You can still download the original.';

        $metadata = array_merge($asset->metadata ?? [], [
            'thumbnail_skip_reason' => 'server_resource_limit',
            'thumbnail_skip_message' => $userMsg,
            'preview_unavailable_user_message' => $userMsg,
            'thumbnails_generated' => false,
            'pipeline_preview_last_error' => $this->sanitizeErrorMessage($e->getMessage()),
            'pipeline_resource_limit_at' => now()->toIso8601String(),
        ]);

        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
            'metadata' => $metadata,
        ]);
        if ($version) {
            $version->update([
                'pipeline_status' => 'complete',
                'metadata' => array_merge($version->metadata ?? [], [
                    'thumbnail_skip_reason' => 'server_resource_limit',
                    'thumbnails_generated' => false,
                ]),
            ]);
        }
    }

    protected function applyWorkerBudgetThumbnailSkip(Asset $asset, ?AssetVersion $version, ProcessingBudgetDecision $decision): void
    {
        $budgetService = app(AssetProcessingBudgetService::class);
        $guard = $budgetService->guardrailMetadataPayload($decision);
        $userMsg = $budgetService->humanMessage($decision);

        Log::info('[GenerateThumbnailsJob] Skipping thumbnails — worker processing budget', [
            'asset_id' => $asset->id,
            'version_id' => $version?->id,
            'decision' => $decision->kind,
            'code' => $decision->failureCode(),
        ]);

        $metadata = array_merge($asset->metadata ?? [], $guard, [
            'thumbnail_skip_reason' => 'worker_processing_guardrail',
            'thumbnail_skip_message' => $userMsg,
            'preview_unavailable_user_message' => $userMsg,
            'thumbnails_generated' => false,
        ]);
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
            'metadata' => $metadata,
        ]);
        if ($version) {
            $version->update([
                'metadata' => array_merge($version->metadata ?? [], $guard, [
                    'thumbnail_skip_reason' => 'worker_processing_guardrail',
                    'thumbnail_skip_message' => $userMsg,
                    'thumbnails_generated' => false,
                ]),
                'pipeline_status' => 'complete',
            ]);
        }
    }

    protected function applyMaxSourceBytesSkip(Asset $asset, ?AssetVersion $version, int $fileSizeBytes, int $maxBytes): void
    {
        $mb = round($fileSizeBytes / 1024 / 1024, 1);
        $maxMb = round($maxBytes / 1024 / 1024, 1);
        $userMsg = "This file is about {$mb} MB, which is above our current preview limit ({$maxMb} MB). You can still download the full original.";

        Log::info('[GenerateThumbnailsJob] Skipping thumbnails — source file over max_source_bytes', [
            'asset_id' => $asset->id,
            'file_size_bytes' => $fileSizeBytes,
            'max_source_bytes' => $maxBytes,
        ]);

        $metadata = array_merge($asset->metadata ?? [], [
            'thumbnail_skip_reason' => 'source_file_too_large',
            'thumbnail_skip_message' => $userMsg,
            'preview_unavailable_user_message' => $userMsg,
            'thumbnails_generated' => false,
        ]);
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
            'metadata' => $metadata,
        ]);
        if ($version) {
            $version->update([
                'metadata' => array_merge($version->metadata ?? [], [
                    'thumbnail_skip_reason' => 'source_file_too_large',
                    'thumbnails_generated' => false,
                ]),
                'pipeline_status' => 'complete',
            ]);
        }
    }

    /**
     * ProcessAssetJob::failed() (and similar paths) merge skip/preview messages onto metadata.
     * When thumbnails later complete, array_merge does not remove those keys — clear them here
     * so the UI and fullscreen preview match actual derivative state.
     */
    private function stripStaleThumbnailFailureMetadataAfterSuccess(array $meta): array
    {
        foreach ([
            'thumbnail_skip_reason',
            'thumbnail_skip_message',
            'preview_unavailable_user_message',
            'pipeline_process_asset_exhausted_at',
            'pipeline_aborted_after_process_failure',
        ] as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }

    protected function isLikelyTimeoutOrExhaustion(\Throwable $exception): bool
    {
        $cur = $exception;
        while ($cur) {
            if ($cur instanceof MaxAttemptsExceededException) {
                return true;
            }
            $cur = $cur->getPrevious();
        }
        $msg = strtolower($exception->getMessage());

        return str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'exceeded the timeout')
            || str_contains($msg, 'worker timeout')
            || str_contains($msg, 'has been attempted too many times');
    }

    /**
     * Create S3 client instance for file verification.
     */
    protected function createS3Client(): S3Client
    {
        if (! class_exists(S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    /**
     * Delete all S3 objects with the given prefix (Phase 7: idempotent rerun).
     */
    protected function deleteS3Prefix(S3Client $s3Client, string $bucket, string $prefix): void
    {
        try {
            $continuationToken = null;
            do {
                $params = ['Bucket' => $bucket, 'Prefix' => $prefix];
                if ($continuationToken) {
                    $params['ContinuationToken'] = $continuationToken;
                }
                $result = $s3Client->listObjectsV2($params);
                $contents = $result['Contents'] ?? [];
                if (! empty($contents)) {
                    $objects = array_map(fn ($o) => ['Key' => $o['Key']], $contents);
                    $s3Client->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $objects]]);
                }
                $continuationToken = $result['IsTruncated'] ? ($result['NextContinuationToken'] ?? null) : null;
            } while ($continuationToken);
        } catch (S3Exception $e) {
            Log::warning('[GenerateThumbnailsJob] Failed to delete existing thumbnails prefix', [
                'bucket' => $bucket,
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - proceed with generation; old thumbnails may be overwritten
        }
    }

    /**
     * Nested payload from {@see ExtractMetadataJob} (`metadata.metadata` on the asset).
     *
     * @return array<string, mixed>
     */
    protected function nestedExtractedMetadata(Asset $asset): array
    {
        $blob = $asset->metadata['metadata'] ?? null;

        return is_array($blob) ? $blob : [];
    }

    /**
     * Extension for thumbnail gating (dimensions, support check, PDF guardrail).
     * Version path: file_path, then asset original_filename, then extracted nested original_filename.
     */
    protected function resolveThumbnailPipelineExtension(Asset $asset, ?AssetVersion $version): string
    {
        $nested = $this->nestedExtractedMetadata($asset);

        if ($version) {
            $ext = $version->file_path
                ? strtolower(pathinfo((string) $version->file_path, PATHINFO_EXTENSION))
                : '';
            if ($ext === '' && $asset->original_filename) {
                $ext = strtolower(pathinfo((string) $asset->original_filename, PATHINFO_EXTENSION));
            }
            if ($ext === '' && ! empty($nested['original_filename']) && is_string($nested['original_filename'])) {
                $ext = strtolower(pathinfo($nested['original_filename'], PATHINFO_EXTENSION));
            }

            return $ext;
        }

        $ext = $asset->original_filename
            ? strtolower(pathinfo((string) $asset->original_filename, PATHINFO_EXTENSION))
            : '';
        if ($ext === '' && $asset->storage_root_path) {
            $ext = strtolower(pathinfo((string) $asset->storage_root_path, PATHINFO_EXTENSION));
        }
        if ($ext === '' && ! empty($nested['original_filename']) && is_string($nested['original_filename'])) {
            $ext = strtolower(pathinfo($nested['original_filename'], PATHINFO_EXTENSION));
        }

        return $ext;
    }

    /**
     * MIME candidates for gates: prefer version row, then asset row, then nested extraction (often correct when
     * version MIME was mis-sniffed).
     *
     * @return list<string>
     */
    protected function resolveThumbnailPipelineMimeCandidates(Asset $asset, ?AssetVersion $version): array
    {
        $nested = $this->nestedExtractedMetadata($asset);
        $extractedMime = isset($nested['mime_type']) && is_string($nested['mime_type']) ? $nested['mime_type'] : '';

        $out = [];
        if ($version && is_string($version->mime_type) && $version->mime_type !== '') {
            $out[] = $version->mime_type;
        }
        if (is_string($asset->mime_type) && $asset->mime_type !== '') {
            $out[] = $asset->mime_type;
        }
        if ($extractedMime !== '') {
            $out[] = $extractedMime;
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Check if thumbnail generation is supported for an asset.
     *
     * When version is provided, still considers asset MIME and nested extracted MIME so Office is not mis-routed
     * as raster when the version row MIME is wrong.
     *
     * @param  AssetVersion|null  $version  When provided, uses version->mime_type for file type detection
     * @return bool True if thumbnail generation is supported
     */
    protected function supportsThumbnailGeneration(Asset $asset, ?AssetVersion $version = null): bool
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $ext = $this->resolveThumbnailPipelineExtension($asset, $version);
        $candidates = $this->resolveThumbnailPipelineMimeCandidates($asset, $version);
        if ($candidates === []) {
            $candidates = [''];
        }

        foreach ($candidates as $rawMime) {
            $mime = is_string($rawMime) && $rawMime !== '' ? strtolower($rawMime) : '';
            if ($fileTypeService->supportsThumbnailPipelineForMimeAndExtension(
                $mime !== '' ? $mime : null,
                $ext !== '' ? $ext : null,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine skip reason for unsupported file types.
     *
     * Step 5: Provides human-readable skip reasons for UI display.
     *
     * @return string Skip reason (e.g., "unsupported_format:tiff", "unsupported_format:avif")
     */
    protected function determineSkipReason(string $mimeType, string $extension): string
    {
        // Use FileTypeService to determine skip reason
        $fileTypeService = app(\App\Services\FileTypeService::class);

        // Check if file type is explicitly unsupported
        $unsupported = $fileTypeService->getUnsupportedReason($mimeType, $extension);
        if ($unsupported) {
            return $unsupported['skip_reason'] ?? 'unsupported_file_type';
        }

        // Detect file type
        $fileType = $fileTypeService->detectFileType($mimeType, $extension);

        if (! $fileType) {
            return 'unsupported_file_type';
        }

        if ($fileTypeService->isModel3dRegistryType($fileType)) {
            if (! $fileTypeService->supportsCapability($fileType, 'thumbnail')) {
                return "unsupported_format:{$fileType}";
            }
            if (! (bool) config('dam_3d.enabled')) {
                return 'dam_3d_preview_disabled';
            }
            $handler = $fileTypeService->getHandler($fileType, 'thumbnail');
            if (! is_string($handler) || $handler === '' || ! method_exists(\App\Services\ThumbnailGenerationService::class, $handler)) {
                return 'model_3d_thumbnail_pipeline_pending';
            }
            $requirements = $fileTypeService->checkRequirements($fileType);
            if (! $requirements['met']) {
                return 'model_3d_thumbnail_pipeline_pending';
            }
        }

        // Check requirements to determine specific skip reason
        $requirements = $fileTypeService->checkRequirements($fileType);
        if (! $requirements['met']) {
            // Check for specific missing requirements
            foreach ($requirements['missing'] as $missing) {
                if (str_contains($missing, 'FFmpeg')) {
                    // Video files require FFmpeg for thumbnail generation
                    if ($fileType === 'video') {
                        return 'unsupported_format:video_ffmpeg_missing';
                    }

                    return 'unsupported_format:video_ffmpeg_missing';
                }
                if (str_contains($missing, 'LibreOffice') || str_contains($missing, 'soffice')) {
                    if ($fileType === 'office') {
                        return 'office_libreoffice_missing';
                    }
                }
                if (str_contains($missing, 'Imagick')) {
                    if ($fileType === 'tiff') {
                        return 'unsupported_format:tiff';
                    }
                    if ($fileType === 'cr2') {
                        return 'unsupported_format:cr2';
                    }
                    if ($fileType === 'avif') {
                        return 'unsupported_format:avif';
                    }
                    if ($fileType === 'heic') {
                        return 'unsupported_format:heic';
                    }
                    if ($fileType === 'psd') {
                        return 'unsupported_format:psd';
                    }
                    if ($fileType === 'office') {
                        return 'unsupported_format:office_imagick';
                    }
                }
                if (str_contains($missing, 'spatie/pdf-to-image')) {
                    if ($fileType === 'office' || $fileType === 'pdf') {
                        return 'unsupported_format:pdf_stack';
                    }
                }
            }
        }

        // Canon CR2 — Imagick + ImageMagick RAW delegate required
        if ($mimeType === 'image/x-canon-cr2' || $extension === 'cr2') {
            if (! extension_loaded('imagick')) {
                return 'unsupported_format:cr2';
            }

            return 'unsupported_file_type';
        }

        // TIFF - Check if Imagick is available, otherwise mark as unsupported
        if ($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif') {
            // If Imagick is not available, mark as unsupported
            if (! extension_loaded('imagick')) {
                return 'unsupported_format:tiff';
            }

            // If Imagick is available, TIFF should be supported - return generic reason
            // (This shouldn't normally be reached if supportsThumbnailGeneration works correctly)
            return 'unsupported_file_type';
        }

        // AVIF - Check if Imagick is available, otherwise mark as unsupported
        if ($mimeType === 'image/avif' || $extension === 'avif') {
            // If Imagick is not available, mark as unsupported
            if (! extension_loaded('imagick')) {
                return 'unsupported_format:avif';
            }

            // If Imagick is available, AVIF should be supported - return generic reason
            // (This shouldn't normally be reached if supportsThumbnailGeneration works correctly)
            return 'unsupported_file_type';
        }

        // HEIC/HEIF — Imagick + ImageMagick HEIF coder (libheif)
        if ($mimeType === 'image/heic' || $mimeType === 'image/heif' || $extension === 'heic' || $extension === 'heif') {
            $heicReq = $fileTypeService->checkRequirements('heic');
            if (! $heicReq['met']) {
                return 'unsupported_format:heic';
            }

            return 'unsupported_file_type';
        }

        // PSD/PSB - Check if Imagick is available, otherwise mark as unsupported
        if ($mimeType === 'image/vnd.adobe.photoshop' || $extension === 'psd' || $extension === 'psb') {
            // If Imagick is not available, mark as unsupported
            if (! extension_loaded('imagick')) {
                return 'unsupported_format:psd';
            }

            // If Imagick is available, PSD should be supported - return generic reason
            // (This shouldn't normally be reached if supportsThumbnailGeneration works correctly)
            return 'unsupported_file_type';
        }

        // Video - Check if FFmpeg is missing
        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'])) {
            return 'unsupported_format:video_ffmpeg_missing';
        }

        // BMP - GD library has limited BMP support, not reliable
        if ($mimeType === 'image/bmp' || $extension === 'bmp') {
            return 'unsupported_format:bmp';
        }

        // Known file type but not explicitly handled above (e.g. format added to unsupported later)
        // Use FileTypeService pattern for consistency with UploadCompletionService
        if ($fileType && ! $fileTypeService->supportsCapability($fileType, 'thumbnail')) {
            return "unsupported_format:{$fileType}";
        }

        // Generic fallback
        return 'unsupported_file_type';
    }

    /**
     * Get user-facing message for thumbnail skip (for UI display).
     *
     * @return string User-friendly message (e.g., "Thumbnail generation is not supported for this file type.")
     */
    protected function getThumbnailSkipMessage(string $mimeType, string $extension, ?string $skipReason = null): string
    {
        if ($skipReason === 'dam_3d_preview_disabled') {
            return '3D previews are disabled in this environment. The file was uploaded successfully; enable DAM_3D on workers when the preview pipeline is ready.';
        }
        if ($skipReason === 'model_3d_thumbnail_pipeline_pending') {
            return '3D thumbnail rendering is not deployed yet. The file is stored safely and will show previews when the pipeline is enabled.';
        }

        if ($skipReason === 'office_libreoffice_missing') {
            return 'Office previews require LibreOffice on the server. Install worker packages from docs/environments/PRODUCTION_WORKER_SOFTWARE.md, then re-run thumbnail generation.';
        }

        $fileTypeService = app(\App\Services\FileTypeService::class);
        $unsupported = $fileTypeService->getUnsupportedReason($mimeType, $extension);
        if ($unsupported && ! empty($unsupported['message'])) {
            return $unsupported['message'];
        }

        return config('file_types.global_errors.unknown_type', 'Thumbnail generation is not supported for this file type.');
    }

    /**
     * Persist page 1 PDF derivative record from already-generated thumbnails.
     */
    protected function syncFirstPdfPageRecord(Asset $asset, ?AssetVersion $version, array $finalThumbnails, ?int $pdfPageCount): void
    {
        $source = $finalThumbnails['large'] ?? $finalThumbnails['medium'] ?? $finalThumbnails['thumb'] ?? null;
        $sourcePath = is_array($source) ? ($source['path'] ?? null) : null;

        if (! $sourcePath || ! $asset->storageBucket) {
            return;
        }

        $versionNumber = $version?->version_number
            ?? ($asset->relationLoaded('currentVersion')
                ? ($asset->currentVersion?->version_number ?? 1)
                : (int) ($asset->currentVersion()->value('version_number') ?? 1));

        try {
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp';
            $targetPath = app(AssetPathGenerator::class)->generatePdfPagePath(
                $asset->tenant,
                $asset,
                $versionNumber,
                1,
                $extension
            );

            $storedPath = $sourcePath;
            if ($targetPath !== $sourcePath) {
                $copySource = $asset->storageBucket->name.'/'.str_replace('%2F', '/', rawurlencode($sourcePath));
                $this->createS3Client()->copyObject([
                    'Bucket' => $asset->storageBucket->name,
                    'CopySource' => $copySource,
                    'Key' => $targetPath,
                    'MetadataDirective' => 'REPLACE',
                    'ContentType' => strtolower($extension) === 'webp' ? 'image/webp' : 'image/jpeg',
                    'Metadata' => [
                        'original-asset-id' => $asset->id,
                        'pdf-page' => '1',
                        'generated-at' => now()->toIso8601String(),
                    ],
                ]);
                $storedPath = $targetPath;
            }

            AssetPdfPage::updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'version_number' => $versionNumber,
                    'page_number' => 1,
                ],
                [
                    'tenant_id' => $asset->tenant_id,
                    'asset_version_id' => $version?->id,
                    'storage_path' => $storedPath,
                    'width' => isset($source['width']) ? (int) $source['width'] : null,
                    'height' => isset($source['height']) ? (int) $source['height'] : null,
                    'size_bytes' => isset($source['size_bytes']) ? (int) $source['size_bytes'] : null,
                    'mime_type' => strtolower($extension) === 'webp' ? 'image/webp' : 'image/jpeg',
                    'status' => 'completed',
                    'error' => null,
                    'rendered_at' => now(),
                ]
            );

            if (($pdfPageCount ?? 0) <= 1) {
                $asset->forceFill(['pdf_pages_rendered' => true])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('[GenerateThumbnailsJob] Failed to persist first PDF page derivative', [
                'asset_id' => $asset->id,
                'version_id' => $version?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether this asset should enqueue full PDF extraction after page 1.
     */
    protected function shouldScheduleFullPdfExtraction(Asset $asset, ?int $pdfPageCount): bool
    {
        if (($pdfPageCount ?? 0) <= 1) {
            return false;
        }

        $metadata = $asset->metadata ?? [];

        return (bool) ($metadata['pdf_extract_full'] ?? $metadata['pdf_full_extraction_requested'] ?? false);
    }

    /**
     * Convert technical error messages to user-friendly messages.
     *
     * This sanitizes exception messages and technical details that users shouldn't see,
     * replacing them with clear, actionable error messages.
     *
     * @param  string  $errorMessage  The raw error message
     * @return string User-friendly error message
     */
    protected function sanitizeErrorMessage(string $errorMessage): string
    {
        // Map technical errors to user-friendly messages
        $errorMappings = [
            // PDF-related errors
            'Call to undefined method.*setPage' => 'PDF processing error. Please try again or contact support if the issue persists.',
            'Call to undefined method.*selectPage' => 'PDF processing error. Please try again or contact support if the issue persists.',
            'PDF file does not exist' => 'The PDF file could not be found or accessed.',
            'Invalid PDF format' => 'The PDF file appears to be corrupted or invalid.',
            'PDF thumbnail generation failed' => 'Unable to generate preview from PDF. The file may be corrupted or too large.',

            // Image processing errors
            'getimagesize.*failed' => 'Unable to read image file. The file may be corrupted.',
            'imagecreatefrom.*failed' => 'Unable to process image. The file format may not be supported.',
            'imagecopyresampled.*failed' => 'Unable to resize image. Please try again.',

            // Storage errors - NoSuchKey when temp file was cleaned up before promotion
            'NoSuchKey' => 'Source file no longer exists in storage. The temporary upload may have been cleaned up before processing completed. Please re-upload the file.',
            'specified key does not exist' => 'Source file no longer exists in storage. Please re-upload the file.',
            'S3.*error' => 'Unable to save thumbnail. Please try again.',
            'Storage.*failed' => 'Unable to save thumbnail. Please check storage configuration.',

            // Timeout errors
            'timeout' => 'Thumbnail generation timed out. The file may be too large or complex.',
            'Maximum execution time' => 'Thumbnail generation took too long. The file may be too large.',

            // Generic technical errors
            'Error:' => 'An error occurred during thumbnail generation.',
            'Exception:' => 'An error occurred during thumbnail generation.',
            'Fatal error' => 'An error occurred during thumbnail generation.',
        ];

        // Check for specific error patterns and replace with user-friendly messages
        foreach ($errorMappings as $pattern => $friendlyMessage) {
            if (preg_match('/'.$pattern.'/i', $errorMessage)) {
                return $friendlyMessage;
            }
        }

        // If error contains class names or technical paths, provide generic message
        if (preg_match('/(\\\\[A-Z][a-zA-Z0-9\\\\]+|::|->|at\s+\/.*\.php)/', $errorMessage)) {
            return 'An error occurred during thumbnail generation. Please try again or contact support if the issue persists.';
        }

        // For other errors, try to extract a meaningful message
        // Remove common technical prefixes
        $cleaned = preg_replace('/^(Error|Exception|Fatal error):\s*/i', '', $errorMessage);

        // If the cleaned message is still too technical, use generic message
        if (strlen($cleaned) > 200 || preg_match('/[{}()\[\]\\\]/', $cleaned)) {
            return 'An error occurred during thumbnail generation. Please try again.';
        }

        return $cleaned;
    }
}
