<?php

namespace App\Services\ContextualNavigation;

use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\MetadataFieldVisibility;
use App\Models\Tenant;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use App\Services\Hygiene\MetadataDuplicateDetector;
use Illuminate\Support\Collection;

/**
 * Phase 6 — generates pending recommendation rows from statistical signals.
 *
 * Lifecycle inside one tenant run:
 *   1. Iterate eligible folders (asset count >= floor).
 *   2. For each folder, iterate eligible filters (Phase 1 eligibility).
 *   3. Score the (folder, field) pair via ContextualNavigationScoringService.
 *   4. Decide whether each recommendation type fires based on score
 *      thresholds + current quick-filter state (no point recommending
 *      "enable as quick filter" for one already enabled).
 *   5. Upsert pending rows in `contextual_navigation_recommendations`.
 *      Existing pending rows for the same (tenant, folder, field, type)
 *      get their score / metrics / last_seen_at refreshed; reviewed rows
 *      are left alone.
 *
 * NO AI calls here. The optional AI rationale step lives in
 * ContextualNavigationAiReasoner and runs only when the job decides the
 * row qualifies (borderline score within band).
 */
class ContextualNavigationRecommender
{
    public function __construct(
        protected ContextualNavigationScoringService $scoring,
        protected FolderQuickFilterEligibilityService $eligibility,
        protected MetadataDuplicateDetector $duplicates,
    ) {}

    /**
     * Run the recommender for one tenant.
     *
     * @param  callable|null  $onPause  optional progress callback (folderId, fieldId)
     * @return array{
     *     written: int,
     *     skipped_folders: int,
     *     skipped_fields: int,
     *     candidates: list<int>  ids of recommendation rows touched (for AI reasoner pass)
     * }
     */
    public function run(Tenant $tenant, ?callable $onPause = null): array
    {
        $config = config('contextual_navigation_insights');
        $minFolderAssets = (int) ($config['min_assets_per_folder'] ?? 10);
        $minDistinct = (int) ($config['min_distinct_values_per_field'] ?? 2);
        $maxRecs = (int) ($config['max_recommendations_per_run'] ?? 200);
        $thresholds = (array) ($config['score_thresholds'] ?? []);

        $written = 0;
        $skippedFolders = 0;
        $skippedFields = 0;
        $candidateIds = [];

        $folders = Category::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get();

        foreach ($folders as $folder) {
            $folderAssetCount = $this->scoring->countFolderAssets($tenant, $folder);
            if ($folderAssetCount < $minFolderAssets) {
                $skippedFolders++;
                continue;
            }

            $candidates = $this->candidateFieldsForFolder($tenant, $folder);
            $existingRows = $this->loadVisibilityRows($folder, $candidates->pluck('id')->all());

            foreach ($candidates as $field) {
                if ($onPause) $onPause($folder->id, $field->id);
                $coverage = $this->scoring->countAssetsWithFieldPopulated($tenant, $folder, $field);
                if ($coverage <= 0) {
                    $skippedFields++;
                    continue;
                }
                $distinct = $this->scoring->countDistinctValuesInFolder($tenant, $folder, $field);
                if ($distinct < $minDistinct) {
                    $skippedFields++;
                    continue;
                }

                $aliasCount = $this->scoring->countAliases($tenant, $field);
                $duplicateCandidates = 0;
                try {
                    $duplicateCandidates = $this->duplicates->candidateGroupCount($field, $tenant);
                } catch (\Throwable $e) {
                    // Hygiene scan must never break recommendation generation.
                    $duplicateCandidates = 0;
                }

                $scores = $this->scoring->computeFromCounters(
                    folderAssetCount: $folderAssetCount,
                    coverageCount: $coverage,
                    distinctValues: $distinct,
                    field: $field,
                    aliasCount: $aliasCount,
                    duplicateCandidateCount: $duplicateCandidates,
                );

                $row = $existingRows[$field->id] ?? null;
                $isQuickFilter = $row !== null && (bool) $row->show_in_folder_quick_filters;
                $isPinned = $row !== null && (bool) $row->is_pinned_folder_quick_filter;

                $recommendations = $this->deriveRecommendationTypes(
                    scores: $scores,
                    thresholds: $thresholds,
                    isQuickFilter: $isQuickFilter,
                    isPinned: $isPinned,
                );

                foreach ($recommendations as $type => $score) {
                    $reasonSummary = $this->reasonFor($type, $scores);
                    $rec = $this->upsertRecommendation(
                        tenant: $tenant,
                        folder: $folder,
                        field: $field,
                        type: $type,
                        score: $score,
                        scores: $scores,
                        reasonSummary: $reasonSummary,
                    );
                    $candidateIds[] = (int) $rec->id;
                    $written++;
                    if ($written >= $maxRecs) {
                        return [
                            'written' => $written,
                            'skipped_folders' => $skippedFolders,
                            'skipped_fields' => $skippedFields,
                            'candidates' => $candidateIds,
                        ];
                    }
                }
            }
        }

        return [
            'written' => $written,
            'skipped_folders' => $skippedFolders,
            'skipped_fields' => $skippedFields,
            'candidates' => $candidateIds,
        ];
    }

