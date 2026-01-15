<?php

namespace App\Services;

use App\Enums\ThumbnailStatus;
use App\Jobs\RetryThumbnailGenerationJob;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Thumbnail Retry Service
 *
 * Handles manual thumbnail retry requests from the asset drawer UI.
 * This service validates retry eligibility, enforces retry limits, and dispatches
 * the existing GenerateThumbnailsJob without modifying it.
 *
 * IMPORTANT: This feature respects the locked thumbnail pipeline:
 * - Does not modify existing GenerateThumbnailsJob
 * - Does not mutate Asset.status (status represents visibility only)
 * - Retry attempts are tracked for audit purposes
 * - Uses existing file type validation logic
 */
class ThumbnailRetryService
{
    /**
     * Check if thumbnail retry is allowed for an asset.
     *
     * Validates:
     * - File type is supported for thumbnail generation
     * - Retry limit has not been exceeded
     * - Asset is not currently processing
     * - Asset has storage path and bucket
     *
     * @param Asset $asset
     * @return array{allowed: bool, reason?: string}
     */
    public function canRetry(Asset $asset): array
    {
        // Check if file type is supported
        if (!$this->isFileTypeSupported($asset)) {
            return [
                'allowed' => false,
                'reason' => 'Thumbnail generation is not supported for this file type',
            ];
        }

        // Check if retry limit has been exceeded
        $maxRetries = config('assets.thumbnail.max_retries', 3);
        if ($asset->thumbnail_retry_count >= $maxRetries) {
            return [
                'allowed' => false,
                'reason' => "Maximum retry attempts ({$maxRetries}) exceeded for this asset",
            ];
        }

        // Check if asset is already processing
        if ($asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
            return [
                'allowed' => false,
                'reason' => 'Thumbnail generation is already in progress',
            ];
        }

        // Check if asset has required storage information
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            return [
                'allowed' => false,
                'reason' => 'Asset storage information is missing',
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Check if file type supports thumbnail generation.
     *
     * Reuses the same validation logic as GenerateThumbnailsJob to ensure consistency.
     * This prevents retry attempts on file types that cannot be processed.
     * 
     * IMPORTANT: PDF support is additive - matches GenerateThumbnailsJob logic exactly.
     *
     * @param Asset $asset
     * @return bool
     */
    public function isFileTypeSupported(Asset $asset): bool
    {
        $mimeType = strtolower($asset->mime_type ?? '');
        $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));

        // PDF support - first-class supported type (matches GenerateThumbnailsJob)
        // Uses spatie/pdf-to-image with ImageMagick/Ghostscript backend
        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            // Verify spatie/pdf-to-image package is available
            if (!class_exists(\Spatie\PdfToImage\Pdf::class)) {
                return false;
            }
            return true;
        }

        // Supported image MIME types - ONLY formats that GD library can actually process
        // This matches GenerateThumbnailsJob::supportsThumbnailGeneration()
        $supportedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
        ];

        // Supported extensions
        $supportedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
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

    /**
     * Dispatch thumbnail retry job for an asset.
     *
     * This method:
     * 1. Validates retry eligibility
     * 2. Records retry attempt in metadata
     * 3. Increments retry count
     * 4. Dispatches existing GenerateThumbnailsJob (unchanged)
     * 5. Logs retry attempt for audit trail
     *
     * IMPORTANT: Does not modify Asset.status (status represents visibility only).
     * The existing GenerateThumbnailsJob handles thumbnail_status updates.
     *
     * @param Asset $asset
     * @param int $userId User ID who triggered the retry
     * @return array{success: bool, job_id?: string, error?: string}
     */
    public function dispatchRetry(Asset $asset, int $userId): array
    {
        // Validate retry eligibility
        $canRetry = $this->canRetry($asset);
        if (!$canRetry['allowed']) {
            Log::warning('[ThumbnailRetryService] Retry not allowed', [
                'asset_id' => $asset->id,
                'user_id' => $userId,
                'reason' => $canRetry['reason'] ?? 'unknown',
                'retry_count' => $asset->thumbnail_retry_count,
            ]);

            return [
                'success' => false,
                'error' => $canRetry['reason'] ?? 'Retry not allowed',
            ];
        }

        // Record retry attempt in metadata (append-only audit log)
        $metadata = $asset->metadata ?? [];
        $retries = $metadata['thumbnail_retries'] ?? [];
        $retries[] = [
            'attempted_at' => now()->toIso8601String(),
            'triggered_by_user_id' => $userId,
            'previous_status' => $asset->thumbnail_status?->value ?? 'unknown',
            'retry_number' => $asset->thumbnail_retry_count + 1,
        ];
        $metadata['thumbnail_retries'] = $retries;

        // Update retry count and metadata
        // IMPORTANT: We do NOT mutate Asset.status here - status represents visibility only
        $asset->update([
            'thumbnail_retry_count' => $asset->thumbnail_retry_count + 1,
            'thumbnail_last_retry_at' => now(),
            'metadata' => $metadata,
        ]);

        // Dispatch RetryThumbnailGenerationJob which wraps GenerateThumbnailsJob
        // This respects the locked pipeline by not modifying GenerateThumbnailsJob
        $retryNumber = $asset->thumbnail_retry_count + 1;
        RetryThumbnailGenerationJob::dispatch($asset->id, $userId, $retryNumber);

        // Note: Job ID is not available immediately after dispatch
        // The job ID will be available inside the job execution via $this->job->getJobId()
        // For now, we'll track retries without the job ID in metadata
        $asset->update(['metadata' => $metadata]);

        // Log retry attempt for audit trail
        Log::info('[ThumbnailRetryService] Thumbnail retry dispatched', [
            'asset_id' => $asset->id,
            'user_id' => $userId,
            'retry_count' => $asset->thumbnail_retry_count,
            'previous_status' => $asset->thumbnail_status?->value ?? 'unknown',
            'retry_number' => $retryNumber,
        ]);

        return [
            'success' => true,
            'job_id' => null, // Job ID not available at dispatch time
        ];
    }
}
