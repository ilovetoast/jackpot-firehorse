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
        $asset = Asset::findOrFail($this->assetId);

        // Idempotency: Check if thumbnails already completed
        // NULL or PENDING means thumbnails haven't been attempted or are pending
        if ($asset->thumbnail_status === ThumbnailStatus::COMPLETED) {
            Log::info('Thumbnail generation skipped - already completed', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Defensive check: Skip if file type doesn't support thumbnails
        // This prevents false "started" events for unsupported formats
        // (e.g., AVIF, or if job is dispatched from elsewhere)
        if (!$this->supportsThumbnailGeneration($asset)) {
            $asset->update([
                'thumbnail_status' => ThumbnailStatus::SKIPPED,
                'thumbnail_error' => 'Thumbnail generation skipped: unsupported file type',
            ]);
            
            // Log skipped event (truthful - work never happened)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_SKIPPED,
                    [
                        'reason' => 'unsupported_file_type',
                        'mime_type' => $asset->mime_type,
                        'file_extension' => pathinfo($asset->original_filename, PATHINFO_EXTENSION),
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
            ]);
            return;
        }

        // Update status to processing
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::PROCESSING,
            'thumbnail_error' => null,
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

        try {
            // Generate all thumbnail styles atomically
            $thumbnails = $thumbnailService->generateThumbnails($asset);

            // Phase 3.1E: Verify thumbnail files exist before marking as completed
            // Only set thumbnail_status = COMPLETED after verifying files were actually created
            // This prevents false "completed" states that cause UI to skip processing/icon states
            $bucket = $asset->storageBucket;
            $s3Client = $this->createS3Client();
            $allThumbnailsExist = true;
            
            foreach ($thumbnails as $styleName => $thumbnailData) {
                $thumbnailPath = $thumbnailData['path'] ?? null;
                if (!$thumbnailPath) {
                    $allThumbnailsExist = false;
                    Log::warning('Thumbnail path missing in generated metadata', [
                        'asset_id' => $asset->id,
                        'style' => $styleName,
                    ]);
                    continue;
                }
                
                // Verify thumbnail file exists in S3
                try {
                    $s3Client->headObject([
                        'Bucket' => $bucket->name,
                        'Key' => $thumbnailPath,
                    ]);
                } catch (S3Exception $e) {
                    if ($e->getStatusCode() === 404) {
                        $allThumbnailsExist = false;
                        Log::error('Thumbnail file not found in S3 after generation', [
                            'asset_id' => $asset->id,
                            'style' => $styleName,
                            'thumbnail_path' => $thumbnailPath,
                            'bucket' => $bucket->name,
                        ]);
                    } else {
                        throw $e; // Re-throw non-404 errors
                    }
                }
            }

            // Update asset metadata with thumbnail information
            $currentMetadata = $asset->metadata ?? [];
            $currentMetadata['thumbnails_generated'] = true;
            $currentMetadata['thumbnails_generated_at'] = now()->toIso8601String();
            $currentMetadata['thumbnails'] = $thumbnails;

            // CRITICAL: Asset.status represents visibility and must remain UPLOADED
            // Processing jobs (thumbnails, metadata, previews) must NOT mutate Asset.status
            // Processing progress is tracked via thumbnail_status, metadata flags, and activity events
            // AssetController queries only status = UPLOADED, so changing status hides assets from the grid
            // Phase 3.1E: Only mark as COMPLETED if all thumbnail files exist
            // If files are missing, keep status as PROCESSING to allow retry
            if ($allThumbnailsExist) {
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::COMPLETED,
                    'thumbnail_error' => null,
                    'metadata' => $currentMetadata,
                ]);
            } else {
                // Phase 3.1E: Some thumbnails are missing after generation
                // This should not happen if upload succeeded, but we verify to prevent false "completed" states
                // Keep as PROCESSING (not FAILED) to allow job retry mechanism to handle transient issues
                Log::error('Thumbnail generation incomplete - some files missing after upload', [
                    'asset_id' => $asset->id,
                    'thumbnail_count' => count($thumbnails),
                ]);
                $asset->update([
                    'thumbnail_status' => ThumbnailStatus::PROCESSING, // Keep as processing to allow retry
                    'thumbnail_error' => 'Some thumbnail files are missing after generation',
                    'metadata' => $currentMetadata,
                ]);
                // Re-throw to trigger job retry mechanism
                // Job will retry up to $tries times, then mark as FAILED in failed() method
                throw new \RuntimeException('Thumbnail files missing after generation');
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
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_COMPLETED,
                    [
                        'styles' => array_keys($thumbnails),
                        'thumbnail_count' => count($thumbnails),
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log thumbnail completed event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Thumbnails generated successfully', [
                'asset_id' => $asset->id,
                'thumbnail_count' => count($thumbnails),
                'styles' => array_keys($thumbnails),
            ]);
        } catch (\Throwable $e) {
            // Capture error but don't fail the entire asset processing pipeline
            $errorMessage = $e->getMessage();
            
            $asset->update([
                'thumbnail_status' => ThumbnailStatus::FAILED,
                'thumbnail_error' => $errorMessage,
            ]);

            // Log thumbnail generation failed (non-blocking)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                    [
                        'error' => $errorMessage,
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

            Log::error('Thumbnail generation failed', [
                'asset_id' => $asset->id,
                'error' => $errorMessage,
                'attempt' => $this->attempts(),
            ]);

            // Re-throw to trigger job retry mechanism
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
            // Update thumbnail status to failed
            $asset->update([
                'thumbnail_status' => ThumbnailStatus::FAILED,
                'thumbnail_error' => $exception->getMessage(),
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
     * Matches frontend logic: only image types that the backend pipeline can actually process.
     * AVIF is excluded because the backend thumbnail pipeline does not yet support it.
     * 
     * @param Asset $asset
     * @return bool True if thumbnail generation is supported
     */
    protected function supportsThumbnailGeneration(Asset $asset): bool
    {
        $mimeType = strtolower($asset->mime_type ?? '');
        $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
        
        // Supported image MIME types (matches frontend THUMBNAIL_SUPPORTED_TYPES)
        $supportedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/tiff',
            'image/tif',
        ];
        
        // Supported extensions (matches frontend THUMBNAIL_SUPPORTED_EXTENSIONS)
        $supportedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'svg',
            'bmp',
            'tiff',
            'tif',
        ];
        
        // AVIF is explicitly excluded (backend doesn't support it yet)
        if ($mimeType === 'image/avif' || $extension === 'avif') {
            return false;
        }
        
        // Check MIME type first
        if ($mimeType && in_array($mimeType, $supportedMimeTypes)) {
            return true;
        }
        
        // Fallback to extension check
        if ($extension && in_array($extension, $supportedExtensions)) {
            return true;
        }
        
        return false;
    }
}