    // -----------------------------------------------------------------
    // Candidate field selection
    // -----------------------------------------------------------------

    /**
     * Eligible (Phase 1) metadata fields restricted to types we score.
     * @return Collection<int, MetadataField>
     */
    private function candidateFieldsForFolder(Tenant $tenant, Category $folder): Collection
    {
        $types = (array) config('categories.folder_quick_filters.allowed_types', [
            'single_select', 'multi_select', 'boolean',
        ]);
        // Map to the storage forms the field rows use.
        $storedTypes = [];
        foreach ($types as $t) {
            $storedTypes[] = match ($t) {
                'single_select', 'select' => 'select',
                'multi_select', 'multiselect' => 'multiselect',
                'boolean' => 'boolean',
                default => $t,
            };
        }
        $storedTypes = array_values(array_unique($storedTypes));

        return MetadataField::query()
            ->whereIn('type', array_merge($storedTypes, ['select', 'multiselect', 'boolean']))
            ->where('is_filterable', true)
            ->where(function ($q) {
                $q->whereNull('is_internal_only')->orWhere('is_internal_only', false);
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (MetadataField $f) => $this->eligibility->isEligible($f))
            ->values();
    }

    /**
     * Load visibility rows for a folder × set of fields in one query so
     * the per-field loop doesn't N+1 the DB.
     *
     * @param  int[]  $fieldIds
     * @return array<int, MetadataFieldVisibility>  keyed by metadata_field_id
     */
    private function loadVisibilityRows(Category $folder, array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }
        $rows = MetadataFieldVisibility::query()
            ->where('category_id', $folder->id)
            ->whereIn('metadata_field_id', $fieldIds)
            ->get();
        $byField = [];
        foreach ($rows as $row) {
            $byField[(int) $row->metadata_field_id] = $row;
        }

        return $byField;
    }

    // -----------------------------------------------------------------
    // Threshold logic — which types fire, and at what score
    // -----------------------------------------------------------------

