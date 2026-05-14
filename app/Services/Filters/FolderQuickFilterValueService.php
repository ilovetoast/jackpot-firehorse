<?php

namespace App\Services\Filters;

use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\Facet\AssetFacetCountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Phase 4 / 5 — resolves the selectable VALUES for a single quick-filter
 * flyout, optionally hydrated with contextual asset counts.
 *
 * This service is intentionally lightweight: it loads `metadata_options` rows
 * for the field, applies tenant/brand/category visibility (matching the
 * existing inheritance order in MetadataSchemaResolver: tenant < brand <
 * category, category wins), then truncates to a configurable ceiling to keep
 * sidebar payloads small.
 *
 * Phase 5 additions:
 *  - When counts are enabled (config flag), we ask the bound
 *    {@see AssetFacetCountService} for per-value counts AFTER truncation —
 *    counts are only computed for VISIBLE values. The provider is responsible
 *    for excluding the current dimension from `$activeFilters`.
 *  - A null return from the provider (unknown / failure) leaves the values
 *    untouched; the UI degrades gracefully to label-only.
 *
 * Constraints by design:
 *  - NO server-side fuzzy search. Client-side filter only when count >
 *    `search_threshold`.
 *  - NO new value system. We mirror the same option source used by the existing
 *    filterable_schema so quick filters always show a strict subset of what
 *    the normal filter system already exposes.
 *  - NO eligibility / assignment checks here. Those are caller responsibility
 *    (the controller calls FolderQuickFilterEligibilityService and
 *    FolderQuickFilterAssignmentService::isQuickFilterEnabled before invoking).
 *
 * Boolean fields short-circuit to a normalized two-option shape.
 */
