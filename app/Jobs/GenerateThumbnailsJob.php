<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetProcessingFailureService;
use App\Services\ThumbnailGenerationService;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
 * Live updates are a DEFERRED FEATURE. See THUMBNAIL_PIPELINE.md for details.
 * 
 * TODO (future): Allow manual thumbnail regeneration per asset.
 * TODO (future): Consider websocket-based thumbnail update broadcasting.
 * TODO (future): Consider thumbnail_version field for live UI refresh.
 */
class GenerateThumbnailsJob implements ShouldQueue
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
     *
     * Generates all thumbnail styles for the asset atomically.
     * Updates thumbnail_status and metadata on success or failure.
     */
    public function handle(ThumbnailGenerationService $thumbnailService): void
    {
        Log::info('[GenerateThumbnailsJob] Job started', [
            'asset_id' => $this->assetId,
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempt' => $this->attempts(),
        ]);

        try {
            $asset = Asset::findOrFail($this->assetId);

            // Idempotency: Check if thumbnails already completed
            // NULL or PENDING means thumbnails haven't been attempted or are pending
            if ($asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
                Log::info('[GenerateThumbnailsJob] Thumbnail generation skipped - already completed', [
                    'asset_id' => $asset->id,
                ]);
                // Job chaining is handled by Bus::chain() in ProcessAssetJob
                // Chain will continue to next job automatically
                return;
            }

        // Step 5: Defensive check - Skip if file type doesn't support thumbnails
        // This prevents false "started" events for unsupported formats
        // (e.g., TIFF, AVIF, BMP, SVG - GD library does not support these)
        if (!$this->supportsThumbnailGeneration($asset)) {
            // Determine skip reason based on file type
            $mimeType = strtolower($asset->mime_type ?? '');
            $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
            $skipReason = $this->determineSkipReason($mimeType, $extension);
            
            // Store skip reason in metadata for UI display
            $metadata = $asset->metadata ?? [];
            $metadata['thumbnail_skip_reason'] = $skipReason;
            
            // Mark as skipped with clear error message
            // SKIPPED assets never started processing, so no thumbnail_started_at needed
            $asset->update([
                'thumbnail_status' => ThumbnailStatus::SKIPPED,
                'thumbnail_error' => "Thumbnail generation skipped: {$skipReason}",
                'thumbnail_started_at' => null, // SKIPPED never started, so no start time
                'metadata' => $metadata,
            ]);
            
            Log::info('[GenerateThumbnailsJob] Marked asset as SKIPPED', [
                'asset_id' => $asset->id,
                'skip_reason' => $skipReason,
            ]);
            
            // Log skipped event (truthful - work never happened)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_SKIPPED,
                    [
                        'reason' => $skipReason,
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
            }
            
            if ($isNowSupported) {
                // Clear the skip reason and reset status to allow regeneration
                unset($metadata['thumbnail_skip_reason']);
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::PENDING,
                    'thumbnail_error' => null,
                    'metadata' => $metadata,
                ]);
                
                Log::info('[GenerateThumbnailsJob] Cleared old skip reason - format now supported', [
                    'asset_id' => $asset->id,
                    'old_skip_reason' => $skipReason,
                    'format' => $mimeType . '/' . $extension,
                ]);
            }
        }

        // Update status to processing and record start time for timeout detection
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::PROCESSING,
            'thumbnail_error' => null,
            'thumbnail_started_at' => now(),
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
        // Note: Thumbnail generation errors are caught by outer catch block
        $thumbnails = $thumbnailService->generateThumbnails($asset);

            // Step 6: Separate preview thumbnails from final thumbnails
            // Preview thumbnails are stored separately and do NOT affect COMPLETED status
            $previewThumbnails = [];
            $finalThumbnails = [];
            
            foreach ($thumbnails as $styleName => $thumbnailData) {
                if ($styleName === 'preview') {
                    $previewThumbnails[$styleName] = $thumbnailData;
                } else {
                    $finalThumbnails[$styleName] = $thumbnailData;
                }
            }
            
            // Step 6: Store preview thumbnails in metadata immediately (for early UI feedback)
            // Preview existence does NOT mark COMPLETED - final thumbnails control completion
            if (!empty($previewThumbnails)) {
                $metadata = $asset->metadata ?? [];
                $metadata['preview_thumbnails'] = $previewThumbnails;
                $asset->update(['metadata' => $metadata]);
                
                Log::info('Preview thumbnails generated and stored', [
                    'asset_id' => $asset->id,
                    'preview_count' => count($previewThumbnails),
                ]);
            }

            // CRITICAL: If NO final thumbnails were generated, mark as FAILED immediately
            // This prevents marking as COMPLETED when all thumbnail generation failed
            // (e.g., PDF conversion failed, all styles failed, etc.)
            if (empty($finalThumbnails)) {
                $errorMessage = 'Thumbnail generation failed: No thumbnails were generated (all styles failed)';
                
                Log::error('Thumbnail generation failed - no final thumbnails generated', [
                    'asset_id' => $asset->id,
                    'total_thumbnails_returned' => count($thumbnails),
                    'preview_thumbnails' => count($previewThumbnails),
                    'final_thumbnails' => count($finalThumbnails),
                ]);
                
                // Mark as FAILED immediately - job failed, not transient issue
                // Clear thumbnail_started_at when failed (no longer needed)
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::FAILED,
                    'thumbnail_error' => $errorMessage,
                    'thumbnail_started_at' => null, // Clear start time on failure
                ]);
                
                Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (no thumbnails generated)', [
                    'asset_id' => $asset->id,
                    'error' => $errorMessage,
                ]);
                
                // Log failure event (truthful - job failed)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                        [
                            'error' => $errorMessage,
                            'reason' => 'No thumbnails were generated - all styles failed',
                        ]
                    );
                } catch (\Exception $logException) {
                    Log::error('Failed to log thumbnail failed event', [
                        'asset_id' => $asset->id,
                        'error' => $logException->getMessage(),
                    ]);
                }
                
                // Update metadata to record failure
                // Step 6: Preserve preview thumbnails even if final generation fails
                $currentMetadata = $asset->metadata ?? [];
                $currentMetadata['thumbnail_generation_failed'] = true;
                $currentMetadata['thumbnail_generation_failed_at'] = now()->toIso8601String();
                $currentMetadata['thumbnail_generation_error'] = $errorMessage;
                // Preview thumbnails remain in metadata (if they were generated)
                $asset->update(['metadata' => $currentMetadata]);
                
                // Throw exception to prevent "completed" event logging below
                throw new \RuntimeException($errorMessage);
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
                $thumbnailPath = $thumbnailData['path'] ?? null;
                if (!$thumbnailPath) {
                    $allThumbnailsValid = false;
                    $verificationErrors[] = "Thumbnail path missing for style '{$styleName}'";
                    Log::error('Thumbnail path missing in generated metadata', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                    ]);
                    continue;
                }
                
                // Verify thumbnail file exists in S3 and is valid
                try {
                    $result = $s3Client->headObject([
                        'Bucket' => $bucket->name,
                        'Key' => $thumbnailPath,
                    ]);
                    
                    // Verify file size > minimum threshold (only catch broken/corrupted files)
                    $contentLength = $result['ContentLength'] ?? 0;
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
                } catch (S3Exception $e) {
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
                    } else {
                        // Re-throw non-404 errors (network issues, permissions, etc.)
                        throw $e;
                    }
                }
            }

            // CRITICAL: Only mark as COMPLETED if ALL thumbnails are valid
            // Step 4: Job truth enforcement - never mark COMPLETED unless files are real and readable
            // 
            // If verification fails:
            // - Mark as FAILED immediately (not PROCESSING) - job failed, not transient
            // - Persist actual error message with details
            // - Do NOT mark as COMPLETED
            // - Do NOT record "completed" event
            // - Throw exception to prevent "completed" event logging below
            if (!$allThumbnailsValid) {
                $errorMessage = 'Thumbnail generation failed: ' . implode('; ', $verificationErrors);
                
                Log::error('Thumbnail generation failed - verification failed', [
                    'asset_id' => $asset->id,
                    'thumbnail_count' => count($thumbnails),
                    'errors' => $verificationErrors,
                ]);
                
                // Mark as FAILED immediately - job failed, not transient issue
                // Clear thumbnail_started_at when failed (no longer needed)
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::FAILED,
                    'thumbnail_error' => $errorMessage,
                    'thumbnail_started_at' => null, // Clear start time on failure
                ]);
                
                Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (verification failed)', [
                    'asset_id' => $asset->id,
                    'error' => $errorMessage,
                ]);
                
                // Log failure event (truthful - job failed)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                        [
                            'error' => $errorMessage,
                            'verification_errors' => $verificationErrors,
                        ]
                    );
                } catch (\Exception $logException) {
                    Log::error('Failed to log thumbnail failed event', [
                        'asset_id' => $asset->id,
                        'error' => $logException->getMessage(),
                    ]);
                }
                
                // Update metadata to record failure
                // Step 6: Preserve preview thumbnails even if final generation fails
                $currentMetadata = $asset->metadata ?? [];
                $currentMetadata['thumbnail_generation_failed'] = true;
                $currentMetadata['thumbnail_generation_failed_at'] = now()->toIso8601String();
                $currentMetadata['thumbnail_generation_error'] = $errorMessage;
                // Preview thumbnails remain in metadata (if they were generated)
                $asset->update(['metadata' => $currentMetadata]);
                
                // Throw exception to prevent "completed" event logging below
                throw new \RuntimeException($errorMessage);
            }
            
            // Step 6: All FINAL thumbnails are valid - update metadata and mark as completed
            // Preview thumbnails are already stored separately above and remain in metadata
            $currentMetadata = $asset->metadata ?? [];
            $currentMetadata['thumbnails_generated'] = true;
            $currentMetadata['thumbnails_generated_at'] = now()->toIso8601String();
            $currentMetadata['thumbnails'] = $finalThumbnails; // Only final thumbnails (exclude preview)

            // Phase V-1: For video assets, store poster URL from thumbnail
            $updateData = [
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
                'thumbnail_error' => null,
                'thumbnail_started_at' => null, // Clear start time on completion
                'metadata' => $currentMetadata,
            ];
            
            // Check if asset is a video and set poster path
            // Store S3 path (not presigned URL) - URLs are generated on-demand via AssetThumbnailController
            // AWS S3 presigned URLs have a maximum expiration of 7 days, so we can't store long-lived URLs
            $fileTypeService = app(\App\Services\FileTypeService::class);
            $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
            if ($fileType === 'video' && isset($finalThumbnails['thumb'])) {
                // Store poster path (S3 key) - use thumb style for grid display
                // The path will be used to generate URLs on-demand via thumbnail controller
                $posterPath = $finalThumbnails['thumb']['path'] ?? null;
                if ($posterPath) {
                    // Store the S3 path, not a presigned URL
                    // URLs will be generated on-demand when needed (via AssetThumbnailController or Asset accessor)
                    $updateData['video_poster_url'] = $posterPath;
                }
            }

            // CRITICAL: Asset.status represents visibility and must remain UPLOADED
            // Processing jobs (thumbnails, metadata, previews) must NOT mutate Asset.status
            // Processing progress is tracked via thumbnail_status, metadata flags, and activity events
            // AssetController queries only status = UPLOADED, so changing status hides assets from the grid
            // Step 4: Only mark as COMPLETED after verification passes
            // Clear thumbnail_started_at when completed (no longer needed)
            $asset->update($updateData);
            
            // Refresh asset to ensure metadata is loaded correctly
            $asset->refresh();
            
            // Verify metadata was saved correctly (defensive check)
            $savedMetadata = $asset->metadata ?? [];
            $savedThumbnails = $savedMetadata['thumbnails'] ?? [];
            $thumbPath = $asset->thumbnailPathForStyle('thumb');
            
            Log::info('[GenerateThumbnailsJob] Marked asset as COMPLETED', [
                'asset_id' => $asset->id,
                'thumbnail_count' => count($finalThumbnails),
                'saved_thumbnail_styles' => array_keys($savedThumbnails),
                'thumb_path_exists' => $thumbPath !== null,
                'thumb_path' => $thumbPath,
            ]);
            
            // If metadata wasn't saved correctly, log warning (but don't fail - status is already set)
            if (empty($savedThumbnails) || !$thumbPath) {
                Log::warning('[GenerateThumbnailsJob] Thumbnail metadata may not have saved correctly', [
                    'asset_id' => $asset->id,
                    'expected_thumbnails' => array_keys($finalThumbnails),
                    'saved_thumbnails' => array_keys($savedThumbnails),
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
                    'thumbnail_count' => count($thumbnails),
                    'styles' => array_keys($thumbnails),
                ],
                'created_at' => now(),
            ]);

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
                'thumbnail_count' => count($thumbnails),
                'styles' => array_keys($thumbnails),
            ]);
            
            Log::info('[GenerateThumbnailsJob] Job completed successfully', [
                'asset_id' => $asset->id,
                'job_id' => $this->job->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[GenerateThumbnailsJob] Job failed with exception', [
                'asset_id' => $this->assetId,
                'job_id' => $this->job->getJobId() ?? 'unknown',
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
                $errorMessage .= ' (Previous: ' . $previous->getMessage() . ')';
            }
            
            // Include exception class name for better debugging (for logs only)
            $fullErrorMessage = get_class($e) . ': ' . $errorMessage;
            
            // $asset may not be defined if exception occurred before findOrFail
            $asset = $asset ?? Asset::find($this->assetId);
            
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
                
                // Mark as FAILED with user-friendly error message
                // Clear thumbnail_started_at when failed (no longer needed)
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::FAILED,
                    'thumbnail_error' => $userFriendlyError,
                    'thumbnail_started_at' => null, // Clear start time on failure
                ]);
                
                Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (exception)', [
                    'asset_id' => $asset->id,
                    'error' => $fullErrorMessage,
                    'user_friendly_error' => $userFriendlyError,
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
                $asset->update(['metadata' => $currentMetadata]);
            } else {
                // Asset not found - log error but can't update
                Log::error('[GenerateThumbnailsJob] Thumbnail generation failed - asset not found', [
                    'asset_id' => $this->assetId,
                    'error' => $errorMessage,
                    'exception_class' => get_class($e),
                    'attempt' => $this->attempts(),
                ]);
            }

            // Re-throw to trigger job retry mechanism
            // After all retries exhausted, failed() method will be called
            throw $e;
        }

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Handle a job failure after all retries exhausted.
     *
     * Records the failure but asset remains usable.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Sanitize error message for user display
            $userFriendlyError = $this->sanitizeErrorMessage($exception->getMessage());
            
            // Update thumbnail status to failed
            // Clear thumbnail_started_at when failed (no longer needed)
            $asset->update([
                'thumbnail_status' => ThumbnailStatus::FAILED,
                'thumbnail_error' => $userFriendlyError,
                'thumbnail_started_at' => null, // Clear start time on failure
            ]);
            
            Log::info('[GenerateThumbnailsJob] Marked asset as FAILED (failed() method)', [
                'asset_id' => $asset->id,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            // Use centralized failure recording service for observability
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts()
            );

            Log::error('Thumbnail generation job failed after all retries', [
                'asset_id' => $asset->id,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);
        }
    }

    /**
     * Create S3 client instance for file verification.
     *
     * @return S3Client
     */
    protected function createS3Client(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ];

        // Support MinIO for local development
        if (env('AWS_ENDPOINT')) {
            $config['endpoint'] = env('AWS_ENDPOINT');
            $config['use_path_style_endpoint'] = env('AWS_USE_PATH_STYLE_ENDPOINT', true);
        }

        return new S3Client($config);
    }

    /**
     * Check if thumbnail generation is supported for an asset.
     * 
     * Supports both image types (via GD library) and PDFs (via spatie/pdf-to-image).
     * This is the central authority for thumbnail support - used by both jobs and retry service.
     * 
     * IMPORTANT: PDF support is additive - does not modify existing image processing logic.
     * 
     * @param Asset $asset
     * @return bool True if thumbnail generation is supported
     */
    protected function supportsThumbnailGeneration(Asset $asset): bool
    {
        // Use FileTypeService as the single source of truth for file type support
        $fileTypeService = app(\App\Services\FileTypeService::class);
        
        // Detect file type
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        
        if (!$fileType) {
            // Unknown file type - not supported
            return false;
        }
        
        // Check if file type supports thumbnail generation
        if (!$fileTypeService->supportsCapability($fileType, 'thumbnail')) {
            return false;
        }
        
        // Check if requirements are met (PHP extensions, packages, external tools)
        $requirements = $fileTypeService->checkRequirements($fileType);
        if (!$requirements['met']) {
            Log::warning('[GenerateThumbnailsJob] File type requirements not met', [
                'asset_id' => $asset->id,
                'file_type' => $fileType,
                'missing' => $requirements['missing'],
                'mime_type' => $asset->mime_type,
                'filename' => $asset->original_filename,
            ]);
            return false;
        }
        
        // Additional logging for video files to help diagnose issues
        if ($fileType === 'video') {
            Log::info('[GenerateThumbnailsJob] Video thumbnail generation supported', [
                'asset_id' => $asset->id,
                'file_type' => $fileType,
                'mime_type' => $asset->mime_type,
                'filename' => $asset->original_filename,
                'requirements_met' => true,
            ]);
        }
        
        return true;
    }
    
    /**
     * Determine skip reason for unsupported file types.
     * 
     * Step 5: Provides human-readable skip reasons for UI display.
     * 
     * @param string $mimeType
     * @param string $extension
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
        
        if (!$fileType) {
            return 'unsupported_file_type';
        }
        
        // Check requirements to determine specific skip reason
        $requirements = $fileTypeService->checkRequirements($fileType);
        if (!$requirements['met']) {
            // Check for specific missing requirements
            foreach ($requirements['missing'] as $missing) {
                if (str_contains($missing, 'FFmpeg')) {
                    // Video files require FFmpeg for thumbnail generation
                    if ($fileType === 'video') {
                        return 'unsupported_format:video_ffmpeg_missing';
                    }
                    return 'unsupported_format:video_ffmpeg_missing';
                }
                if (str_contains($missing, 'Imagick')) {
                    if ($fileType === 'tiff') {
                        return 'unsupported_format:tiff';
                    }
                    if ($fileType === 'avif') {
                        return 'unsupported_format:avif';
                    }
                }
            }
        }
        
        // TIFF - Check if Imagick is available, otherwise mark as unsupported
        if ($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif') {
            // If Imagick is not available, mark as unsupported
            if (!extension_loaded('imagick')) {
                return 'unsupported_format:tiff';
            }
            // If Imagick is available, TIFF should be supported - return generic reason
            // (This shouldn't normally be reached if supportsThumbnailGeneration works correctly)
            return 'unsupported_file_type';
        }
        
        // AVIF - Check if Imagick is available, otherwise mark as unsupported
        if ($mimeType === 'image/avif' || $extension === 'avif') {
            // If Imagick is not available, mark as unsupported
            if (!extension_loaded('imagick')) {
                return 'unsupported_format:avif';
            }
            // If Imagick is available, AVIF should be supported - return generic reason
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
        
        // SVG - GD library does not support SVG (requires Imagick or other tools)
        if ($mimeType === 'image/svg+xml' || $extension === 'svg') {
            return 'unsupported_format:svg';
        }
        
        // Generic fallback
        return 'unsupported_file_type';
    }

    /**
     * Convert technical error messages to user-friendly messages.
     * 
     * This sanitizes exception messages and technical details that users shouldn't see,
     * replacing them with clear, actionable error messages.
     * 
     * @param string $errorMessage The raw error message
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
            
            // Storage errors
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
            if (preg_match('/' . $pattern . '/i', $errorMessage)) {
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
