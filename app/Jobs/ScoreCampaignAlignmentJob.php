<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\CampaignAlignmentScore;
use App\Models\CollectionCampaignIdentity;
use App\Services\BrandIntelligence\BrandIntelligenceEngine;
use App\Services\BrandIntelligence\Campaign\CampaignEvaluationOrchestrator;
use App\Services\BrandIntelligence\Dimensions\AlignmentScoreDeriver;
use App\Services\BrandIntelligence\Dimensions\DimensionResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScoreCampaignAlignmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $assetId;
    public int $collectionId;

    public function __construct(string $assetId, int $collectionId)
    {
        $this->assetId = $assetId;
        $this->collectionId = $collectionId;
    }

    /**
     * Dedupe key: prevents duplicate jobs for the same asset+collection pair.
     */
    public function uniqueId(): string
    {
        return 'campaign_score:' . $this->assetId . ':' . $this->collectionId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(CampaignEvaluationOrchestrator $orchestrator, AlignmentScoreDeriver $deriver): void
    {
        $asset = Asset::query()->with(['brand'])->find($this->assetId);
        if (! $asset || ! $asset->brand_id) {
            return;
        }

        $campaignIdentity = CollectionCampaignIdentity::query()
            ->where('collection_id', $this->collectionId)
            ->first();

        if (! $campaignIdentity) {
            return;
        }

        if (! $campaignIdentity->isScorable()) {
            Log::info('[CampaignBI] Skipping: not scorable', [
                'asset_id' => $this->assetId,
                'collection_id' => $this->collectionId,
                'readiness' => $campaignIdentity->readiness_status,
                'scoring_enabled' => $campaignIdentity->scoring_enabled,
            ]);

            return;
        }

        if ($this->alreadyScoredForCurrentEngine($asset)) {
            return;
        }

        $brand = $asset->brand;
        $brand->loadMissing('brandModel.activeVersion');

        $result = $orchestrator->evaluate($asset, $brand, $campaignIdentity);
        $dimensions = $result['dimensions'];
        $weights = $result['weights'];

        $derived = $deriver->derive($dimensions, $weights);

        $breakdownJson = [
            'dimensions' => array_map(fn (DimensionResult $r) => $r->toArray(), $dimensions),
            'evaluation_context' => $result['context']->toArray(),
            'weights' => $weights,
            'weights_note' => $result['weights_note'],
            'campaign_references_used' => $result['campaign_references_used'],
            'campaign_rules_checked' => $result['campaign_rules_checked'],
            'scoring_path' => $derived,
            'v2_weighted_score' => $derived['weighted_score'],
            'v2_overall_confidence' => $derived['overall_confidence'],
            'v2_evaluable_proportion' => $derived['evaluable_proportion'],
            'v2_rating' => $derived['rating'],
            'v2_rating_derivation' => $derived['rating_derivation'],
            'v2_alignment_state' => $derived['alignment_state']->value,
        ];

        $this->persistScore($asset, $campaignIdentity, $derived, $breakdownJson);

        Log::info('[CampaignBI] Asset scored', [
            'asset_id' => $asset->id,
            'collection_id' => $this->collectionId,
            'brand_id' => $asset->brand_id,
            'score' => $derived['rating'],
            'confidence' => $derived['overall_confidence'],
            'engine_version' => BrandIntelligenceEngine::ENGINE_VERSION,
        ]);
    }

    private function alreadyScoredForCurrentEngine(Asset $asset): bool
    {
        return CampaignAlignmentScore::query()
            ->where('asset_id', $asset->id)
            ->where('collection_id', $this->collectionId)
            ->where('engine_version', BrandIntelligenceEngine::ENGINE_VERSION)
            ->exists();
    }

    private function persistScore(
        Asset $asset,
        CollectionCampaignIdentity $campaignIdentity,
        array $derived,
        array $breakdownJson,
    ): void {
        CampaignAlignmentScore::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'collection_id' => $this->collectionId,
                'engine_version' => BrandIntelligenceEngine::ENGINE_VERSION,
            ],
            [
                'campaign_identity_id' => $campaignIdentity->id,
                'brand_id' => $asset->brand_id,
                'overall_score' => $derived['rating'],
                'confidence' => $derived['overall_confidence'],
                'level' => $derived['alignment_state']->value,
                'breakdown_json' => $breakdownJson,
                'ai_used' => false,
            ]
        );
    }
}