class FolderQuickFilterValueService
{
    /** @var int Hard ceiling fallback when config is missing/garbage. */
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        protected AssetFacetCountService $facetCounts,
    ) {}

    /**
     * Resolve the value list payload for `(folder, field)`.
     *
     * @param  array<string, array{operator: string, value: mixed}>|null  $activeFilters
     *         Other-dimension active filters from the page URL. Forwarded to
     *         the facet count provider so counts reflect "matches everything
     *         else the user has selected, EXCLUDING the dimension being
     *         counted". Pass `null` to skip context entirely.
     * @return array{
     *     field: array{id:int, key:string, label:string, type:string},
     *     values: list<array{value:bool|string, label:string, selected:bool, count?: int|null}>,
     *     has_more: bool,
     *     limit: int,
     *     counts_available: bool
     * }
     */
    public function getValues(
        Category $folder,
        MetadataField $field,
        ?array $activeFilters = null,
    ): array {
        if (! $folder->exists) {
            throw new InvalidArgumentException('Folder must be persisted.');
        }
        if (! $field->exists) {
            throw new InvalidArgumentException('Field must be persisted.');
        }

        $limit = $this->resolveLimit();
        $type = $this->normalizeType((string) ($field->type ?? ''));

        $values = match ($type) {
            'boolean' => $this->booleanValues(),
            default => $this->selectValues($folder, $field, $limit + 1),
        };

        // For non-boolean we fetched limit+1 to detect overflow without an
        // extra COUNT query. Trim back to the visible page.
        $hasMore = false;
        if ($type !== 'boolean' && count($values) > $limit) {
            $hasMore = true;
            $values = array_slice($values, 0, $limit);
        }

        $countsAvailable = false;
        if ($this->countsEnabled() && $values !== []) {
            $countsAvailable = $this->hydrateCounts($values, $folder, $field, $activeFilters);
        }

        return [
            'field' => [
                'id' => (int) $field->id,
                'key' => (string) ($field->key ?? ''),
                'label' => (string) ($field->system_label ?? ($field->key ?? '')),
                'type' => $type,
            ],
            'values' => $values,
            'has_more' => $hasMore,
            'limit' => $limit,
            'counts_available' => $countsAvailable,
        ];
    }

    /**
     * Master switch for the count column. When false, the provider is never
     * called and the response omits a meaningful `counts_available` flag.
     */
    public function countsEnabled(): bool
    {
        return (bool) config('categories.folder_quick_filters.counts_enabled', true);
    }

    /**
     * Ask the facet provider for counts on the visible value set, then merge
     * them into `$values` in-place. Returns whether any usable count was
     * obtained — the UI uses this to decide whether to render the count
     * column at all (vs gracefully omitting it).
     *
     * Provider returns:
     *   - array<string,int>  → counts available; missing keys default to 0.
     *   - null               → unknown / failure; leave values untouched.
     *
     * @param  list<array{value:bool|string, label:string, selected:bool}>  $values
     * @param  array<string, array{operator: string, value: mixed}>|null    $activeFilters
     */
    private function hydrateCounts(
        array &$values,
        Category $folder,
        MetadataField $field,
        ?array $activeFilters,
    ): bool {
        try {
            $tenant = $folder->tenant;
            if ($tenant === null) {
                return false;
            }
            $counts = $this->facetCounts->countOptionsForFolder(
                $field,
                $tenant,
                $folder->brand,
                $folder,
                $activeFilters,
            );
        } catch (\Throwable $e) {
            Log::warning('FolderQuickFilterValueService: facet count provider threw', [
                'folder_id' => $folder->id,
                'field_id' => $field->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($counts === null) {
            return false;
        }

        foreach ($values as $idx => $row) {
            $key = is_bool($row['value'])
                ? ($row['value'] ? 'true' : 'false')
                : (string) $row['value'];
            $values[$idx]['count'] = (int) ($counts[$key] ?? 0);
        }

        return true;
    }

    /**
     * The ceiling enforced server-side. Defaults to 20 when config is missing
     * or invalid; clamped to a positive integer so a misconfigured 0 does not
     * make the flyout silently empty.
     */
    public function resolveLimit(): int
    {
        $configured = (int) config(
            'categories.folder_quick_filters.max_visible_values_per_filter',
            self::DEFAULT_LIMIT
        );

        return $configured > 0 ? $configured : self::DEFAULT_LIMIT;
    }

    /**
     * Map raw stored field-type strings to the canonical type the flyout
     * understands. Eligibility uses the same vocabulary, so anything outside
     * the allowed set should already be blocked upstream — but if a future
     * type slips through, it falls into the `select`-style code path which
     * gracefully returns an empty value list.
     */
    public function normalizeType(string $type): string
    {
        $type = strtolower($type);

        return match ($type) {
            'multi_select', 'multiselect' => 'multiselect',
            'single_select', 'select' => 'select',
            'boolean', 'bool', 'checkbox' => 'boolean',
            default => $type,
        };
    }

    /**
     * Yes/No is hardcoded here (and matches `FilterFieldInput`'s boolean
     * widget) rather than read from per-field config: existing field rows do
     * not carry boolean labels, and inventing a "boolean_labels" column for
     * Phase 4 would balloon scope.
     *
     * @return list<array{value:bool, label:string, selected:bool}>
     */
    private function booleanValues(): array
    {
        return [
            ['value' => true, 'label' => 'Yes', 'selected' => false],
            ['value' => false, 'label' => 'No', 'selected' => false],
        ];
    }

    /**
     * Pull all metadata_options for the field, then drop those hidden by
     * tenant/brand/category visibility. We fetch `$fetchLimit` rows so the
     * caller can detect overflow with a single query.
     *
     * @return list<array{value:string, label:string, selected:bool}>
     */
    private function selectValues(Category $folder, MetadataField $field, int $fetchLimit): array
    {
        $hiddenOptionIds = $this->loadHiddenOptionIds(
            (int) $folder->tenant_id,
            $folder->brand_id !== null ? (int) $folder->brand_id : null,
            (int) $folder->id,
        );

        // Order by system_label so the flyout matches the way options render
        // elsewhere (e.g. MetadataSchemaResolver also orders by system_label).
        // We over-fetch by 1 (caller passes limit+1) to detect has_more.
        $rows = DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->orderBy('system_label', 'asc')
            ->get();

        $values = [];
        foreach ($rows as $row) {
            if (isset($hiddenOptionIds[$row->id])) {
                continue;
            }
            $values[] = [
                'value' => (string) $row->value,
                'label' => (string) ($row->system_label ?? $row->value),
                'selected' => false,
            ];
            if (count($values) >= $fetchLimit) {
                break;
            }
        }

        return $values;
    }

    /**
     * Hidden-option lookup mirroring MetadataSchemaResolver::loadOptionVisibility
     * inheritance order (tenant < brand < category, category wins). We only
     * care about the boolean "is hidden in this folder" outcome — everything
     * else gets filtered upstream.
     *
     * @return array<int, true> map of option_id -> true (hidden)
     */
    private function loadHiddenOptionIds(int $tenantId, ?int $brandId, int $categoryId): array
    {
        $query = DB::table('metadata_option_visibility')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($brandId, $categoryId) {
                $q->where(function ($sub) {
                    $sub->whereNull('brand_id')->whereNull('category_id');
                });
                if ($brandId !== null) {
                    $q->orWhere(function ($sub) use ($brandId) {
                        $sub->where('brand_id', $brandId)->whereNull('category_id');
                    });
                    $q->orWhere(function ($sub) use ($brandId, $categoryId) {
                        $sub->where('brand_id', $brandId)->where('category_id', $categoryId);
                    });
                }
            })
            // Highest specificity first so we only adopt the most-specific
            // override for each option_id.
            ->orderByRaw(
                'CASE
                    WHEN category_id IS NOT NULL THEN 3
                    WHEN brand_id IS NOT NULL THEN 2
                    ELSE 1
                END DESC'
            )
            ->get();

        $hidden = [];
        $seen = [];
        foreach ($query as $row) {
            if (isset($seen[$row->metadata_option_id])) {
                continue;
            }
            $seen[$row->metadata_option_id] = true;
            if ((bool) $row->is_hidden) {
                $hidden[$row->metadata_option_id] = true;
            }
        }

        return $hidden;
    }
}
