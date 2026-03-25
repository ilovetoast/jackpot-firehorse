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

            $asset->delete();
            DeleteAssetJob::dispatch((string) $asset->id);
            $count++;
        }

        if ($count > 0) {
            Log::info('[CleanupOrphanedGenerativeAssets] Queued hard deletion', ['count' => $count]);
        }
    }
}
