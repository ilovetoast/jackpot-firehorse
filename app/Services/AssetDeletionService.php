<?php

namespace App\Services;

use App\Enums\EventType;
use App\Jobs\DeleteAssetJob;
use App\Models\Asset;
use App\Models\AssetEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class AssetDeletionService
{
    /**
     * Soft delete an asset.
     *
     * @param Asset $asset
     * @param int|null $userId Optional user ID for event tracking
     * @return void
     */
    public function softDelete(Asset $asset, ?int $userId = null): void
    {
        // Store asset data before deletion
        $assetId = $asset->id;
        $tenantId = $asset->tenant_id;
        $brandId = $asset->brand_id;
        $originalFilename = $asset->original_filename;
        $sizeBytes = $asset->size_bytes;

        // Phase B2: Set who deleted before soft delete
        $asset->deleted_by_user_id = $userId;
        $asset->saveQuietly();

        // Soft delete the asset (sets deleted_at)
        $asset->delete();

        // Emit deletion event (after deletion, asset still exists in DB with deleted_at)
        AssetEvent::create([
            'tenant_id' => $tenantId,
            'brand_id' => $brandId,
            'asset_id' => $assetId,
            'user_id' => $userId,
            'event_type' => 'asset.deleted',
            'metadata' => [
                'deletion_type' => 'soft',
                'original_filename' => $originalFilename,
                'size_bytes' => $sizeBytes,
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset soft deleted', [
            'asset_id' => $assetId,
            'original_filename' => $originalFilename,
            'grace_period_days' => config('assets.deletion_grace_period_days', 30),
        ]);

        // Dispatch hard delete job after grace period
        $gracePeriodDays = config('assets.deletion_grace_period_days', 30);
        DeleteAssetJob::dispatch($assetId)
            ->delay(now()->addDays($gracePeriodDays));
    }

    /**
     * Restore a soft-deleted asset (undo delete before grace period expires).
     * When DeleteAssetJob runs, it checks if asset is still trashed; if restored, job skips.
     *
     * @param Asset $asset Must be loaded with withTrashed()
     * @param int|null $userId Optional user ID for event tracking
     * @return void
     */
    public function restoreFromTrash(Asset $asset, ?int $userId = null): void
    {
        if (!$asset->trashed()) {
            return; // Idempotent: already restored
        }

        $asset->deleted_by_user_id = null;
        $asset->saveQuietly();
        $asset->restore();

        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => $userId,
            'event_type' => \App\Enums\EventType::ASSET_RESTORED,
            'metadata' => [
                'original_filename' => $asset->original_filename,
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset restored from trash', [
            'asset_id' => $asset->id,
            'original_filename' => $asset->original_filename,
        ]);
    }

    /**
     * Phase B2: Permanently delete a soft-deleted asset (force delete from trash).
     * Runs the same logic as DeleteAssetJob synchronously, then emits asset.force_deleted with user_id.
     *
     * @param Asset $asset Must be loaded with withTrashed() and must be trashed
     * @param int|null $userId User who requested the force delete
     * @return void
     * @throws \Throwable
     */
    public function forceDelete(Asset $asset, ?int $userId = null): void
    {
        if (! $asset->trashed()) {
            throw new \InvalidArgumentException('Asset is not deleted. Only trashed assets can be force-deleted.');
        }

        $tenantId = $asset->tenant_id;
        $brandId = $asset->brand_id;
        $assetId = $asset->id;
        $originalFilename = $asset->original_filename;

        Bus::dispatchSync(new DeleteAssetJob($assetId));

        AssetEvent::create([
            'tenant_id' => $tenantId,
            'brand_id' => $brandId,
            'asset_id' => $assetId,
            'user_id' => $userId,
            'event_type' => EventType::ASSET_FORCE_DELETED,
            'metadata' => [
                'deletion_type' => 'force',
                'original_filename' => $originalFilename,
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset force-deleted from trash', [
            'asset_id' => $assetId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Get soft-deleted assets that are past grace period and ready for hard deletion.
     *
     * @param int $batchSize
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAssetsReadyForHardDeletion(int $batchSize = 100)
    {
        $gracePeriodDays = config('assets.deletion_grace_period_days', 30);
        $cutoffDate = now()->subDays($gracePeriodDays);

        // Get soft-deleted assets that are past grace period
        // Note: Status doesn't matter for soft-deleted assets - they're deleted
        return Asset::onlyTrashed()
            ->where('deleted_at', '<=', $cutoffDate)
            ->limit($batchSize)
            ->get();
    }
}
