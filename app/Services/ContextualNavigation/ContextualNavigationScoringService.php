<?php

namespace App\Services\ContextualNavigation;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Hygiene\MetadataCanonicalizationService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — pure statistical scoring for folder × quick-filter
 * recommendations.
 *
 * NO AI calls. NO provider calls. NO credit debits. This service is the
 * 80% of Phase 6 that runs without invoking the agent.
 *
 * Inputs (per folder × field pair):
 *   - folder asset count
 *   - assets in the folder that have a value for the field (coverage)
 *   - distinct value count for the field across the folder's assets
 *   - facet usage signals (Phase 5.2 columns: facet_usage_count,
 *     last_facet_usage_at)
 *   - Phase 5.2 quality flags (is_high_cardinality, is_low_quality_candidate)
 *   - Phase 5.3 hygiene signals (alias count, duplicate candidate count) —
 *     read directly off `metadata_value_aliases` to avoid coupling to
 *     FolderQuickFilterQualityService's tenant-aware evaluate() path.
 *
 * Outputs:
 *   - five 0.0–1.0 sub-scores
 *   - a derived 0.0–1.0 overall score
 *   - the raw counters that fed those scores (for `metrics` JSON in the
 *     recommendation row, so admins can audit what the recommender saw)
 *
 * Stability:
 *   - All computations are deterministic given the same DB state.
 *   - Empty / degenerate folders score 0 across the board (the
 *     recommender filters them out via min_assets_per_folder).
 */
