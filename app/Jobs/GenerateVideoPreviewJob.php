<?php

namespace App\Jobs;

use App\Enums\DerivativeProcessor;
use App\Enums\DerivativeType;
use App\Models\Asset;
use App\Services\AssetDerivativeFailureService;
use App\Services\VideoPreviewGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Generate Video Preview Job
 *
 * Background job that generates hover preview videos for video assets.
 * Creates a short, muted MP4 preview optimized for grid hover interactions.
 *
 * Failures are logged but do not block upload completion.
 */
class GenerateVideoPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2; // Lower retries for preview (non-critical)

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [120, 600]; // 2 minutes, 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(VideoPreviewGenerationService $previewService): void
    {
        Log::info('[GenerateVideoPreviewJob] Job started', [
            'asset_id' => $this->assetId,
        ]);

        try {
            $asset = Asset::findOrFail($this->assetId);
            
            // Log activity: Video preview generation started
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_VIDEO_PREVIEW_STARTED,
                    []
                );
            } catch (\Exception $e) {
                Log::error('Failed to log video preview started event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Check if video_preview_url column exists in the database
            // This handles cases where the migration hasn't been run yet
            if (!Schema::hasColumn('assets', 'video_preview_url')) {
                Log::error('[GenerateVideoPreviewJob] Video preview column missing', [
                    'asset_id' => $asset->id,
                    'message' => 'video_preview_url column does not exist in assets table. Please run migrations.',
                ]);
                // Don't throw - preview generation failure should not block upload completion
                return;
            }

            // Check if asset is a video
            $fileTypeService = app(\App\Services\FileTypeService::class);
            $fileType = $fileTypeService->detectFileTypeFromAsset($asset);

            if ($fileType !== 'video') {
                Log::info('[GenerateVideoPreviewJob] Skipping - asset is not a video', [
                    'asset_id' => $asset->id,
                    'file_type' => $fileType,
                ]);
                
                // Log activity: Video preview skipped (not a video)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_VIDEO_PREVIEW_SKIPPED,
                        [
                            'reason' => 'not_a_video',
                            'file_type' => $fileType,
                        ]
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to log video preview skipped event', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                return;
            }

            // Idempotency: Check if preview already generated
            // CRITICAL: Check raw database value, not accessor (accessor generates presigned URL which may fail)
            // The accessor can return null even if a preview path exists (if S3 URL generation fails)
            $rawPreviewUrl = $asset->getAttributes()['video_preview_url'] ?? null;
            if ($rawPreviewUrl) {
                Log::info('[GenerateVideoPreviewJob] Preview already generated', [
                    'asset_id' => $asset->id,
                    'preview_path' => $rawPreviewUrl,
                ]);
                
                // Log activity: Video preview skipped (already generated)
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_VIDEO_PREVIEW_SKIPPED,
                        [
                            'reason' => 'already_generated',
                        ]
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to log video preview skipped event', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                return;
            }

            // Generate preview
            $previewPath = $previewService->generatePreview($asset);

            // Verify preview file exists in S3 before marking as complete
            $bucket = $asset->storageBucket;
            $s3Client = $this->createS3Client();
            
            try {
                $result = $s3Client->headObject([
                    'Bucket' => $bucket->name,
                    'Key' => $previewPath,
                ]);
                
                $fileSize = $result['ContentLength'] ?? 0;
                if ($fileSize < 1000) { // Minimum 1KB for valid video file
                    throw new \RuntimeException("Preview file too small (likely corrupted): {$fileSize} bytes");
                }
                
                Log::info('[GenerateVideoPreviewJob] Preview file verified in S3', [
                    'asset_id' => $asset->id,
                    'preview_path' => $previewPath,
                    'file_size' => $fileSize,
                ]);
            } catch (\Aws\S3\Exception\S3Exception $e) {
                if ($e->getStatusCode() === 404) {
                    throw new \RuntimeException("Preview file not found in S3 after generation: {$previewPath}");
                }
                throw new \RuntimeException("Failed to verify preview file in S3: {$e->getMessage()}", 0, $e);
            }

            // Update asset with preview path (stored as S3 key, not signed URL)
            // Signed URLs will be generated on-demand in the frontend/API
            $asset->update([
                'video_preview_url' => $previewPath, // Store S3 key path
            ]);

            Log::info('[GenerateVideoPreviewJob] Preview generated and verified successfully', [
                'asset_id' => $asset->id,
                'preview_path' => $previewPath,
            ]);
            
            // Log activity: Video preview generation completed
            // IMPORTANT: Log completion event AFTER successful generation and update
            // This ensures the timeline shows completion even if there are subsequent errors
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_VIDEO_PREVIEW_COMPLETED,
                    [
                        'preview_path' => $previewPath,
                    ]
                );
                
                Log::info('[GenerateVideoPreviewJob] Video preview completed event logged', [
                    'asset_id' => $asset->id,
                ]);
            } catch (\Exception $e) {
                // Log error but don't fail the job - preview was successfully generated
                Log::error('[GenerateVideoPreviewJob] Failed to log video preview completed event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                    'note' => 'Preview was generated successfully, but activity event logging failed',
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database errors (like missing column)
            $errorMessage = $e->getMessage();
            
            // Check if error is about missing column
            if (str_contains($errorMessage, "Unknown column 'video_preview_url'")) {
                Log::error('[GenerateVideoPreviewJob] Video preview column missing (QueryException)', [
                    'asset_id' => $this->assetId,
                    'error' => $errorMessage,
                    'message' => 'video_preview_url column does not exist in assets table. Please run migrations.',
                ]);
            // Phase T-1: Record derivative failure for observability
            $asset = Asset::find($this->assetId);
            if ($asset) {
                try {
                    app(AssetDerivativeFailureService::class)->recordFailure(
                        $asset,
                        DerivativeType::PREVIEW,
                        DerivativeProcessor::FFMPEG,
                        $e,
                        'schema_error'
                    );
                } catch (\Throwable $t1Ex) {
                    Log::warning('[GenerateVideoPreviewJob] AssetDerivativeFailureService recording failed', ['error' => $t1Ex->getMessage()]);
                }
            }

            // Don't throw - preview generation failure should not block upload completion
            return;
        }

        // Other database errors
        Log::error('[GenerateVideoPreviewJob] Job failed with database exception', [
                'asset_id' => $this->assetId,
                'exception' => get_class($e),
                'message' => $errorMessage,
            ]);

            // Don't throw - preview generation failure should not block upload completion
            $asset = Asset::find($this->assetId);
            if ($asset) {
                Log::warning('[GenerateVideoPreviewJob] Preview generation failed (non-fatal)', [
                    'asset_id' => $asset->id,
                    'error' => $errorMessage,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[GenerateVideoPreviewJob] Job failed with exception', [
                'asset_id' => $this->assetId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            // Don't throw - preview generation failure should not block upload completion
            // Just log the error
            $asset = Asset::find($this->assetId);
            if ($asset) {
                Log::warning('[GenerateVideoPreviewJob] Preview generation failed (non-fatal)', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
                
                // Log activity: Video preview generation failed
                try {
                    \App\Services\ActivityRecorder::logAsset(
                        $asset,
                        \App\Enums\EventType::ASSET_VIDEO_PREVIEW_FAILED,
                        [
                            'error' => $e->getMessage(),
                            'exception' => get_class($e),
                        ]
                    );
                } catch (\Exception $logException) {
                    Log::error('Failed to log video preview failed event', [
                        'asset_id' => $asset->id,
                        'error' => $logException->getMessage(),
                    ]);
                }

                // Phase T-1: Record derivative failure for observability (never affects Asset.status)
                try {
                    $mime = $asset->metadata['mime_type'] ?? $asset->mime_type ?? null;
                    app(AssetDerivativeFailureService::class)->recordFailure(
                        $asset,
                        DerivativeType::PREVIEW,
                        DerivativeProcessor::FFMPEG,
                        $e,
                        null,
                        null,
                        $mime
                    );
                } catch (\Throwable $t1Ex) {
                    Log::warning('[GenerateVideoPreviewJob] AssetDerivativeFailureService recording failed', [
                        'asset_id' => $asset->id,
                        'error' => $t1Ex->getMessage(),
                    ]);
                }
            }

            // Don't re-throw - allow job to complete silently
            // Preview generation is a nice-to-have feature
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            Log::warning('[GenerateVideoPreviewJob] Preview generation failed after all retries (non-fatal)', [
                'asset_id' => $asset->id,
                'error' => $exception->getMessage(),
                'exception_class' => get_class($exception),
            ]);

            // Phase T-1: Record derivative failure for observability (never affects Asset.status)
            try {
                $mime = $asset->metadata['mime_type'] ?? $asset->mime_type ?? null;
                app(AssetDerivativeFailureService::class)->recordFailure(
                    $asset,
                    DerivativeType::PREVIEW,
                    DerivativeProcessor::FFMPEG,
                    $exception,
                    null,
                    null,
                    $mime
                );
            } catch (\Throwable $t1Ex) {
                Log::warning('[GenerateVideoPreviewJob] AssetDerivativeFailureService recording failed in failed()', [
                    'asset_id' => $asset->id,
                    'error' => $t1Ex->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create S3 client instance for file verification.
     *
     * @return \Aws\S3\S3Client
     */
    protected function createS3Client(): \Aws\S3\S3Client
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
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

        return new \Aws\S3\S3Client($config);
    }
}
