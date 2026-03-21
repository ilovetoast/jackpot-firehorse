<?php

namespace App\Services\BrandIntelligence;

use App\Jobs\DebouncedBrandIntelligenceRescoreJob;
use App\Jobs\ScoreAssetBrandIntelligenceJob;
use App\Models\Asset;
use App\Models\BrandIntelligenceScore;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Coordinates when {@see ScoreAssetBrandIntelligenceJob} runs: pipeline completion vs user-driven rescoring.
 */
class BrandIntelligenceScheduleService
{
    /** Delay before rescoring after tag/metadata churn (debounced; last action wins). */
    public const TAG_METADATA_DEBOUNCE_SECONDS = 120;

    /**
     * Queue a first-pass EBI score when the pipeline completes without the embedding path
     * (non-images, or images where thumbnails/embeddings are skipped).
     *
     * Idempotent: {@see ScoreAssetBrandIntelligenceJob} skips if already scored for the current engine.
     */
    public function dispatchAfterPipelineComplete(Asset $asset): void
    {
        // category is an accessor (metadata.category_id), not a relation
        $category = $asset->category;
        if (! $category instanceof Category || ! $category->isEbiEnabled()) {
            return;
        }

        $status = $asset->analysis_status ?? '';
        if (! in_array($status, ['scoring', 'complete'], true)) {
            return;
        }

        ScoreAssetBrandIntelligenceJob::dispatch($asset);

        Log::debug('[EBI] Pipeline-complete score queued', ['asset_id' => $asset->id]);
    }

    /**
     * After user edits tags or metadata, debounce rescoring so rapid clicks collapse to one run.
     */
    public function scheduleDebouncedRescoreAfterUserEdit(Asset $asset): void
    {
        if (! $asset->category instanceof Category || ! $asset->category->isEbiEnabled()) {
            return;
        }

        $key = 'ebi_rescore_debounce_v:'.$asset->id;
        $v = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $v, now()->addMinutes(15));

        DebouncedBrandIntelligenceRescoreJob::dispatch($asset->id, $v)
            ->delay(now()->addSeconds(self::TAG_METADATA_DEBOUNCE_SECONDS));

        Log::debug('[EBI] Debounced rescore scheduled', [
            'asset_id' => $asset->id,
            'debounce_version' => $v,
            'delay_seconds' => self::TAG_METADATA_DEBOUNCE_SECONDS,
        ]);
    }

    /**
     * Clear existing asset-level scores and queue a fresh run (category gate applied inside the job).
     */
    public function purgeAssetScoresAndDispatch(Asset $asset): void
    {
        BrandIntelligenceScore::query()
            ->where('asset_id', $asset->id)
            ->where('brand_id', $asset->brand_id)
            ->whereNull('execution_id')
            ->delete();

        ScoreAssetBrandIntelligenceJob::dispatch($asset, false);
    }
}