class ContextualNavigationScoringService
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
        protected MetadataCanonicalizationService $canonicalization,
    ) {}

    /**
     * @return array{
     *     coverage: float,
     *     reuse_consistency: float,
     *     cardinality_penalty: float,
     *     fragmentation_penalty: float,
     *     usage: float,
     *     narrowing_power: float,
     *     overall: float,
     *     counters: array<string, int|float|null>
     * }
     */
    public function score(Tenant $tenant, Category $folder, MetadataField $field): array
    {
        $folderAssetCount = $this->countFolderAssets($tenant, $folder);
        if ($folderAssetCount <= 0) {
            return $this->emptyScores($folderAssetCount);
        }

        $coverageCount = $this->countAssetsWithFieldPopulated($tenant, $folder, $field);
        $distinctValues = $this->countDistinctValuesInFolder($tenant, $folder, $field);
        $aliasCount = $this->countAliases($tenant, $field);
        // duplicate candidate count is expensive enough that the
        // recommender feeds it in pre-computed; default 0 here.
        $duplicateCandidateCount = 0;

        return $this->computeFromCounters(
            folderAssetCount: $folderAssetCount,
            coverageCount: $coverageCount,
            distinctValues: $distinctValues,
            field: $field,
            aliasCount: $aliasCount,
            duplicateCandidateCount: $duplicateCandidateCount,
        );
    }

    /**
     * Variant accepting pre-fetched counters. The recommender uses this so
     * it can batch-load distinct/coverage counts and avoid N+1 queries.
     *
     * @param  int  $folderAssetCount  total assets in the folder for this tenant
     * @param  int  $coverageCount     subset that has a value for `$field`
     * @param  int  $distinctValues    distinct values for `$field` in this folder
     * @param  int  $aliasCount        Phase 5.3 alias rows for this field × tenant
     * @param  int  $duplicateCandidateCount Phase 5.3 duplicate cluster count
     */
    public function computeFromCounters(
        int $folderAssetCount,
        int $coverageCount,
        int $distinctValues,
        MetadataField $field,
        int $aliasCount = 0,
        int $duplicateCandidateCount = 0,
    ): array {
        if ($folderAssetCount <= 0) {
            return $this->emptyScores($folderAssetCount);
        }

        // Zero coverage = the field has no values in this folder. Even if
        // cardinality (1.0 floor) and fragmentation (1.0 floor) are nominal
        // by default, a filter that applies to nobody MUST not surface as a
        // useful candidate. Short-circuit the whole bundle so overall is 0.
        if ($coverageCount <= 0) {
            return $this->emptyScores($folderAssetCount);
        }

        // -----------------------------------------------------------------
        // Sub-scores
        // -----------------------------------------------------------------

        // Coverage = fraction of folder assets with a value for this field.
        // 0.0 (nobody has it) → 1.0 (everyone has it). The recommender
        // PUNISHES very-low coverage for "suggest" types — a filter that
        // applies to 5% of assets is not contextual.
        $coverage = $folderAssetCount > 0
            ? $this->clamp01($coverageCount / $folderAssetCount)
            : 0.0;

        // Reuse consistency = how often each value gets reused on average.
        // High distinct count vs coverage → low reuse → tag-soup. We map
        // this to (avg uses per value) / (3 + avg uses per value) so that
        // 0 → 0.0, 1 use/value → 0.25, 3 → 0.5, 9 → 0.75.
        $avgUsesPerValue = $distinctValues > 0
            ? $coverageCount / max(1, $distinctValues)
            : 0.0;
        $reuseConsistency = $this->clamp01($avgUsesPerValue / (3 + $avgUsesPerValue));

        // Cardinality penalty: 1.0 = ideal small set; drops as distinct
        // values explode. The function is gentle in the 1–10 range and
        // sharp past the Phase 5.2 cap (default 24).
        $cap = max(1, (int) $this->assignment->maxDistinctValuesForQuickFilter());
        $cardinalityPenalty = $distinctValues <= 1
            ? 1.0
            : $this->clamp01(1.0 - max(0.0, ($distinctValues - $cap) / max(1, $cap)));
        // Override hard: persisted high-cardinality flag → 0.1 floor.
        if ((bool) ($field->is_high_cardinality ?? false) || $distinctValues > ($cap * 2)) {
            $cardinalityPenalty = min($cardinalityPenalty, 0.1);
        }

        // Fragmentation penalty: alias + duplicate candidate density vs
        // distinct values. Even a small alias set on a small option list
        // signals editorial drift; we map it conservatively.
        $fragmentationDensity = $distinctValues > 0
            ? ($aliasCount + $duplicateCandidateCount) / max(1, $distinctValues)
            : 0.0;
        $fragmentationPenalty = $this->clamp01(1.0 - $fragmentationDensity);
        if ((bool) ($field->is_low_quality_candidate ?? false)) {
            $fragmentationPenalty = min($fragmentationPenalty, 0.3);
        }

        // Usage = how often the facet is opened. Phase 5.2 records this
        // on `metadata_fields.facet_usage_count`. Log-scale because some
        // tenants will have orders-of-magnitude more events than others.
        $usageCount = (int) ($field->facet_usage_count ?? 0);
        $usage = $this->clamp01(log10(1 + $usageCount) / log10(1 + 200));

        // Narrowing power proxy: a filter "narrows" well when coverage is
        // high AND distinct values are >1 AND values are reused. We blend
        // these into one sub-score; the recommender uses it as the
        // primary "is this even a useful filter?" axis.
        //
        // Hard rule: a filter with a single distinct value cannot narrow
        // the asset set at all. We zero narrowing in that case so the
        // recommender flags pinned-but-monotone filters for unpinning.
        $narrowingPower = $distinctValues <= 1
            ? 0.0
            : $this->clamp01(
                (0.5 * $coverage)
                + (0.3 * $cardinalityPenalty)
                + (0.2 * $reuseConsistency)
            );

        // Overall: weighted blend. Coverage and narrowing power dominate;
        // usage nudges promotion; fragmentation penalises noisy fields.
        $overall = $this->clamp01(
            (0.30 * $coverage)
            + (0.25 * $narrowingPower)
            + (0.15 * $cardinalityPenalty)
            + (0.15 * $fragmentationPenalty)
            + (0.15 * $usage)
        );

        return [
            'coverage' => round($coverage, 4),
            'reuse_consistency' => round($reuseConsistency, 4),
            'cardinality_penalty' => round($cardinalityPenalty, 4),
            'fragmentation_penalty' => round($fragmentationPenalty, 4),
            'usage' => round($usage, 4),
            'narrowing_power' => round($narrowingPower, 4),
            'overall' => round($overall, 4),
            'counters' => [
                'folder_asset_count' => $folderAssetCount,
                'coverage_count' => $coverageCount,
                'distinct_values' => $distinctValues,
                'avg_uses_per_value' => round($avgUsesPerValue, 4),
                'alias_count' => $aliasCount,
                'duplicate_candidate_count' => $duplicateCandidateCount,
                'facet_usage_count' => $usageCount,
                'distinct_value_cap' => $cap,
                'is_high_cardinality_flag' => (bool) ($field->is_high_cardinality ?? false),
                'is_low_quality_candidate_flag' => (bool) ($field->is_low_quality_candidate ?? false),
            ],
        ];
    }

    /**
     * Coverage-by-folder helper kept public so the recommender can build a
     * per-folder coverage map up front (one query per folder, not
     * per-(folder,field) pair).
     *
     * Counts assets where `assets.metadata->>'$.category_id' = folder.id`,
     * scoped by tenant (and brand if present on the folder).
     */
    public function countFolderAssets(Tenant $tenant, Category $folder): int
    {
        $q = DB::table('assets')
            ->where('tenant_id', $tenant->id)
            ->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) = ?',
                [(int) $folder->id]
            );
        if ($folder->brand_id !== null) {
            $q->where('brand_id', $folder->brand_id);
        }

        return (int) $q->count();
    }

    /**
     * Number of assets in the folder that have an `asset_metadata` row for
     * the given field. Doesn't care what the value is — just whether one
     * exists.
     */
    public function countAssetsWithFieldPopulated(
        Tenant $tenant,
        Category $folder,
        MetadataField $field,
    ): int {
        $q = DB::table('asset_metadata as am')
            ->join('assets as a', 'a.id', '=', 'am.asset_id')
            ->where('a.tenant_id', $tenant->id)
            ->where('am.metadata_field_id', $field->id)
            ->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, "$.category_id")) AS UNSIGNED) = ?',
                [(int) $folder->id]
            );
        if ($folder->brand_id !== null) {
            $q->where('a.brand_id', $folder->brand_id);
        }

        return (int) $q->distinct()->count('am.asset_id');
    }

    /**
     * Distinct value count for `$field` across `$folder`'s assets.
     *
     * For scalar select fields this is a clean COUNT DISTINCT on
     * `JSON_UNQUOTE(value_json)`. For multiselect arrays the literal
     * value_json is an array; counting distinct array literals is a
     * reasonable proxy for distinct combinations and stays cheap. The
     * recommender treats this number as a soft signal — exactness
     * matters less than orders of magnitude.
     */
    public function countDistinctValuesInFolder(
        Tenant $tenant,
        Category $folder,
        MetadataField $field,
    ): int {
        $q = DB::table('asset_metadata as am')
            ->join('assets as a', 'a.id', '=', 'am.asset_id')
            ->where('a.tenant_id', $tenant->id)
            ->where('am.metadata_field_id', $field->id)
            ->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, "$.category_id")) AS UNSIGNED) = ?',
                [(int) $folder->id]
            );
        if ($folder->brand_id !== null) {
            $q->where('a.brand_id', $folder->brand_id);
        }

        return (int) $q->distinct()->count(DB::raw('JSON_UNQUOTE(am.value_json)'));
    }

    /**
     * Phase 5.3 alias rows for `$field` × `$tenant`. Delegates to
     * MetadataCanonicalizationService so the alias-count query lives in
     * one place — Phase 5.3 + Phase 6 share the same SQL definition.
     */
    public function countAliases(Tenant $tenant, MetadataField $field): int
    {
        return $this->canonicalization->aliasCountForField($field, $tenant);
    }

    private function clamp01(float $v): float
    {
        if (is_nan($v)) return 0.0;
        if ($v < 0.0) return 0.0;
        if ($v > 1.0) return 1.0;

        return $v;
    }

    private function emptyScores(int $folderAssetCount): array
    {
        return [
            'coverage' => 0.0,
            'reuse_consistency' => 0.0,
            'cardinality_penalty' => 0.0,
            'fragmentation_penalty' => 0.0,
            'usage' => 0.0,
            'narrowing_power' => 0.0,
            'overall' => 0.0,
            'counters' => [
                'folder_asset_count' => $folderAssetCount,
                'coverage_count' => 0,
                'distinct_values' => 0,
                'avg_uses_per_value' => 0.0,
                'alias_count' => 0,
                'duplicate_candidate_count' => 0,
                'facet_usage_count' => 0,
                'distinct_value_cap' => 0,
                'is_high_cardinality_flag' => false,
                'is_low_quality_candidate_flag' => false,
            ],
        ];
    }
}
