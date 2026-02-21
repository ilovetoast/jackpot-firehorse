<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetPathGenerator;
use App\Services\AssetProcessingFailureService;
use App\Services\Reliability\ReliabilityEngine;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Promote Asset Job
 *
 * Moves finalized assets from temporary upload storage to their canonical, permanent S3 location.
 *
 * Why temp storage exists:
 * - Uploads land in temp/ during the upload process for isolation and safety
 * - Allows upload verification before committing to permanent storage
 * - Enables cleanup of failed/incomplete uploads without affecting assets
 *
 * Why promotion is delayed:
 * - Promotion only happens after asset processing is complete (thumbnails, metadata, etc.)
 * - Ensures asset is fully validated before moving to permanent location
 * - Allows for rollback if processing fails
 *
 * Why promotion is a separate job:
 * - Separates concerns: processing vs. storage organization
 * - Allows for retry logic specific to promotion failures
 * - Enables observability and monitoring of promotion separately
 * - Supports enterprise reliability: can retry promotion without reprocessing
 *
 * This job:
 * - Moves original file from temp/{upload_session_id}/original to tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}
 * - Moves thumbnails to canonical location
 * - Updates asset.storage_root_path to canonical path
 * - Cleans up empty temp folders
 * - Is idempotent: safe to re-run if already promoted
 *
 * Enterprise-grade reliability:
 * - Never deletes temp files until canonical move succeeds
 * - Thumbnail promotion failures don't block original promotion
 * - Missing files are logged but don't crash the queue
 * - Asset remains usable even if promotion partially fails
 */
