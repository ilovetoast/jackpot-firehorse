<?php

namespace App\Jobs;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandComplianceAggregate;
use App\Services\BrandDNA\BrandComplianceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8: Re-score execution assets after Brand DNA activation.
 * Only processes assets in categories with asset_type === deliverable (executions).
 */
class RescoreBrandExecutionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const HIGH_SCORE_THRESHOLD = 85;
    private const LOW_SCORE_THRESHOLD = 60;

    protected int $brandId;

    public function __construct(int $brandId)
    {
        $this->brandId = $brandId;
    }

    public function handle(BrandComplianceService $complianceService): void
    {
        $brand = Brand::with('brandModel')->find($this->brandId);
        if (! $brand) {
            return;
        }

        // Tenant isolation enforced at approval controller. Job runs for approved brand.

        // Only score when Brand DNA is enabled and has active version
        $brandModel = $brand->brandModel;
        if (! $brandModel || ! $brandModel->is_enabled || ! $brandModel->activeVersion) {
            Log::info('[RescoreBrandExecutionsJob] Brand model not ready for scoring', ['brand_id' => $this->brandId]);
            return;
        }

        $deliverableCategoryIds = \App\Models\Category::where('brand_id', $brand->id)
            ->where('asset_type', AssetType::DELIVERABLE)
            ->pluck('id')
            ->toArray();

        if (empty($deliverableCategoryIds)) {
            $this->updateAggregates($brand, []);
            return;
        }

        $assets = Asset::where('brand_id', $brand->id)->get();
        $scores = [];
        foreach ($assets as $asset) {
            $categoryId = $asset->metadata['category_id'] ?? null;
            if (! $categoryId || ! in_array((int) $categoryId, $deliverableCategoryIds, true)) {
                continue;
            }
            $result = $complianceService->scoreAsset($asset, $brand);
            if ($result !== null) {
                $scores[] = $result['overall_score'];
            }
        }

        $this->updateAggregates($brand, $scores);
    }

    protected function updateAggregates(Brand $brand, array $scores): void
    {
        $count = count($scores);
        $avgScore = $count > 0 ? array_sum($scores) / $count : null;
        $highCount = count(array_filter($scores, fn ($s) => $s >= self::HIGH_SCORE_THRESHOLD));
        $lowCount = count(array_filter($scores, fn ($s) => $s < self::LOW_SCORE_THRESHOLD));

        BrandComplianceAggregate::updateOrCreate(
            ['brand_id' => $brand->id],
            [
                'avg_score' => $avgScore !== null ? round($avgScore, 2) : null,
                'execution_count' => $count,
                'high_score_count' => $highCount,
                'low_score_count' => $lowCount,
                'last_scored_at' => $count > 0 ? now() : null,
            ]
        );

        Log::info('[RescoreBrandExecutionsJob] Completed', [
            'brand_id' => $brand->id,
            'execution_count' => $count,
            'avg_score' => round($avgScore, 2),
        ]);
    }

    public static function dispatchForBrand(int $brandId): void
    {
        self::dispatch($brandId);
    }
}
