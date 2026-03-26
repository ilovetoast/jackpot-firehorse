<?php

namespace App\Jobs;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\AssetUsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hard-remove generative layer AI assets that have been orphaned longer than the configured grace period.
 * Uses {@link DeleteAssetJob} after soft-delete so S3 prefix + DB cleanup stay consistent.
 */
class CleanupOrphanedGenerativeAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AssetUsageService $usage): void
    {
        $days = max(1, (int) config('editor.generative.orphan_cleanup_days', 7));
        $threshold = now()->subDays($days);

        $query = Asset::query()
            ->where('lifecycle', 'orphaned')
            ->where('type', AssetType::AI_GENERATED)
            ->where('metadata->asset_role', 'generative_layer')
            ->whereNull('deleted_at')
            ->where('updated_at', '<', $threshold);

        $count = 0;
        foreach ($query->cursor() as $asset) {
            if ($asset->published_at !== null) {
                continue;
            }

            if ($usage->isAssetReferencedInCompositions((string) $asset->id, (int) $asset->tenant_id, (int) $asset->brand_id)) {
                Log::info('[CleanupOrphanedGenerativeAssets] Skipping — asset referenced again', [
                    'asset_id' => $asset->id,
                ]);
                continue;
            }

            $assetId = (string) $asset->id;
            $logContext = [
                'asset_id' => $assetId,
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'grace_days' => $days,
                'orphaned_since_before' => $threshold->toIso8601String(),
                'asset_updated_at' => $asset->updated_at?->toIso8601String(),
                'event' => 'orphan_hard_delete_queued',
            ];
            $asset->delete();
            DeleteAssetJob::dispatch($assetId);
            $count++;

            Log::info('[CleanupOrphanedGenerativeAssets] Queued hard deletion for orphaned generative layer asset', $logContext);
        }

        if ($count > 0) {
            Log::info('[CleanupOrphanedGenerativeAssets] Batch completed', ['count' => $count, 'grace_days' => $days]);
        }
    }
}
