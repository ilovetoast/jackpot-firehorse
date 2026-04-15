<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandReferenceAsset;
use App\Models\BrandVisualReference;
use App\Services\BrandIntelligence\ReferenceSimilarityCalculator;
use Illuminate\Support\Facades\Schema;

final class VisualStyleEvaluator implements DimensionEvaluatorInterface
{
    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult
    {
        $evidence = [];
        $blockers = [];

        if (! $context->hasExtraction('embeddings')) {
            if ($context->mediaType === MediaType::PDF && $context->visualEvaluationRasterResolved) {
                return DimensionResult::notEvaluable(
                    AlignmentDimension::VISUAL_STYLE,
                    'PDF page render is available but no stored embedding vector yet',
                    ['Generate asset embedding for the rendered page to enable visual style evaluation'],
                );
            }

            return DimensionResult::notEvaluable(
                AlignmentDimension::VISUAL_STYLE,
                'Asset has no visual embedding for style comparison',
                ['Generate asset embedding to enable visual style evaluation'],
            );
        }

        $refCount = $this->countStyleReferences($brand);
        $promotedCount = $this->countPromotedReferences($brand);
        $totalRefs = $refCount + $promotedCount;

        if ($totalRefs === 0) {
            return DimensionResult::missingReference(
                AlignmentDimension::VISUAL_STYLE,
                'No style references with embeddings available for comparison',
                ['Add approved style reference images to enable visual style evaluation'],
            );
        }

        $assetRow = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        $assetVec = ($assetRow && ! empty($assetRow->embedding_vector))
            ? array_values($assetRow->embedding_vector)
            : [];

        if ($assetVec === []) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::VISUAL_STYLE,
                'Asset embedding vector is empty',
                ['Regenerate asset embedding'],
            );
        }

        $similarities = $this->computeSimilarities($asset, $brand, $assetVec);

        if ($similarities === []) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::VISUAL_STYLE,
                'No valid reference vectors available for comparison',
                ['Ensure reference images have embeddings generated'],
            );
        }

        usort($similarities, fn ($a, $b) => $b['cosine'] <=> $a['cosine']);
        $topN = array_slice($similarities, 0, ReferenceSimilarityCalculator::TOP_N);
        $topSimilarities = array_column($topN, 'cosine');
        $meanSim = array_sum($topSimilarities) / max(1, count($topSimilarities));
        $variance = ReferenceSimilarityCalculator::populationVariance($topSimilarities);

        $thinCoverage = $totalRefs < ReferenceSimilarityCalculator::MIN_STYLE_REFERENCES_FOR_EMBEDDING;

        $confidence = $thinCoverage ? min(0.45, $meanSim) : min(0.85, $meanSim + 0.1);
        if ($variance > ReferenceSimilarityCalculator::VARIANCE_STABILITY_THRESHOLD) {
            $confidence -= 0.1;
        }
        $confidence = max(0.0, min(1.0, $confidence));

        $evidence[] = $thinCoverage
            ? EvidenceItem::soft(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Style similarity %.0f%% (mean of top %d), limited coverage (%d refs)', $meanSim * 100, count($topN), $totalRefs),
            )
            : EvidenceItem::hard(
                EvidenceSource::VISUAL_SIMILARITY,
                sprintf('Style similarity %.0f%% (mean of top %d, %d total refs)', $meanSim * 100, count($topN), $totalRefs),
            );

        if ($thinCoverage) {
            $blockers[] = sprintf('Only %d style reference(s) available; add more to improve confidence', $totalRefs);
        }

        $score = max(0.0, min(1.0, ($meanSim - 0.2) / 0.6));

        if ($score >= 0.6 && ! $thinCoverage) {
            $status = DimensionStatus::ALIGNED;
        } elseif ($score >= 0.35) {
            $status = DimensionStatus::PARTIAL;
        } elseif ($score >= 0.15) {
            $status = DimensionStatus::WEAK;
        } else {
            $status = DimensionStatus::FAIL;
        }

        $stabilityLabel = ReferenceSimilarityCalculator::stabilityLabel($variance);

        return new DimensionResult(
            dimension: AlignmentDimension::VISUAL_STYLE,
            status: $status,
            score: $score,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::VISUAL_SIMILARITY,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: true,
            statusReason: sprintf(
                'Visual style similarity %.0f%% across %d reference(s) (%s)',
                $meanSim * 100,
                $totalRefs,
                $stabilityLabel,
            ),
        );
    }

    private function countStyleReferences(Brand $brand): int
    {
        return BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->get()
            ->filter(fn (BrandVisualReference $r) => $r->isStyleReferenceForSimilarity())
            ->count();
    }

    private function countPromotedReferences(Brand $brand): int
    {
        if (! Schema::hasTable('brand_reference_assets')) {
            return 0;
        }

        return BrandReferenceAsset::query()
            ->where('brand_id', $brand->id)
            ->where('reference_type', BrandReferenceAsset::REFERENCE_TYPE_STYLE)
            ->count();
    }

    /**
     * @return list<array{cosine: float, id: string}>
     */
    private function computeSimilarities(Asset $asset, Brand $brand, array $assetVec): array
    {
        $sims = [];

        $styleRefs = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->get()
            ->filter(fn (BrandVisualReference $r) => $r->isStyleReferenceForSimilarity());

        foreach ($styleRefs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if ($refVec === [] || count($refVec) !== count($assetVec)) {
                continue;
            }
            $c = $this->cosineSimilarity($assetVec, $refVec);
            if ($c >= ReferenceSimilarityCalculator::NOISE_SIMILARITY_FLOOR) {
                $sims[] = ['cosine' => $c, 'id' => 'bvr:' . $ref->id];
            }
        }

        if (Schema::hasTable('brand_reference_assets')) {
            $promoted = BrandReferenceAsset::query()
                ->where('brand_id', $brand->id)
                ->where('reference_type', BrandReferenceAsset::REFERENCE_TYPE_STYLE)
                ->get();

            foreach ($promoted as $bra) {
                $emb = AssetEmbedding::query()->where('asset_id', $bra->asset_id)->first();
                if (! $emb || empty($emb->embedding_vector)) {
                    continue;
                }
                $refVec = array_values($emb->embedding_vector);
                if (count($refVec) !== count($assetVec)) {
                    continue;
                }
                $c = $this->cosineSimilarity($assetVec, $refVec);
                if ($c >= ReferenceSimilarityCalculator::NOISE_SIMILARITY_FLOOR) {
                    $sims[] = ['cosine' => $c, 'id' => 'bra:' . $bra->id];
                }
            }
        }

        return $sims;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return 0.0;
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $v) {
            $w = $b[$i] ?? 0;
            $dot += $v * $w;
            $normA += $v * $v;
            $normB += $w * $w;
        }
        $denom = sqrt($normA) * sqrt($normB);

        return $denom < 1e-10 ? 0.0 : (float) ($dot / $denom);
    }
}
