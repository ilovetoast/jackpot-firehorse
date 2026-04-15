<?php

namespace App\Services\BrandIntelligence\Campaign;

use App\Jobs\ScoreCampaignAlignmentJob;
use App\Models\Collection;
use App\Models\CollectionCampaignIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Centralizes campaign scoring dispatch logic with deduplication and batch chunking.
 *
 * Guards:
 * - Only dispatches when campaign identity is scorable (readiness + enabled)
 * - Asset+collection dedupe via ShouldBeUnique on the job
 * - Batch chunking for rescoring after campaign identity update
 * - Version-safe: engine version is checked inside the job
 */
final class CampaignScoringDispatcher
{
    private const RESCORE_CHUNK_SIZE = 50;

    /**
     * Dispatch scoring for a single asset added to a collection.
     * No-ops if the collection doesn't have a scorable campaign identity.
     */
    public static function dispatchForAsset(string $assetId, int $collectionId): void
    {
        $campaignIdentity = CollectionCampaignIdentity::query()
            ->where('collection_id', $collectionId)
            ->first();

        if (! $campaignIdentity || ! $campaignIdentity->isScorable()) {
            return;
        }

        ScoreCampaignAlignmentJob::dispatch($assetId, $collectionId);

        Log::debug('[CampaignBI] Dispatched single asset scoring', [
            'asset_id' => $assetId,
            'collection_id' => $collectionId,
        ]);
    }

    /**
     * Dispatch scoring for all assets in a collection after a campaign identity update.
     * Uses chunked dispatch to prevent queue explosion.
     *
     * Deletes stale scores for the current engine version first so the job's
     * alreadyScoredForCurrentEngine() guard doesn't skip assets that need rescoring
     * with the updated campaign identity.
     */
    public static function rescoreCollectionAssets(Collection $collection): void
    {
        $campaignIdentity = $collection->campaignIdentity;

        if (! $campaignIdentity || ! $campaignIdentity->isScorable()) {
            Log::debug('[CampaignBI] Skipping rescore: not scorable', [
                'collection_id' => $collection->id,
                'readiness' => $campaignIdentity?->readiness_status,
                'enabled' => $campaignIdentity?->scoring_enabled,
            ]);

            return;
        }

        $deleted = \App\Models\CampaignAlignmentScore::query()
            ->where('collection_id', $collection->id)
            ->where('engine_version', \App\Services\BrandIntelligence\BrandIntelligenceEngine::ENGINE_VERSION)
            ->delete();

        Log::debug('[CampaignBI] Cleared stale scores before rescore', [
            'collection_id' => $collection->id,
            'deleted' => $deleted,
        ]);

        $total = 0;

        $collection->assets()
            ->select('assets.id')
            ->chunkById(self::RESCORE_CHUNK_SIZE, function ($assets) use ($collection, &$total) {
                foreach ($assets as $asset) {
                    ScoreCampaignAlignmentJob::dispatch($asset->id, $collection->id);
                    $total++;
                }
            });

        Log::info('[CampaignBI] Dispatched batch rescore', [
            'collection_id' => $collection->id,
            'assets_queued' => $total,
        ]);
    }

    /**
     * Force-dispatch scoring for a single asset, ignoring the "already scored" check.
     * Used for manual rescore actions.
     */
    public static function forceScoreAsset(string $assetId, int $collectionId): void
    {
        $campaignIdentity = CollectionCampaignIdentity::query()
            ->where('collection_id', $collectionId)
            ->first();

        if (! $campaignIdentity) {
            return;
        }

        // Delete existing score so the job doesn't skip
        \App\Models\CampaignAlignmentScore::query()
            ->where('asset_id', $assetId)
            ->where('collection_id', $collectionId)
            ->delete();

        ScoreCampaignAlignmentJob::dispatch($assetId, $collectionId);
    }
}
