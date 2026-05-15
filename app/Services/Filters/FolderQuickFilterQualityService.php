<?php

namespace App\Services\Filters;

use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Hygiene\MetadataCanonicalizationService;
use App\Services\Hygiene\MetadataDuplicateDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5.2 — operational quality signals for quick filters.
 *
 * Surfaces lightweight heuristics that admins can use to make
 * "should this filter be a quick filter?" decisions without staring at raw
 * value distributions:
 *
 *   - estimated_distinct_value_count: most recent cardinality estimate
 *     (option count or DISTINCT(value_json)). Hydrated opportunistically.
 *   - last_facet_usage_at / facet_usage_count: aggregate usage signal.
 *   - is_high_cardinality: derived flag — true once the estimate exceeds
 *     `categories.folder_quick_filters.max_distinct_values_for_quick_filter`.
 *   - is_low_quality_candidate: reserved heuristic for OCR/AI explosions.
 *
 * Phase 5.2 deliberately:
 *   - Does NOT auto-suppress filters. Admins decide.
 *   - Does NOT block enable; we surface warnings only.
 *   - Reuses the Phase 5 facet provider — no new estimation pipeline.
 *
 * Phase 6+ ideas: synonym detection, AI-tag clustering, low-usage suppression.
 */
class FolderQuickFilterQualityService
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
        // Phase 5.3 — hygiene signals. Both default to null so call sites
        // that haven't been updated keep working; AppServiceProvider binds
        // the real implementations everywhere that matters. Marking them
        // nullable also keeps the unit-test path simple.
        protected ?MetadataCanonicalizationService $canonical = null,
        protected ?MetadataDuplicateDetector $duplicates = null,
    ) {}

    /**
     * Materialize the quality summary for a field. Reads the persisted
     * Phase 5.2 columns and re-computes derived flags so callers don't have
     * to interpret nullables themselves.
     *
     * @return array{
     *     estimated_distinct_value_count: ?int,
     *     last_facet_usage_at: ?string,
     *     facet_usage_count: int,
     *     is_high_cardinality: bool,
     *     is_low_quality_candidate: bool,
     *     alias_count: int,
     *     duplicate_candidate_count: int,
     *     warnings: list<string>
     * }
     */
    public function evaluate(MetadataField $field, ?Tenant $tenant = null): array
    {
        $estimated = $field->estimated_distinct_value_count !== null
            ? (int) $field->estimated_distinct_value_count
            : null;
        $cap = $this->assignment->maxDistinctValuesForQuickFilter();
        $isHighCardinality = $estimated !== null && $cap > 0 && $estimated > $cap;

        // Persisted column wins if explicitly set (admin override path), else
        // fall back to the derived value so callers see the freshest signal.
        $persistedHigh = (bool) ($field->is_high_cardinality ?? false);
        $isHighCardinality = $persistedHigh || $isHighCardinality;
        $isLowQualityCandidate = (bool) ($field->is_low_quality_candidate ?? false);

        $warnings = [];
        if ($isHighCardinality) {
            $warnings[] = $estimated !== null
                ? sprintf(
                    'This filter has roughly %s distinct values, which exceeds the recommended '
                    .'cap of %s for sidebar quick filters. Consider narrowing values or removing '
                    .'it from the sidebar.',
                    number_format($estimated),
                    number_format($cap)
                )
                : 'This filter is flagged as high-cardinality and may be unsuitable for sidebar quick filtering.';
        }
        if ($isLowQualityCandidate) {
            $warnings[] = 'This filter contains unusually high metadata variation and may produce noisy quick filter values.';
        }

        // Phase 5.3 — hygiene signals. Tenant scope is required for alias
        // counts (aliases are per-tenant); for duplicate detection it's
        // optional (we run against MetadataOption which is tenant-agnostic
        // for system fields). Caller passes null tenant in tests where
        // no tenant context exists; we then skip alias counting cleanly.
        $aliasCount = 0;
        if ($tenant !== null && $this->canonical !== null) {
            try {
                $aliasCount = $this->canonical->aliasCountForField($field, $tenant);
            } catch (\Throwable $e) {
                // Hygiene queries must never break quality evaluation.
                Log::debug('FolderQuickFilterQualityService: alias count failed', [
                    'field_id' => $field->id,
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $duplicateCandidateCount = 0;
        if ($this->duplicates !== null) {
            try {
                $duplicateCandidateCount = $this->duplicates->candidateGroupCount($field, $tenant);
            } catch (\Throwable $e) {
                Log::debug('FolderQuickFilterQualityService: duplicate scan failed', [
                    'field_id' => $field->id,
                    'tenant_id' => $tenant?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($duplicateCandidateCount > 0) {
            $warnings[] = sprintf(
                'This filter has %d potentially duplicate value group%s — admins can review and '
                .'merge them in the metadata hygiene panel.',
                $duplicateCandidateCount,
                $duplicateCandidateCount === 1 ? '' : 's'
            );
        }

        return [
            'estimated_distinct_value_count' => $estimated,
            'last_facet_usage_at' => $field->last_facet_usage_at instanceof Carbon
                ? $field->last_facet_usage_at->toIso8601String()
                : ($field->last_facet_usage_at !== null ? (string) $field->last_facet_usage_at : null),
            'facet_usage_count' => (int) ($field->facet_usage_count ?? 0),
            'is_high_cardinality' => $isHighCardinality,
            'is_low_quality_candidate' => $isLowQualityCandidate,
            'alias_count' => $aliasCount,
            'duplicate_candidate_count' => $duplicateCandidateCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Compact list of human-readable warnings — convenience wrapper around
     * {@see evaluate()} for callers (admin UI etc.) that only care about the
     * warning vocabulary.
     *
     * @return list<string>
     */
    public function warningsFor(MetadataField $field, ?Tenant $tenant = null): array
    {
        return $this->evaluate($field, $tenant)['warnings'];
    }

    /**
     * "Strongly suggested to NOT enable" — admin-facing recommendation
     * surface. Currently mirrors `is_high_cardinality` but the seam exists
     * so future signals (per-tenant noise score, AI-tag explosion detection)
     * can compose without callers changing their checks.
     */
    public function recommendsSuppression(MetadataField $field, ?Tenant $tenant = null): bool
    {
        $summary = $this->evaluate($field, $tenant);

        return $summary['is_high_cardinality'] || $summary['is_low_quality_candidate'];
    }

    /**
     * Persist a usage tick — typically called when a flyout opens on the
     * field. Increments `facet_usage_count` and stamps `last_facet_usage_at`.
     * Cheap raw-DB update so the request path doesn't pay model overhead.
     *
     * Errors are swallowed and logged: instrumentation must never break a
     * user-facing flow.
     */
    public function recordFacetUsage(MetadataField $field, ?Tenant $tenant = null): void
    {
        try {
            DB::table('metadata_fields')
                ->where('id', $field->id)
                ->update([
                    'last_facet_usage_at' => now(),
                    'facet_usage_count' => DB::raw('COALESCE(facet_usage_count, 0) + 1'),
                ]);
        } catch (\Throwable $e) {
            Log::debug('FolderQuickFilterQualityService: usage tick failed', [
                'field_id' => $field->id,
                'tenant_id' => $tenant?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist the latest cardinality estimate + recompute the high-cardinality
     * flag. Designed to be called from the Phase 5 facet pipeline whenever a
     * fresh count is available; safe to call frequently.
     */
    public function recordCardinalityEstimate(MetadataField $field, int $estimate): void
    {
        $cap = $this->assignment->maxDistinctValuesForQuickFilter();
        $isHigh = $cap > 0 && $estimate > $cap;
        try {
            DB::table('metadata_fields')
                ->where('id', $field->id)
                ->update([
                    'estimated_distinct_value_count' => max(0, $estimate),
                    'is_high_cardinality' => $isHigh,
                ]);
        } catch (\Throwable $e) {
            Log::debug('FolderQuickFilterQualityService: cardinality update failed', [
                'field_id' => $field->id,
                'estimate' => $estimate,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