class PromoteAssetJob implements ShouldQueue
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
     * S3 client instance.
     */
    protected ?S3Client $s3Client = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Execute the job.
     *
     * Promotes asset from temporary storage to canonical permanent location.
     * Safe to re-run if already promoted (idempotent).
     *
     * Promotion runs when:
     * - Asset status is COMPLETED (after FinalizeAssetJob)
     * - Asset type is 'asset' or 'deliverable'
     * - Asset is not already promoted (idempotency check)
     *
     * This job is chained after FinalizeAssetJob in the processing pipeline,
     * ensuring promotion happens after all processing is complete, including
     * thumbnail generation. The job is idempotent and safe to re-run.
     */
    public function handle(): void
    {
        $asset = Asset::findOrFail($this->assetId);
        \App\Services\UploadDiagnosticLogger::jobStart('PromoteAssetJob', $asset->id);

        // Only promote assets with completed processing pipeline
        // Check processing completion state, not status (status is visibility only)
        $completionService = app(\App\Services\AssetCompletionService::class);
        if (!$completionService->isComplete($asset)) {
            Log::debug('Asset promotion skipped - asset processing not completed yet', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            \App\Services\UploadDiagnosticLogger::jobSkip('PromoteAssetJob', $asset->id, 'processing_not_complete');
            return;
        }

        // Only promote asset and deliverable types
        if (!in_array($asset->type->value, ['asset', 'deliverable'])) {
            Log::info('Asset promotion skipped - unsupported asset type', [
                'asset_id' => $asset->id,
                'type' => $asset->type->value,
            ]);
            return;
        }

        // Idempotency: Check if already promoted
        // Canonical path pattern: tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}
        if ($this->isAlreadyPromoted($asset)) {
            Log::info('Asset promotion skipped - already promoted', [
                'asset_id' => $asset->id,
                'storage_root_path' => $asset->storage_root_path,
            ]);
            \App\Services\UploadDiagnosticLogger::jobSkip('PromoteAssetJob', $asset->id, 'already_promoted');
            return;
        }

        if (!$asset->storageBucket) {
            Log::error('Asset promotion failed - missing storage bucket', [
                'asset_id' => $asset->id,
            ]);
            $this->markPromotionFailed($asset, 'Missing storage bucket');
            return;
        }

        if (!$asset->storage_root_path) {
            Log::error('Asset promotion failed - missing storage root path', [
                'asset_id' => $asset->id,
            ]);
            $this->markPromotionFailed($asset, 'Missing storage root path');
            return;
        }

        $bucket = $asset->storageBucket;
        $s3Client = $this->getS3Client();

        // Determine source and destination paths
        $sourcePath = $asset->storage_root_path;
        $pathGenerator = app(AssetPathGenerator::class);
        $version = $asset->currentVersion?->version_number ?? 1;
        $extension = $this->extractExtension($asset);
        $canonicalPath = $pathGenerator->generateOriginalPath($asset->tenant, $asset, $version, $extension);

        // Double-check: if source path is not in temp/, skip promotion
        // (This should have been caught by isAlreadyPromoted, but extra safety check)
        if (!str_starts_with($sourcePath, 'temp/')) {
            Log::debug('Asset promotion skipped - source path is not in temp/ (extra safety check)', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath,
            ]);
            return;
        }

        // Verify source file exists before attempting move
        if (!$s3Client->doesObjectExist($bucket->name, $sourcePath)) {
            Log::error('Asset promotion failed - source file not found in S3', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath,
                'bucket' => $bucket->name,
            ]);
            $this->markPromotionFailed($asset, 'Source file not found in S3');
            return;
        }

        try {
            // Move original file to canonical location
            $this->moveObject($s3Client, $bucket->name, $sourcePath, $canonicalPath);

            // Move thumbnails to canonical location
            $thumbnailMoves = $this->moveThumbnails($s3Client, $bucket, $asset, $sourcePath, $canonicalPath);

            // Update asset record with canonical path
            $asset->update([
                'storage_root_path' => $canonicalPath,
            ]);

            // Update thumbnail paths in metadata
            $this->updateThumbnailPathsInMetadata($asset, $canonicalPath, $thumbnailMoves);

            // Clean up empty temp folder
            $this->cleanupTempFolder($s3Client, $bucket, $asset->upload_session_id);

            // Log success
            Log::info('Asset promoted successfully', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath,
                'canonical_path' => $canonicalPath,
                'thumbnails_moved' => count($thumbnailMoves),
            ]);
            \App\Services\UploadDiagnosticLogger::jobComplete('PromoteAssetJob', $asset->id, [
                'canonical_path' => $canonicalPath,
            ]);

            // Emit promotion event
            AssetEvent::create([
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'asset_id' => $asset->id,
                'user_id' => null,
                'event_type' => 'asset.promoted',
                'metadata' => [
                    'job' => 'PromoteAssetJob',
                    'canonical_path' => $canonicalPath,
                    'thumbnails_moved' => count($thumbnailMoves),
                ],
                'created_at' => now(),
            ]);

            // Log asset promotion (non-blocking)
            try {
                \App\Services\ActivityRecorder::logAsset(
                    $asset,
                    \App\Enums\EventType::ASSET_PROMOTED,
                    [
                        'from' => $sourcePath,
                        'to' => $canonicalPath,
                        'upload_session_id' => $asset->upload_session_id,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log asset promoted event', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // P1: Invoke reconciliation at end of chain (last job)
            try {
                $result = app(\App\Services\Assets\AssetStateReconciliationService::class)->reconcile($asset->fresh());
                if ($result['updated']) {
                    Log::info('[PromoteAssetJob] Reconciliation applied', [
                        'asset_id' => $asset->id,
                        'changes' => $result['changes'],
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[PromoteAssetJob] Reconciliation failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Asset promotion failed', [
                'asset_id' => $asset->id,
                'source_path' => $sourcePath ?? null,
                'canonical_path' => $canonicalPath ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->markPromotionFailed($asset, $e->getMessage());
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Check if asset is already promoted (idempotency check).
     *
     * An asset is considered "already promoted" if:
     * 1. It's in the canonical format: tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}
     * 2. It's NOT in temp/ location (temp/uploads/{upload_session_id}/original)
     *
     * Legacy paths (assets/{tenant_id}/...) are deprecated and no longer written.
     *
     * @param Asset $asset
     * @return bool
     */
    protected function isAlreadyPromoted(Asset $asset): bool
    {
        $path = $asset->storage_root_path;

        if (!$path) {
            return false;
        }

        // If path is in temp/, it's not promoted yet
        if (str_starts_with($path, 'temp/')) {
            return false;
        }

        // Canonical pattern: tenants/{uuid}/assets/{uuid}/v{n}/original.{ext}
        if ($asset->tenant?->uuid && str_starts_with($path, "tenants/{$asset->tenant->uuid}/assets/{$asset->id}/v")) {
            return true;
        }

        // Legacy: assets/{tenant_id}/... or assets/{asset_id}/v{n}/... — consider promoted (no re-promotion)
        if (str_starts_with($path, 'assets/')) {
            return true;
        }

        return false;
    }

    /**
     * Extract file extension from asset.
     */
    protected function extractExtension(Asset $asset): string
    {
        if ($asset->original_filename) {
            $ext = pathinfo($asset->original_filename, PATHINFO_EXTENSION);
            if ($ext) {
                return strtolower($ext);
            }
        }
        if ($asset->storage_root_path) {
            $ext = pathinfo($asset->storage_root_path, PATHINFO_EXTENSION);
            if ($ext) {
                return strtolower($ext);
            }
        }
        return 'file';
    }

    /**
     * Move object in S3 (copy + delete).
     *
     * S3 doesn't have a native "move" operation, so we copy then delete.
     * This is atomic from the application's perspective.
     *
     * @param S3Client $s3Client
     * @param string $bucketName
     * @param string $sourceKey
     * @param string $destinationKey
     * @throws \RuntimeException If move fails
     */
    protected function moveObject(S3Client $s3Client, string $bucketName, string $sourceKey, string $destinationKey): void
    {
        try {
            // Copy object to destination
            $s3Client->copyObject([
                'Bucket' => $bucketName,
                'CopySource' => "{$bucketName}/{$sourceKey}",
                'Key' => $destinationKey,
            ]);

            // Verify copy succeeded
            if (!$s3Client->doesObjectExist($bucketName, $destinationKey)) {
                throw new \RuntimeException("Copy verification failed: destination object not found");
            }

            // Delete source object
            $s3Client->deleteObject([
                'Bucket' => $bucketName,
                'Key' => $sourceKey,
            ]);

            Log::debug('Object moved in S3', [
                'source' => $sourceKey,
                'destination' => $destinationKey,
            ]);
        } catch (S3Exception $e) {
            Log::error('Failed to move object in S3', [
                'source' => $sourceKey,
                'destination' => $destinationKey,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to move object in S3: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Move thumbnails to canonical location.
     *
     * Thumbnails are stored at: tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/thumbnails/{style}/{filename}
     *
     * Thumbnail promotion failures do NOT block original promotion.
     *
     * @param S3Client $s3Client
     * @param \App\Models\StorageBucket $bucket
     * @param Asset $asset
     * @param string $oldAssetPath
     * @param string $newAssetPath
     * @return array Array of successfully moved thumbnail paths [old_path => new_path]
     */
    protected function moveThumbnails(
        S3Client $s3Client,
        \App\Models\StorageBucket $bucket,
        Asset $asset,
        string $oldAssetPath,
        string $newAssetPath
    ): array {
        $moved = [];
        $metadata = $asset->metadata ?? [];
        $thumbnails = $metadata['thumbnails'] ?? [];

        if (empty($thumbnails)) {
            Log::debug('No thumbnails to move', [
                'asset_id' => $asset->id,
            ]);
            return $moved;
        }

        $version = $asset->currentVersion?->version_number ?? 1;
        $pathGenerator = app(AssetPathGenerator::class);
        $tenant = $asset->tenant;

        foreach ($thumbnails as $style => $thumbnailInfo) {
            $oldThumbnailPath = $thumbnailInfo['path'] ?? null;

            if (!$oldThumbnailPath) {
                continue;
            }

            $thumbnailFilename = basename($oldThumbnailPath);
            $newThumbnailPath = $pathGenerator->generateThumbnailPath($tenant, $asset, $version, $style, $thumbnailFilename);

            // Skip if thumbnail is already in canonical location
            if ($oldThumbnailPath === $newThumbnailPath || str_starts_with($oldThumbnailPath, dirname($newAssetPath))) {
                Log::debug('Thumbnail already in canonical location', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'path' => $oldThumbnailPath,
                ]);
                continue;
            }

            try {
                // Check if old thumbnail exists
                if (!$s3Client->doesObjectExist($bucket->name, $oldThumbnailPath)) {
                    Log::warning('Thumbnail not found in S3, skipping move', [
                        'asset_id' => $asset->id,
                        'style' => $style,
                        'old_path' => $oldThumbnailPath,
                    ]);
                    continue;
                }

                // Move thumbnail
                $this->moveObject($s3Client, $bucket->name, $oldThumbnailPath, $newThumbnailPath);
                
                $moved[$oldThumbnailPath] = $newThumbnailPath;

                Log::debug('Thumbnail moved successfully', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'old_path' => $oldThumbnailPath,
                    'new_path' => $newThumbnailPath,
                ]);
            } catch (\Exception $e) {
                // Log error but don't block promotion
                Log::warning('Failed to move thumbnail, continuing promotion', [
                    'asset_id' => $asset->id,
                    'style' => $style,
                    'old_path' => $oldThumbnailPath,
                    'new_path' => $newThumbnailPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $moved;
    }

    /**
     * Update thumbnail paths in asset metadata.
     *
     * @param Asset $asset
     * @param string $newAssetPath
     * @param array $thumbnailMoves Map of old_path => new_path
     */
    protected function updateThumbnailPathsInMetadata(Asset $asset, string $newAssetPath, array $thumbnailMoves): void
    {
        if (empty($thumbnailMoves)) {
            return;
        }

        $metadata = $asset->metadata ?? [];
        $thumbnails = $metadata['thumbnails'] ?? [];

        foreach ($thumbnails as $style => &$thumbnailInfo) {
            $oldPath = $thumbnailInfo['path'] ?? null;
            if ($oldPath && isset($thumbnailMoves[$oldPath])) {
                $thumbnailInfo['path'] = $thumbnailMoves[$oldPath];
            }
        }

        $metadata['thumbnails'] = $thumbnails;
        $asset->update(['metadata' => $metadata]);
    }

    /**
     * Clean up empty temp folder after promotion.
     *
     * Removes temp/{upload_session_id}/ folder if it's empty.
     * Only deletes if all files have been moved.
     *
     * @param S3Client $s3Client
     * @param \App\Models\StorageBucket $bucket
     * @param string $uploadSessionId
     */
    protected function cleanupTempFolder(S3Client $s3Client, \App\Models\StorageBucket $bucket, string $uploadSessionId): void
    {
        $tempFolderPrefix = "temp/uploads/{$uploadSessionId}/";

        try {
            // List all objects in temp folder
            $result = $s3Client->listObjectsV2([
                'Bucket' => $bucket->name,
                'Prefix' => $tempFolderPrefix,
            ]);

            $objects = $result['Contents'] ?? [];

            // If folder is empty, nothing to clean up
            if (empty($objects)) {
                Log::debug('Temp folder already empty, nothing to clean up', [
                    'upload_session_id' => $uploadSessionId,
                    'prefix' => $tempFolderPrefix,
                ]);
                return;
            }

            // Folder still has files - don't delete (may be other files or in-progress uploads)
            Log::debug('Temp folder not empty, skipping cleanup', [
                'upload_session_id' => $uploadSessionId,
                'prefix' => $tempFolderPrefix,
                'object_count' => count($objects),
            ]);
        } catch (S3Exception $e) {
            // Log but don't fail promotion if cleanup check fails
            Log::warning('Failed to check temp folder for cleanup', [
                'upload_session_id' => $uploadSessionId,
                'prefix' => $tempFolderPrefix,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark asset promotion as failed.
     *
     * @param Asset $asset
     * @param string $errorMessage
     */
    protected function markPromotionFailed(Asset $asset, string $errorMessage): void
    {
        $metadata = $asset->metadata ?? [];
        $metadata['promotion_failed'] = true;
        $metadata['promotion_failed_at'] = now()->toIso8601String();
        $metadata['promotion_error'] = $errorMessage;

        $asset->update([
            'metadata' => $metadata,
            'analysis_status' => 'promotion_failed',
        ]);

        app(ReliabilityEngine::class)->report([
            'source_type' => 'asset',
            'source_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'severity' => 'error',
            'title' => 'Asset promotion failed',
            'message' => $errorMessage,
            'retryable' => true,
            'requires_support' => false,
            'metadata' => [
                'analysis_status' => $asset->analysis_status,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? null,
            ],
        ]);

        Log::error('Asset promotion marked as failed', [
            'asset_id' => $asset->id,
            'error' => $errorMessage,
        ]);
    }

    /**
     * Get or create S3 client instance.
     *
     * @return S3Client
     */
    protected function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            if (!class_exists(S3Client::class)) {
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

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Use centralized failure recording service for observability
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts(),
                true // preserveVisibility: uploaded assets must never disappear from grid
            );

            Log::error('Asset promotion job failed after all retries', [
                'asset_id' => $asset->id,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);
        }
    }
}
