<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Models\Asset;
use App\Models\User;
use App\Services\ActivityRecorder;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Asset Archive Service
 *
 * Handles archiving and restoring assets with proper permission checks,
 * lifecycle state management, and S3 storage class transitions.
 *
 * Phase L.3 — Asset Archive & Restore
 */
class AssetArchiveService
{
    /**
     * Archive an asset.
     *
     * Sets archived_at timestamp and archived_by_id, hides the asset,
     * and transitions S3 objects to cheaper storage class.
     * Enforces permissions and guards against invalid states.
     *
     * @param Asset $asset The asset to archive
     * @param User $actor The user performing the action
     * @param string|null $reason Optional reason for archiving
     * @return void
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \RuntimeException
     */
    public function archive(Asset $asset, User $actor, ?string $reason = null): void
    {
        // Check permission via policy
        Gate::forUser($actor)->authorize('archive', $asset);

        // Guard: Cannot archive failed assets
        if ($asset->status === AssetStatus::FAILED) {
            throw new \RuntimeException('Cannot archive failed assets.');
        }

        // Idempotent: If already archived, do nothing
        if ($asset->isArchived()) {
            return;
        }

        DB::transaction(function () use ($asset, $actor, $reason) {
            // Set archive fields
            $asset->archived_at = now();
            $asset->archived_by_id = $actor->id;

            // Hide the asset (archived assets are always hidden)
            $asset->status = AssetStatus::HIDDEN;

            $asset->save();

            // Transition S3 objects to cheaper storage class (non-blocking)
            $this->transitionToArchiveStorageClass($asset);

            // Log activity event
            try {
                ActivityRecorder::record(
                    tenant: $asset->tenant,
                    eventType: EventType::ASSET_ARCHIVED,
                    subject: $asset,
                    actor: $actor,
                    brand: $asset->brand,
                    metadata: array_filter([
                        'archived_at' => $asset->archived_at->toIso8601String(),
                        'archived_by_id' => $actor->id,
                        'reason' => $reason,
                    ])
                );
            } catch (\Exception $e) {
                // Activity logging must never break the operation
                Log::error('[AssetArchiveService] Failed to log archive event', [
                    'asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Restore an archived asset.
     *
     * Clears archived_at and archived_by_id, restores visibility based on
     * publication state, and transitions S3 objects back to standard storage class.
     * Enforces permissions.
     *
     * @param Asset $asset The asset to restore
     * @param User $actor The user performing the action
     * @return void
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \RuntimeException
     */
    public function restore(Asset $asset, User $actor): void
    {
        // Check permission via policy
        Gate::forUser($actor)->authorize('restoreArchive', $asset);

        // Guard: Cannot restore failed assets
        if ($asset->status === AssetStatus::FAILED) {
            throw new \RuntimeException('Cannot restore failed assets.');
        }

        // Idempotent: If already restored (not archived), do nothing
        if (!$asset->isArchived()) {
            return;
        }

        DB::transaction(function () use ($asset, $actor) {
            // Clear archive fields
            $asset->archived_at = null;
            $asset->archived_by_id = null;

            // Restore visibility based on publication state
            // If published, make visible; otherwise keep hidden
            if ($asset->isPublished()) {
                $asset->status = AssetStatus::VISIBLE;
            } else {
                $asset->status = AssetStatus::HIDDEN;
            }

            $asset->save();

            // Transition S3 objects back to standard storage class (non-blocking)
            $this->transitionToStandardStorageClass($asset);

            // Log activity event
            try {
                ActivityRecorder::record(
                    tenant: $asset->tenant,
                    eventType: EventType::ASSET_UNARCHIVED,
                    subject: $asset,
                    actor: $actor,
                    brand: $asset->brand,
                    metadata: [
                        'restored_at' => now()->toIso8601String(),
                        'restored_by_id' => $actor->id,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break the operation
                Log::error('[AssetArchiveService] Failed to log restore event', [
                    'asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Transition asset's S3 objects to archive storage class (STANDARD_IA or GLACIER_IR).
     *
     * This method transitions the main asset file and all thumbnails to a cheaper
     * storage class. The operation is non-blocking and fails gracefully.
     *
     * @param Asset $asset The asset to transition
     * @return void
     */
    protected function transitionToArchiveStorageClass(Asset $asset): void
    {
        try {
            $s3Client = $this->getS3Client();
            $bucket = config('filesystems.disks.s3.bucket');

            if (!$s3Client || !$bucket) {
                Log::warning('[AssetArchiveService] S3 client or bucket not configured, skipping storage class transition', [
                    'asset_id' => $asset->id,
                ]);
                return;
            }

            // Get all S3 paths for this asset
            $paths = $this->getAssetS3Paths($asset);

            foreach ($paths as $path) {
                try {
                    // Use copyObject to change storage class (copy object to itself with new storage class)
                    $s3Client->copyObject([
                        'Bucket' => $bucket,
                        'CopySource' => "{$bucket}/{$path}",
                        'Key' => $path,
                        'StorageClass' => 'STANDARD_IA', // Infrequent Access - cheaper for archived assets
                        'MetadataDirective' => 'COPY', // Preserve existing metadata
                    ]);

                    Log::info('[AssetArchiveService] Transitioned S3 object to STANDARD_IA', [
                        'asset_id' => $asset->id,
                        'path' => $path,
                    ]);
                } catch (\Exception $e) {
                    // Log but don't fail - S3 operations can be slow or fail
                    Log::warning('[AssetArchiveService] Failed to transition S3 object to archive storage class', [
                        'asset_id' => $asset->id,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Fail gracefully - archiving should succeed even if S3 transition fails
            Log::error('[AssetArchiveService] Failed to transition asset to archive storage class', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Transition asset's S3 objects back to standard storage class.
     *
     * This method transitions the main asset file and all thumbnails back to
     * STANDARD storage class for active use. The operation is non-blocking.
     *
     * @param Asset $asset The asset to transition
     * @return void
     */
    protected function transitionToStandardStorageClass(Asset $asset): void
    {
        try {
            $s3Client = $this->getS3Client();
            $bucket = config('filesystems.disks.s3.bucket');

            if (!$s3Client || !$bucket) {
                Log::warning('[AssetArchiveService] S3 client or bucket not configured, skipping storage class transition', [
                    'asset_id' => $asset->id,
                ]);
                return;
            }

            // Get all S3 paths for this asset
            $paths = $this->getAssetS3Paths($asset);

            foreach ($paths as $path) {
                try {
                    // Use copyObject to change storage class back to STANDARD
                    $s3Client->copyObject([
                        'Bucket' => $bucket,
                        'CopySource' => "{$bucket}/{$path}",
                        'Key' => $path,
                        'StorageClass' => 'STANDARD',
                        'MetadataDirective' => 'COPY', // Preserve existing metadata
                    ]);

                    Log::info('[AssetArchiveService] Transitioned S3 object to STANDARD', [
                        'asset_id' => $asset->id,
                        'path' => $path,
                    ]);
                } catch (\Exception $e) {
                    // Log but don't fail - S3 operations can be slow or fail
                    Log::warning('[AssetArchiveService] Failed to transition S3 object to standard storage class', [
                        'asset_id' => $asset->id,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Fail gracefully - restoring should succeed even if S3 transition fails
            Log::error('[AssetArchiveService] Failed to transition asset to standard storage class', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get all S3 paths for an asset (main file + thumbnails).
     *
     * @param Asset $asset The asset
     * @return array<string> Array of S3 paths
     */
    protected function getAssetS3Paths(Asset $asset): array
    {
        $paths = [];

        // Add main asset file path
        if ($asset->storage_root_path) {
            $paths[] = $asset->storage_root_path;
        }

        // Add thumbnail paths from metadata
        $metadata = $asset->metadata ?? [];
        if (isset($metadata['thumbnails']) && is_array($metadata['thumbnails'])) {
            foreach ($metadata['thumbnails'] as $thumbnail) {
                if (isset($thumbnail['path'])) {
                    $paths[] = $thumbnail['path'];
                }
            }
        }

        // Add preview thumbnail paths
        if (isset($metadata['preview_thumbnails']) && is_array($metadata['preview_thumbnails'])) {
            foreach ($metadata['preview_thumbnails'] as $thumbnail) {
                if (isset($thumbnail['path'])) {
                    $paths[] = $thumbnail['path'];
                }
            }
        }

        return array_filter($paths); // Remove any null/empty paths
    }

    /**
     * Get S3 client instance.
     *
     * @return S3Client|null
     */
    protected function getS3Client(): ?S3Client
    {
        try {
            $disk = Storage::disk('s3');
            
            // Laravel 9+ exposes S3Client directly via getClient() method
            if (method_exists($disk, 'getClient')) {
                return $disk->getClient();
            }

            // Fallback: Create new S3Client if getClient() is not available
            // This handles older Laravel versions or custom configurations
            $config = [
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
            ];
            if (config('filesystems.disks.s3.endpoint')) {
                $config['endpoint'] = config('filesystems.disks.s3.endpoint');
                $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
            }
            return new S3Client($config);
        } catch (\Exception $e) {
            Log::error('[AssetArchiveService] Failed to get S3 client', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
