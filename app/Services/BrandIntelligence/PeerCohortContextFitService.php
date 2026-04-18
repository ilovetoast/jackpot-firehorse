<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use App\Models\AssetEmbedding;
use Illuminate\Support\Facades\DB;

/**
 * Peer-cohort Context Fit evaluator (Stage 8a).
 *
 * When a campaign/context override isn't configured and VLM classification didn't resolve,
 * we can still estimate "does this asset belong here?" by asking how similar its embedding
 * is to other scored assets in the *same execution* (collection) — and falling back to the
 * broader brand+category cohort when the collection alone is too small.
 *
 * Design notes:
 *  - Collection-first, because the user explicitly said executions are the primary unit
 *    of grouping. Only augment with brand+category peers when collection_cohort_size < MIN.
 *  - Embedding vector from {@see AssetEmbedding}; skipped silently if missing (caller emits
 *    the appropriate reason code).
 *  - Cosine median of top-K peers is the headline metric. Top-K (not mean) because we want
 *    to reward "this asset is near *some* peers" rather than "near *all* peers" — the grid
 *    of assets in a collection is typically heterogeneous.
 *  - Confidence is always capped (peer similarity is a single-family signal; the Alignment
 *    deriver's signal-family dampener will further dampen if this is the only evidence).
 */
final class PeerCohortContextFitService
{
    public const MIN_PEER_COHORT = 5;

    public const TOP_K = 10;

    /** Minimum cosine similarity for a peer to count — below this we treat it as noise. */
    public const NOISE_FLOOR = 0.2;

    /** When cohort size hits this, we upgrade evidence to "hard" and allow higher confidence. */
    public const STRONG_COHORT_SIZE = 15;

    /** Hard cap on confidence from this single (reference-similarity) family. */
    public const MAX_CONFIDENCE = 0.6;

    /**
     * Evaluate an asset's peer-cohort fit.
     *
     * Returns a structured result the caller can turn into a DimensionResult. Shape:
     *   ok: bool              // true when we computed a usable signal
     *   reason: string        // one of: missing_embedding|no_category|cohort_too_small|cohort_no_signal|evaluated
     *   cohort_source: string // collection|brand_category|both|none
     *   cohort_size: int
     *   peer_count_considered: int
     *   median_cosine: float|null
     *   top_k_cosines: float[]
     *   score: float|null     // when ok=true
     *   confidence: float|null
     *   evidence_strength: string|null // soft|hard
     *   diagnostic: array<string, mixed>
     *
     * @return array<string, mixed>
     */
    public function evaluate(Asset $asset): array
    {
        $vec = $this->loadEmbeddingVector($asset);
        if ($vec === []) {
            return $this->fail('missing_embedding', [
                'reason_detail' => 'Asset has no stored embedding vector',
            ]);
        }

        $categoryId = $asset->category_id;
        if ($categoryId === null || $categoryId === '') {
            return $this->fail('no_category', [
                'reason_detail' => 'Asset is not assigned to a category',
            ]);
        }

        $excludeIds = [$asset->id];

        // Pull collection-scope peer ids first (same execution). These are asset ids that share
        // any collection with the current asset AND live in the same brand+category.
        $collectionPeerIds = $this->collectionPeerIds($asset, $excludeIds);
        $brandCategoryPeerIds = [];
        $cohortSource = 'collection';

        if (count($collectionPeerIds) < self::MIN_PEER_COHORT) {
            $brandCategoryPeerIds = $this->brandCategoryPeerIds($asset, array_merge($excludeIds, $collectionPeerIds));
            $cohortSource = $collectionPeerIds === [] ? 'brand_category' : 'both';
        }

        $peerIds = array_values(array_unique(array_merge($collectionPeerIds, $brandCategoryPeerIds)));

        if (count($peerIds) < self::MIN_PEER_COHORT) {
            return $this->fail('cohort_too_small', [
                'cohort_source' => $peerIds === [] ? 'none' : $cohortSource,
                'cohort_size' => count($peerIds),
                'collection_peers' => count($collectionPeerIds),
                'brand_category_peers' => count($brandCategoryPeerIds),
                'min_required' => self::MIN_PEER_COHORT,
            ]);
        }

        // Load peer embeddings in one query; compute cosine to the asset's vector.
        $similarities = $this->computeCosines($vec, $peerIds);

        // Filter noise below the floor; what's left is the usable signal.
        $usable = array_values(array_filter($similarities, fn ($c) => $c >= self::NOISE_FLOOR));

        if ($usable === []) {
            return $this->fail('cohort_no_signal', [
                'cohort_source' => $cohortSource,
                'cohort_size' => count($peerIds),
                'peers_above_floor' => 0,
                'noise_floor' => self::NOISE_FLOOR,
            ]);
        }

        rsort($usable);
        $topK = array_slice($usable, 0, self::TOP_K);
        $median = $this->median($topK);

        // Score: map [0.3, 0.8] cosine → [0.0, 1.0] linearly; clamp.
        $score = max(0.0, min(1.0, ($median - 0.3) / 0.5));

        // Confidence: base off cohort size and variance. Capped.
        $cohortSize = count($peerIds);
        $sizeBonus = min(0.15, ($cohortSize - self::MIN_PEER_COHORT) * 0.015);
        $variance = $this->variance($topK);
        $variancePenalty = $variance > 0.04 ? 0.1 : 0.0;
        $confidence = max(0.0, min(self::MAX_CONFIDENCE, 0.3 + $sizeBonus - $variancePenalty));

        $evidenceStrength = $cohortSize >= self::STRONG_COHORT_SIZE && $variance <= 0.04 ? 'hard' : 'soft';

        return [
            'ok' => true,
            'reason' => 'evaluated',
            'cohort_source' => $cohortSource,
            'cohort_size' => $cohortSize,
            'peer_count_considered' => count($usable),
            'median_cosine' => round($median, 4),
            'top_k_cosines' => array_map(fn ($c) => round($c, 4), $topK),
            'score' => round($score, 4),
            'confidence' => round($confidence, 4),
            'evidence_strength' => $evidenceStrength,
            'diagnostic' => [
                'collection_peers' => count($collectionPeerIds),
                'brand_category_peers' => count($brandCategoryPeerIds),
                'variance' => round($variance, 4),
            ],
        ];
    }

