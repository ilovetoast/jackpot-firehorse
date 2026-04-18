<?php

namespace App\Services\BrandIntelligence;

use App\Enums\AssetType;
use App\Jobs\DebouncedBrandIntelligenceRescoreJob;
use App\Jobs\ScoreAssetBrandIntelligenceJob;
use App\Models\Asset;
use App\Models\BrandIntelligenceScore;
use App\Models\Category;
use App\Services\FileTypeService;
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
     * Tenant-level belt-and-suspenders gate. Keeps us from enqueuing jobs we'll just
     * early-return from inside {@see ScoreAssetBrandIntelligenceJob::handle()}.
     *
     * Either kill switch (master AI or Brand Alignment specifically) short-circuits
     * dispatch. Both default to true when absent.
     */
    protected function tenantAllowsBrandAlignment(Asset $asset): bool
    {
        $asset->loadMissing('tenant');
        $settings = $asset->tenant?->settings ?? [];

        if (($settings['ai_enabled'] ?? true) === false) {
            return false;
        }
        if (($settings['brand_alignment_enabled'] ?? true) === false) {
            return false;
        }

        return true;
    }

    /**
     * Queue a first-pass EBI score when the pipeline completes without the embedding path
     * (non-images, or images where thumbnails/embeddings are skipped).
     *
     * Idempotent: {@see ScoreAssetBrandIntelligenceJob} skips if already scored for the current engine.
     */
    public function dispatchAfterPipelineComplete(Asset $asset): void
    {
        if (! $this->tenantAllowsBrandAlignment($asset)) {
            return;
        }

        $category = $asset->resolveCategoryForTenant();
        if (! $category instanceof Category || ! $category->isEbiEnabled()) {
            return;
        }

        $status = $asset->analysis_status ?? '';
        if (! in_array($status, ['scoring', 'complete'], true)) {
            return;
        }

        ScoreAssetBrandIntelligenceJob::dispatch($asset->id);

        Log::debug('[EBI] Pipeline-complete score queued', ['asset_id' => $asset->id]);
    }

    /**
     * Library (non-deliverable) videos with async video AI enabled: defer first-pass EBI until
     * {@see \App\Jobs\GenerateVideoInsightsJob} reaches a terminal state. Deliverables keep immediate EBI.
     */
    public function shouldDeferBrandIntelligenceUntilVideoInsights(Asset $asset): bool
    {
        if (app(FileTypeService::class)->detectFileTypeFromAsset($asset) !== 'video') {
            return false;
        }

        if ($asset->type === AssetType::DELIVERABLE) {
            return false;
        }

        return (bool) config('assets.video_ai.enabled', true);
    }

    /**
     * Video insights finished, skipped, or failed — safe to allow deferred EBI for library videos.
     */
    public function videoInsightsTerminalForDeferredEbi(Asset $asset): bool
    {
        $meta = $asset->metadata ?? [];
        if (! empty($meta['ai_video_insights_completed_at'])) {
            return true;
        }

        $st = $meta['ai_video_status'] ?? null;

        return in_array($st, ['skipped', 'failed'], true);
    }

    /**
     * After video insights settle, queue first-pass EBI if the asset was deferred at finalize.
     * Idempotent via {@see ScoreAssetBrandIntelligenceJob}.
     */
    public function dispatchAfterVideoInsightsIfDeferred(Asset $asset): void
    {
        $asset->refresh();

        if (! $this->shouldDeferBrandIntelligenceUntilVideoInsights($asset)) {
            return;
        }

        if (! $this->videoInsightsTerminalForDeferredEbi($asset)) {
            return;
        }

        $this->dispatchAfterPipelineComplete($asset);

        Log::debug('[EBI] Deferred library-video score queued after video insights terminal', [
            'asset_id' => $asset->id,
        ]);
    }

    /**
     * After user edits tags or metadata, debounce rescoring so rapid clicks collapse to one run.
     */
    public function scheduleDebouncedRescoreAfterUserEdit(Asset $asset): void
    {
        if (! $this->tenantAllowsBrandAlignment($asset)) {
            return;
        }

        $category = $asset->resolveCategoryForTenant();
        if (! $category instanceof Category || ! $category->isEbiEnabled()) {
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

        // Keep purge behavior (existing scores wiped) but skip the dispatch entirely
        // when the tenant has disabled the feature. Matches the intent: "no new scores
        // are computed." The job's own tenant-gate would also no-op it, but skipping
        // dispatch avoids queue churn.
        if (! $this->tenantAllowsBrandAlignment($asset)) {
            return;
        }

        ScoreAssetBrandIntelligenceJob::dispatch($asset->id, false);
    }
}
