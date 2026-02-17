<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Jobs\DeleteAssetJob;
use App\Models\Asset;
use App\Models\AssetEvent;
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

        // Note: We don't update status to DELETED since we use soft deletes
        // The asset's status remains as-is, but the record is soft-deleted

        // Soft delete the asset
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

        $asset->restore();

        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => $userId,
            'event_type' => 'asset.restored_from_trash',
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
