<?php

namespace App\Services;

use App\Enums\ThumbnailStatus;
use App\Jobs\RetryThumbnailGenerationJob;
use App\Models\Asset;
use App\Services\TenantBucketService;
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
     * - Manual retry limit has not been exceeded (unless $enforceManualRetryLimit is false)
     * - Asset is not currently processing
     * - Asset has storage path and bucket
     *
     * @param  bool  $enforceManualRetryLimit  When false, skips the configured manual retry cap so
     *                                         system dispatches (user id 0) do not consume the user's manual quota.
     * @return array{allowed: bool, reason?: string}
     */
    public function canRetry(Asset $asset, bool $enforceManualRetryLimit = true): array
    {
        // Check if file type is supported
        if (!$this->isFileTypeSupported($asset)) {
            return [
                'allowed' => false,
                'reason' => 'Thumbnail generation is not supported for this file type',
            ];
        }

        if ($enforceManualRetryLimit) {
            $maxRetries = config('assets.thumbnail.max_retries', 3);
            if ($asset->thumbnail_retry_count >= $maxRetries) {
                return [
                    'allowed' => false,
                    'reason' => "Maximum retry attempts ({$maxRetries}) exceeded for this asset",
                ];
            }
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
     * Whether admin may dispatch {@see \App\Jobs\ProcessAssetJob} (full pipeline retry).
     *
     * Unlike {@see canRetry()}, this does not require FFmpeg/Imagick in the **web** PHP process.
     * Video and other types are validated against the file-type registry only; workers run the
     * actual tools. Prevents false "Thumbnail generation is not supported for this file type" on
     * app nodes where only queue workers have FFmpeg.
     *
     * @return array{allowed: bool, reason?: string}
     */
    public function canAdminDispatchFullPipeline(Asset $asset): array
    {
        if ($asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
            return [
                'allowed' => false,
                'reason' => 'Thumbnail generation is already in progress',
            ];
        }

        if (! is_string($asset->storage_root_path) || $asset->storage_root_path === '') {
            return [
                'allowed' => false,
                'reason' => 'Asset storage path is missing',
            ];
        }

        $asset->loadMissing('storageBucket', 'tenant');

        $hasExplicitBucket = $asset->storage_bucket_id !== null
            && (int) $asset->storage_bucket_id > 0
            && $asset->storageBucket !== null;
        if (! $hasExplicitBucket) {
            if ($asset->tenant === null) {
                return ['allowed' => false, 'reason' => 'Asset storage information is missing'];
            }
            try {
                app(TenantBucketService::class)->resolveActiveBucketOrFail($asset->tenant);
            } catch (\Throwable) {
                return ['allowed' => false, 'reason' => 'Asset storage information is missing'];
            }
        }

        $fileTypeService = app(FileTypeService::class);
        $mime = $asset->mime_type;
        $ext = strtolower((string) pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
        $unsupported = $fileTypeService->getUnsupportedReason($mime, $ext);
        if ($unsupported !== null) {
            return [
                'allowed' => false,
                'reason' => $unsupported['message'] ?? 'This file type cannot be processed by the pipeline.',
            ];
        }

        if ($fileTypeService->detectFileTypeFromAsset($asset) === null) {
            return [
                'allowed' => false,
                'reason' => 'Could not determine file type (missing or unknown MIME type / extension).',
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
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        
        if (!$fileType) {
            return false;
        }
        
        // Check if requirements are met
        $requirements = $fileTypeService->checkRequirements($fileType);
        if (!$requirements['met']) {
            return false;
        }
        
        return $fileTypeService->supportsCapability($fileType, 'thumbnail');
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
        $countsTowardManualLimit = $userId > 0;

        // Validate retry eligibility (system dispatches skip manual attempt cap)
        $canRetry = $this->canRetry($asset, $countsTowardManualLimit);
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
        
        // Clear old skip reasons for formats that are now supported (e.g., TIFF/AVIF)
        // This allows previously skipped assets to be regenerated
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
                      ($mimeType === 'image/heic' || $mimeType === 'image/heif' ||
                          $extension === 'heic' || $extension === 'heif') &&
                      extension_loaded('imagick')) {
                $isNowSupported = true;
            } elseif ($skipReason === 'unsupported_format:cr2' &&
                      ($mimeType === 'image/x-canon-cr2' || $extension === 'cr2') &&
                      extension_loaded('imagick')) {
                $isNowSupported = true;
            } elseif ($skipReason === 'unsupported_format:svg' &&
                      ($mimeType === 'image/svg+xml' || $extension === 'svg')) {
                $isNowSupported = true;
            } elseif ($skipReason === 'dimensions_unknown' &&
                      ($mimeType === 'image/svg+xml' || $extension === 'svg')) {
                $isNowSupported = true;
            }
            
            if ($isNowSupported) {
                unset($metadata['thumbnail_skip_reason']);
                Log::info('[ThumbnailRetryService] Cleared old skip reason during retry', [
                    'asset_id' => $asset->id,
                    'old_skip_reason' => $skipReason,
                ]);
            }
        }
        
        $previousStatus = $asset->thumbnail_status?->value ?? 'unknown';
        $retries = $metadata['thumbnail_retries'] ?? [];
        $retries[] = [
            'attempted_at' => now()->toIso8601String(),
            'triggered_by_user_id' => $userId,
            'previous_status' => $previousStatus,
            'retry_number' => $countsTowardManualLimit ? ($asset->thumbnail_retry_count + 1) : null,
            'system_dispatch' => ! $countsTowardManualLimit,
        ];
        $metadata['thumbnail_retries'] = $retries;

        // Manual UI retries increment thumbnail_retry_count; system nudges (user id 0) do not.
        $updateData = [
            'metadata' => $metadata,
        ];
        if ($countsTowardManualLimit) {
            $updateData['thumbnail_retry_count'] = $asset->thumbnail_retry_count + 1;
            $updateData['thumbnail_last_retry_at'] = now();
        }

        // Reset SKIPPED or FAILED status to PENDING to allow regeneration
        if ($asset->thumbnail_status === ThumbnailStatus::SKIPPED || $asset->thumbnail_status === ThumbnailStatus::FAILED) {
            $updateData['thumbnail_status'] = ThumbnailStatus::PENDING;
            $updateData['thumbnail_error'] = null;
            unset($metadata['thumbnail_timeout'], $metadata['thumbnail_timeout_at'], $metadata['thumbnail_timeout_reason']);
            $updateData['metadata'] = $metadata;
        }

        $jobRetryNumber = $countsTowardManualLimit ? ($asset->thumbnail_retry_count + 1) : 0;

        $asset->update($updateData);

        RetryThumbnailGenerationJob::dispatch($asset->id, $userId, $jobRetryNumber)->onQueue(config('queue.images_queue', 'images'));

        $asset->refresh();

        Log::info('[ThumbnailRetryService] Thumbnail retry dispatched', [
            'asset_id' => $asset->id,
            'user_id' => $userId,
            'retry_count' => $asset->thumbnail_retry_count,
            'previous_status' => $previousStatus,
            'retry_number' => $jobRetryNumber,
            'counts_toward_manual_limit' => $countsTowardManualLimit,
        ]);

        return [
            'success' => true,
            'job_id' => null, // Job ID not available at dispatch time
        ];
    }
}