    /**
     * @param  array<string, float>  $thresholds
     * @return array<string, float>  type => score, sorted by precedence
     */
    private function deriveRecommendationTypes(
        array $scores,
        array $thresholds,
        bool $isQuickFilter,
        bool $isPinned,
    ): array {
        $types = [];
        $overall = (float) ($scores['overall'] ?? 0.0);
        $coverage = (float) ($scores['coverage'] ?? 0.0);
        $cardinality = (float) ($scores['cardinality_penalty'] ?? 0.0);
        $fragmentation = (float) ($scores['fragmentation_penalty'] ?? 0.0);
        $narrowing = (float) ($scores['narrowing_power'] ?? 0.0);

        $tSuggest = (float) ($thresholds['suggest_quick_filter'] ?? 0.70);
        $tPin = (float) ($thresholds['suggest_pin_quick_filter'] ?? 0.80);
        $tUnpin = (float) ($thresholds['suggest_unpin_quick_filter'] ?? 0.30);
        $tDisable = (float) ($thresholds['suggest_disable_quick_filter'] ?? 0.20);
        $tOverflow = (float) ($thresholds['suggest_move_to_overflow'] ?? 0.40);
        $tHighCard = (float) ($thresholds['warn_high_cardinality'] ?? 0.50);
        $tLowNav = (float) ($thresholds['warn_low_navigation_value'] ?? 0.30);
        $tFrag = (float) ($thresholds['warn_metadata_fragmentation'] ?? 0.40);
        $tCoverage = (float) ($thresholds['warn_low_coverage'] ?? 0.40);

        // Suggestion types — these are mutually exclusive with respect to
        // existing state. We don't recommend "enable" for already-enabled
        // filters; we don't recommend "pin" for already-pinned ones.
        if (! $isQuickFilter && $overall >= $tSuggest && $narrowing >= 0.4) {
            $types[ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER] = $overall;
        }
        if ($isQuickFilter && ! $isPinned && $overall >= $tPin && $narrowing >= 0.5) {
            $types[ContextualNavigationRecommendation::TYPE_SUGGEST_PIN] = $overall;
        }
        if ($isPinned && ($overall <= $tUnpin || $narrowing <= $tUnpin)) {
            // A pin is "weak" when the filter doesn't actually narrow the
            // asset set (single distinct value, low coverage, or noisy
            // tag-soup). Either weak overall OR weak narrowing power is
            // enough to flag — pinned-monotone filters score high overall
            // (100% coverage of one value) but should still be unpinned.
            $weakSignal = max(1.0 - max($overall, $narrowing), 0.0);
            $types[ContextualNavigationRecommendation::TYPE_SUGGEST_UNPIN] = round($weakSignal, 4);
        }
        if ($isQuickFilter && ! $isPinned && $overall <= $tDisable) {
            $types[ContextualNavigationRecommendation::TYPE_SUGGEST_DISABLE] = round(1.0 - $overall, 4);
        }
        if ($isQuickFilter && ! $isPinned && $overall > $tDisable && $overall <= $tOverflow) {
            $types[ContextualNavigationRecommendation::TYPE_SUGGEST_OVERFLOW] = round(1.0 - $overall, 4);
        }

        // Warning types — informational, can fire alongside suggestions.
        // High cardinality penalty is INVERTED: penalty=0.1 → cardinality
        // is BAD → warning score = 0.9.
        $highCardScore = round(1.0 - $cardinality, 4);
        if ($highCardScore >= $tHighCard) {
            $types[ContextualNavigationRecommendation::TYPE_WARN_HIGH_CARDINALITY] = $highCardScore;
        }
        $lowNavScore = round(1.0 - $narrowing, 4);
        if ($lowNavScore >= $tLowNav && $isQuickFilter) {
            $types[ContextualNavigationRecommendation::TYPE_WARN_LOW_NAV_VALUE] = $lowNavScore;
        }
        $fragScore = round(1.0 - $fragmentation, 4);
        if ($fragScore >= $tFrag) {
            $types[ContextualNavigationRecommendation::TYPE_WARN_FRAGMENTATION] = $fragScore;
        }
        $lowCoverageScore = round(1.0 - $coverage, 4);
        if ($lowCoverageScore >= $tCoverage && $isQuickFilter) {
            $types[ContextualNavigationRecommendation::TYPE_WARN_LOW_COVERAGE] = $lowCoverageScore;
        }

        return $types;
    }

