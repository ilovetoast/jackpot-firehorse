<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\BrandIntelligenceScore;
use App\Services\AnalysisStatusLogger;
use App\Services\BrandIntelligence\BrandIntelligenceEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScoreAssetBrandIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $assetId,
        public bool $forceRun = false
    ) {}

    public function handle(BrandIntelligenceEngine $engine): void
    {
        $asset = Asset::query()
            ->with(['brand'])
            ->find($this->assetId);

        if (! $asset || ! $asset->brand_id) {
            return;
        }

        $status = $asset->analysis_status ?? '';
        if (! in_array($status, ['scoring', 'complete'], true)) {
            return;
        }

        $category = $asset->resolveCategoryForTenant();
        $ebiEnabled = $this->forceRun || ($category && $category->isEbiEnabled());

        $brand = $asset->brand;
        $guidelinesPublished = false;
        if ($brand) {
            $brand->loadMissing('brandModel');
            $guidelinesPublished = $brand->brandModel?->active_version_id !== null;
        }

        if ($ebiEnabled && $guidelinesPublished && ! $this->alreadyScoredForCurrentEngine($asset)) {
            $payload = $engine->scoreAsset($asset);
            if ($payload !== null) {
                $this->persistAssetScore($asset, $payload);

                Log::info('[EBI] Asset scored', [
                    'asset_id' => $asset->id,
                    'brand_id' => $asset->brand_id,
                    'score' => $payload['overall_score'] ?? null,
                    'engine_version' => BrandIntelligenceEngine::ENGINE_VERSION,
                ]);
            }
        }

        $asset->refresh();
        if (($asset->analysis_status ?? '') === 'scoring') {
            $asset->update(['analysis_status' => 'complete']);
            AnalysisStatusLogger::log($asset, 'scoring', 'complete', 'ScoreAssetBrandIntelligenceJob');
        }
    }

    /**
     * Idempotent per {@see BrandIntelligenceEngine::ENGINE_VERSION}: skip duplicate work from retries, reprocessing, and duplicate dispatches.
     * Legacy rows with null `engine_version` are treated as the current engine for skip purposes.
     */
    protected function alreadyScoredForCurrentEngine(Asset $asset): bool
    {
        return BrandIntelligenceScore::query()
            ->where('asset_id', $asset->id)
            ->whereNull('execution_id')
            ->where(function ($q) {
                $q->where('engine_version', BrandIntelligenceEngine::ENGINE_VERSION)
                    ->orWhereNull('engine_version');
            })
            ->exists();
    }

    /**
     * One row per (asset_id, engine_version). Migrates legacy rows that used asset_id-only upserts with null engine_version.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function persistAssetScore(Asset $asset, array $payload): void
    {
        $attrs = [
            'brand_id' => $asset->brand_id,
            'execution_id' => null,
            'overall_score' => $payload['overall_score'],
            'confidence' => $payload['confidence'],
            'level' => $payload['level'],
            'breakdown_json' => $payload['breakdown_json'],
            'ai_used' => $payload['ai_used'],
            'engine_version' => BrandIntelligenceEngine::ENGINE_VERSION,
        ];

        $legacy = BrandIntelligenceScore::query()
            ->where('asset_id', $asset->id)
            ->whereNull('execution_id')
            ->whereNull('engine_version')
            ->first();

        if ($legacy) {
            $legacy->update($attrs);

            return;
        }

        BrandIntelligenceScore::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'engine_version' => BrandIntelligenceEngine::ENGINE_VERSION,
            ],
            $attrs
        );
    }
}
