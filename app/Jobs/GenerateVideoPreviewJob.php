<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\AssetProcessingFailureService;
use App\Services\VideoPreviewGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

            // Check if asset is a video
            $fileTypeService = app(\App\Services\FileTypeService::class);
            $fileType = $fileTypeService->detectFileTypeFromAsset($asset);

            if ($fileType !== 'video') {
                Log::info('[GenerateVideoPreviewJob] Skipping - asset is not a video', [
                    'asset_id' => $asset->id,
                    'file_type' => $fileType,
                ]);
                return;
            }

            // Idempotency: Check if preview already generated
            if ($asset->video_preview_url) {
                Log::info('[GenerateVideoPreviewJob] Preview already generated', [
                    'asset_id' => $asset->id,
                    'preview_url' => $asset->video_preview_url,
                ]);
                return;
            }

            // Generate preview
            $previewPath = $previewService->generatePreview($asset);

            // Update asset with preview path (stored as S3 key, not signed URL)
            // Signed URLs will be generated on-demand in the frontend/API
            $asset->update([
                'video_preview_url' => $previewPath, // Store S3 key path
            ]);

            Log::info('[GenerateVideoPreviewJob] Preview generated successfully', [
                'asset_id' => $asset->id,
                'preview_path' => $previewPath,
            ]);
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
            ]);

            // Don't record as processing failure - preview is non-critical
            // Asset upload and processing can complete successfully without preview
        }
    }
}
