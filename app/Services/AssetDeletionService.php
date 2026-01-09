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
        $fileName = $asset->file_name;
        $fileSize = $asset->file_size;

        // Update status to DELETED before soft delete
        $asset->update([
            'status' => AssetStatus::DELETED,
        ]);

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
                'file_name' => $fileName,
                'file_size' => $fileSize,
            ],
            'created_at' => now(),
        ]);

        Log::info('Asset soft deleted', [
            'asset_id' => $assetId,
            'file_name' => $fileName,
            'grace_period_days' => config('assets.deletion_grace_period_days', 30),
        ]);

        // Dispatch hard delete job after grace period
        $gracePeriodDays = config('assets.deletion_grace_period_days', 30);
        DeleteAssetJob::dispatch($assetId)
            ->delay(now()->addDays($gracePeriodDays));
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

        return Asset::onlyTrashed()
            ->where('deleted_at', '<=', $cutoffDate)
            ->where('status', AssetStatus::DELETED)
            ->limit($batchSize)
            ->get();
    }
}