    /**
     * Human-readable one-liner. The recommender only writes statistical
     * reasons; the AI reasoner can replace this on borderline rows.
     */
    private function reasonFor(string $type, array $scores): string
    {
        $coverage = round(($scores['coverage'] ?? 0.0) * 100);
        $narrowing = round(($scores['narrowing_power'] ?? 0.0) * 100);
        $cardinality = round(($scores['cardinality_penalty'] ?? 0.0) * 100);
        $usage = round(($scores['usage'] ?? 0.0) * 100);
        $distinct = (int) ($scores['counters']['distinct_values'] ?? 0);

        return match ($type) {
            ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER =>
                "Strong contextual signal: {$coverage}% coverage, {$distinct} values, narrowing power {$narrowing}%.",
            ContextualNavigationRecommendation::TYPE_SUGGEST_PIN =>
                "Reliably useful in this folder: {$coverage}% coverage, {$narrowing}% narrowing, usage {$usage}%.",
            ContextualNavigationRecommendation::TYPE_SUGGEST_UNPIN =>
                "Pinned but the contextual signal is weak ({$narrowing}% narrowing, {$coverage}% coverage).",
            ContextualNavigationRecommendation::TYPE_SUGGEST_DISABLE =>
                "Quick filter performs poorly: {$coverage}% coverage, {$narrowing}% narrowing, {$distinct} distinct values.",
            ContextualNavigationRecommendation::TYPE_SUGGEST_OVERFLOW =>
                "Marginal value as a top-level quick filter ({$narrowing}% narrowing); consider overflow.",
            ContextualNavigationRecommendation::TYPE_WARN_HIGH_CARDINALITY =>
                "Field has {$distinct} distinct values; high cardinality dilutes contextual filtering.",
            ContextualNavigationRecommendation::TYPE_WARN_LOW_NAV_VALUE =>
                "Quick filter does not narrow results much ({$narrowing}% narrowing power).",
            ContextualNavigationRecommendation::TYPE_WARN_FRAGMENTATION =>
                "Metadata fragmentation detected (aliases / duplicate value clusters).",
            ContextualNavigationRecommendation::TYPE_WARN_LOW_COVERAGE =>
                "Field is only populated on {$coverage}% of folder assets.",
            default => 'Statistical signal triggered this recommendation.',
        };
    }

    // -----------------------------------------------------------------
    // Upsert
    // -----------------------------------------------------------------

    private function upsertRecommendation(
        Tenant $tenant,
        Category $folder,
        MetadataField $field,
        string $type,
        float $score,
        array $scores,
        string $reasonSummary,
    ): ContextualNavigationRecommendation {
        $now = now();
        $metrics = $scores;

        // Find a non-finalised row with the same key. We refresh those;
        // we never overwrite admin-acted rows.
        $existing = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $field->id)
            ->where('recommendation_type', $type)
            ->whereIn('status', [
                ContextualNavigationRecommendation::STATUS_PENDING,
                ContextualNavigationRecommendation::STATUS_DEFERRED,
                ContextualNavigationRecommendation::STATUS_STALE,
            ])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->fill([
                'score' => $score,
                'confidence' => $score,
                'reason_summary' => $reasonSummary,
                'metrics' => $metrics,
                'last_seen_at' => $now,
                // Reactivate stale rows the next run still corroborates.
                'status' => $existing->status === ContextualNavigationRecommendation::STATUS_DEFERRED
                    ? ContextualNavigationRecommendation::STATUS_DEFERRED
                    : ContextualNavigationRecommendation::STATUS_PENDING,
                'source' => ContextualNavigationRecommendation::SOURCE_STATISTICAL,
            ]);
            $existing->brand_id = $folder->brand_id;
            $existing->save();

            return $existing;
        }

        return ContextualNavigationRecommendation::query()->create([
            'tenant_id' => $tenant->id,
            'brand_id' => $folder->brand_id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => $type,
            'source' => ContextualNavigationRecommendation::SOURCE_STATISTICAL,
            'status' => ContextualNavigationRecommendation::STATUS_PENDING,
            'score' => $score,
            'confidence' => $score,
            'reason_summary' => $reasonSummary,
            'metrics' => $metrics,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Read the borderline-band recommendations the AI reasoner should
     * enrich. The job calls this AFTER `run()` and AFTER the credit
     * pre-check.
     *
     * @param  int[]  $candidateIds
     * @return Collection<int, ContextualNavigationRecommendation>
     */
    public function selectBorderlineForAi(array $candidateIds, float $band, int $cap): Collection
    {
        if ($candidateIds === [] || $cap <= 0 || $band <= 0) {
            return collect();
        }
        $thresholds = (array) config('contextual_navigation_insights.score_thresholds', []);
        $rows = ContextualNavigationRecommendation::query()
            ->whereIn('id', $candidateIds)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->get();

        return $rows->filter(function (ContextualNavigationRecommendation $r) use ($thresholds, $band) {
            $threshold = (float) ($thresholds[$r->recommendation_type] ?? 0.5);
            $score = (float) ($r->score ?? 0.0);

            return abs($score - $threshold) <= $band;
        })->take($cap)->values();
    }
}