    /**
     * @param  list<string|int>  $excludeIds
     * @return list<string|int>
     */
    private function collectionPeerIds(Asset $asset, array $excludeIds): array
    {
        // Find collection ids this asset belongs to; then other assets in those collections
        // that share brand+category. Keep the query narrow to avoid scanning the whole pivot.
        $collectionIds = DB::table('asset_collections')
            ->where('asset_id', $asset->id)
            ->pluck('collection_id')
            ->all();

        if ($collectionIds === []) {
            return [];
        }

        return DB::table('asset_collections')
            ->join('assets', 'assets.id', '=', 'asset_collections.asset_id')
            ->whereIn('asset_collections.collection_id', $collectionIds)
            ->where('assets.brand_id', $asset->brand_id)
            ->where('assets.category_id', $asset->category_id)
            ->whereNotIn('assets.id', $excludeIds)
            ->whereNull('assets.deleted_at')
            ->distinct()
            ->pluck('assets.id')
            ->all();
    }

    /**
     * @param  list<string|int>  $excludeIds
     * @return list<string|int>
     */
    private function brandCategoryPeerIds(Asset $asset, array $excludeIds): array
    {
        // Sort by most recently scored first so we get a representative slice of current work,
        // not ancient assets that may predate the current brand identity. Cap to keep the
        // cosine pass fast.
        return DB::table('assets')
            ->where('brand_id', $asset->brand_id)
            ->where('category_id', $asset->category_id)
            ->whereNotIn('id', $excludeIds)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<float>  $assetVec
     * @param  list<string|int>  $peerIds
     * @return list<float>
     */
    private function computeCosines(array $assetVec, array $peerIds): array
    {
        if ($peerIds === []) {
            return [];
        }

        $rows = AssetEmbedding::query()
            ->whereIn('asset_id', $peerIds)
            ->get(['asset_id', 'embedding_vector']);

        $out = [];
        foreach ($rows as $row) {
            $peerVec = is_array($row->embedding_vector) ? array_values($row->embedding_vector) : [];
            if ($peerVec === [] || count($peerVec) !== count($assetVec)) {
                continue;
            }
            $c = $this->cosine($assetVec, $peerVec);
            if ($c > 0.0) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return list<float>
     */
    private function loadEmbeddingVector(Asset $asset): array
    {
        $row = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        if (! $row || empty($row->embedding_vector)) {
            return [];
        }
        $vec = array_values($row->embedding_vector);

        return $vec === [] ? [] : array_map(static fn ($v) => (float) $v, $vec);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $na += $av * $av;
            $nb += $bv * $bv;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2.0 : $values[$mid];
    }

    /**
     * @param  list<float>  $values
     */
    private function variance(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return $sum / $n;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fail(string $reason, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'reason' => $reason,
            'cohort_source' => 'none',
            'cohort_size' => 0,
            'peer_count_considered' => 0,
            'median_cosine' => null,
            'top_k_cosines' => [],
            'score' => null,
            'confidence' => null,
            'evidence_strength' => null,
            'diagnostic' => $extra,
        ], ['diagnostic' => $extra]);
    }
}
