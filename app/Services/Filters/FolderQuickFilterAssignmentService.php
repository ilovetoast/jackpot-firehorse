<?php

namespace App\Services\Filters;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\MetadataFieldVisibility;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Filters\Contracts\FacetCountProvider;
use App\Services\Filters\Contracts\QuickFilterPersonalizationProvider;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 2 — Folder Quick Filter assignment service.
 *
 * Purpose: read and write the per-folder quick filter selection. The persistence
 * lives on the existing {@see metadata_field_visibility} pivot
 * (tenant_id, brand_id, category_id, metadata_field_id), extended in migration
 * 2026_05_14_140000_add_folder_quick_filter_columns_*.
 *
 * Hard rules:
 *   1. Quick filter rows are ALWAYS scoped to a specific category (folder). The
 *      service refuses to write a row with category_id = NULL.
 *   2. Eligibility is ALWAYS validated through
 *      {@see FolderQuickFilterEligibilityService}. An ineligible filter cannot
 *      be enabled — even by an admin — full stop.
 *   3. The service does NOT mutate the existing visibility flags
 *      (is_hidden, is_upload_hidden, is_filter_hidden, is_primary, is_required,
 *      is_edit_hidden). Folder enablement remains the single source of truth
 *      for whether a filter applies to a folder at all; quick-filter is a
 *      strictly additional, opt-in surface on top of that.
 *
 * What this service deliberately DOES NOT do (Phase 3+):
 *   - render the sidebar
 *   - run count / facet queries
 *   - compute or apply weight-based ordering
 *   - call AI services to suggest quick filters
 *   - touch the existing asset filtering pipeline
 */
class FolderQuickFilterAssignmentService
{
    public const SOURCE_SEEDED = 'seeded';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI_SUGGESTED = 'ai_suggested';

    public function __construct(
        protected FolderQuickFilterEligibilityService $eligibility,
        // Phase 5 seam — defaults to NullFacetCountProvider so calls return null.
        protected ?FacetCountProvider $counts = null,
        // Phase 6 seam — defaults to NullQuickFilterPersonalizationProvider so
        // calls return empty lists. Both nullable so tests can construct the
        // service directly without booting the container.
        protected ?QuickFilterPersonalizationProvider $personalization = null,
    ) {}

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, MetadataFieldVisibility> visibility rows (one per
     *   filter) currently enabled as quick filters for this folder. Filters
     *   that have become ineligible after-the-fact are filtered out so callers
     *   can iterate the result without re-checking eligibility.
     *
     *   Ordering: explicit folder_quick_filter_order ascending (NULLs last),
     *   then by the joined metadata_fields.system_label (alphabetical) for
     *   deterministic UI rendering.
     */
    public function getQuickFiltersForFolder(Category $folder): Collection
    {
        $rows = MetadataFieldVisibility::query()
            ->where('tenant_id', $folder->tenant_id)
            ->where('category_id', $folder->id)
            ->where('show_in_folder_quick_filters', true)
            ->where(function ($q) use ($folder) {
                if ($folder->brand_id) {
                    $q->where('brand_id', $folder->brand_id)->orWhereNull('brand_id');
                } else {
                    $q->whereNull('brand_id');
                }
            })
            ->with('metadataField')
            ->get();

        $fieldsById = $rows
            ->pluck('metadataField')
            ->filter()
            ->keyBy('id');

        return $rows
            ->filter(function (MetadataFieldVisibility $row) use ($fieldsById): bool {
                $field = $fieldsById->get($row->metadata_field_id);
                if (! $field instanceof MetadataField) {
                    return false;
                }

                return $this->eligibility->isEligible($field);
            })
            ->sortBy(fn (MetadataFieldVisibility $row) => [
                $row->folder_quick_filter_order === null ? PHP_INT_MAX : $row->folder_quick_filter_order,
                strtolower((string) ($fieldsById->get($row->metadata_field_id)?->system_label ?? '')),
            ])
            ->values();
    }

    /**
     * Phase 3 — batch counterpart to {@see getQuickFiltersForFolder()}.
     *
     * Given a collection of categories from a single tenant, returns a map
     * `[category_id => list<MetadataFieldVisibility>]` ordered the same way
     * `getQuickFiltersForFolder` orders a single folder. Designed so the asset
     * sidebar can hydrate quick filters for every visible folder in **one**
     * round trip (no N+1).
     *
     * Eligibility is re-checked per-row with no per-call DB hit because the
     * fields are pre-loaded with `with('metadataField')`.
     *
     * @param  iterable<Category>|\Illuminate\Support\Collection<int, Category>  $folders
     * @return array<int, list<MetadataFieldVisibility>>
     */
    public function getQuickFiltersForFolders(iterable $folders): array
    {
        $foldersById = [];
        foreach ($folders as $folder) {
            if (! $folder instanceof Category || ! $folder->exists) {
                continue;
            }
            $foldersById[(int) $folder->id] = $folder;
        }
        if ($foldersById === []) {
            return [];
        }

        $tenantIds = array_values(array_unique(array_map(
            fn (Category $c) => (int) $c->tenant_id,
            $foldersById
        )));
        if (count($tenantIds) > 1) {
            throw new InvalidArgumentException(
                'getQuickFiltersForFolders only supports a single tenant per call.'
            );
        }
        $tenantId = $tenantIds[0];

        $brandIds = array_values(array_unique(array_filter(array_map(
            fn (Category $c) => $c->brand_id,
            $foldersById
        ))));

        $rows = MetadataFieldVisibility::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('category_id', array_keys($foldersById))
            ->where('show_in_folder_quick_filters', true)
            ->where(function ($q) use ($brandIds) {
                $q->whereNull('brand_id');
                if ($brandIds !== []) {
                    $q->orWhereIn('brand_id', $brandIds);
                }
            })
            ->with('metadataField')
            ->get();

        // Eligibility-gate up front so callers iterate clean data.
        $rows = $rows->filter(function (MetadataFieldVisibility $row) {
            $field = $row->metadataField;

            return $field instanceof MetadataField && $this->eligibility->isEligible($field);
        });

        $bucketed = [];
        foreach ($foldersById as $categoryId => $folder) {
            $forFolder = $rows
                ->filter(function (MetadataFieldVisibility $row) use ($folder, $categoryId) {
                    if ((int) $row->category_id !== $categoryId) {
                        return false;
                    }
                    if ($row->brand_id === null) {
                        return true;
                    }

                    return (int) $row->brand_id === (int) ($folder->brand_id ?? 0);
                })
                ->sortBy(fn (MetadataFieldVisibility $row) => [
                    $row->folder_quick_filter_order === null
                        ? PHP_INT_MAX
                        : (int) $row->folder_quick_filter_order,
                    strtolower((string) ($row->metadataField?->system_label ?? '')),
                ])
                ->values()
                ->all();

            $bucketed[$categoryId] = $forFolder;
        }

        return $bucketed;
    }

    public function isQuickFilterEnabled(Category $folder, MetadataField $filter): bool
    {
        $row = $this->findRow($folder, $filter);

        return $row !== null && (bool) $row->show_in_folder_quick_filters;
    }

    /**
     * Cheap admin/UI helper: a filter is "available" (the toggle should be live
     * and clickable) when the eligibility service says so. Used by the admin
     * UI to render disabled toggles with explanations for ineligible rows.
     */
    public function supportsFolderQuickFiltering(MetadataField $filter): bool
    {
        return $this->eligibility->isEligible($filter);
    }

    /**
     * Forward to {@see FolderQuickFilterEligibilityService::reasonIneligible()}
     * so callers don't have to depend on two services.
     */
    public function reasonIneligible(MetadataField $filter): ?string
    {
        return $this->eligibility->reasonIneligible($filter);
    }

    /**
     * Phase 2 future-extension stub. Returns true for the type set the Phase 2
     * eligibility service permits (single_select / multi_select / boolean).
     * Phase 5 will plug in real cardinality + storage signals here.
     */
    public function supportsFacetCounts(MetadataField $filter): bool
    {
        return $this->eligibility->isEligible($filter);
    }

    /**
     * Phase 5 efficiency check. Returns false when:
     *   - the filter is no longer eligible, OR
     *   - the {@see FacetCountProvider} reports an estimated distinct count
     *     above {@see maxDistinctValuesForQuickFilter()}.
     *
     * Phase 2 binds {@see \App\Services\Filters\Facet\NullFacetCountProvider}
     * which returns null (unknown). When the count is unknown we give the
     * filter the benefit of the doubt — the alternative would be to block all
     * filters until Phase 5 ships, which defeats Phase 2's goal of getting
     * the architecture wired now.
     */
    public function isFacetEfficient(MetadataField $filter): bool
    {
        if (! $this->eligibility->isEligible($filter)) {
            return false;
        }

        $estimated = $this->estimatedDistinctValueCount($filter);
        if ($estimated === null) {
            return true;
        }

        return $estimated <= $this->maxDistinctValuesForQuickFilter();
    }

    /**
     * Returns the cardinality cap above which a filter is considered too noisy
     * for a quick filter strip ("AI generated 25,000 Subject values" scenario).
     *
     * Phase 2 only reads the value; nothing enforces it at write time — that
     * is Phase 5's job.
     */
    public function maxDistinctValuesForQuickFilter(): int
    {
        $configured = config('categories.folder_quick_filters.max_distinct_values_for_quick_filter');

        return is_int($configured) ? max(0, $configured) : 100;
    }

    /**
     * Estimated distinct value count for a filter, optionally scoped. Delegates
     * to the bound {@see FacetCountProvider} which returns null when unknown.
     *
     * Phase 2 callers can already call this and get a stable null; Phase 5
     * swaps the provider and the same call site gets real numbers.
     */
    public function estimatedDistinctValueCount(
        MetadataField $filter,
        ?Tenant $tenant = null,
        ?Brand $brand = null,
        ?Category $folder = null,
    ): ?int {
        if (! $this->counts) {
            return null;
        }

        return $this->counts->estimateDistinctValueCount($filter, $tenant, $brand, $folder);
    }

    /**
     * Phase 5 quality gate hook. Today: a thin wrapper around eligibility +
     * cardinality. Future phases will plug in additional signals here:
     *   - low usage suppression
     *   - low-quality AI metadata suppression
     *   - per-tenant disabled_by_admin flag (when added)
     *
     * Existing call sites that want to render or recommend a filter should
     * route through this, not directly through eligibility, so future signals
     * take effect everywhere at once.
     */
    public function passesQualityGuards(MetadataField $filter): bool
    {
        if (! $this->eligibility->isEligible($filter)) {
            return false;
        }

        return $this->isFacetEfficient($filter);
    }

    // -----------------------------------------------------------------
    // Personalization (Phase 6 seam)
    // -----------------------------------------------------------------

    /**
     * Phase 6 seam — pinned, recent, role-default, and favorite filter IDs
     * for the user. Phase 2 returns the empty shape so call sites compile and
     * remain forward-compatible.
     *
     * @return array{
     *     pinned: list<int>,
     *     recent: list<int>,
     *     role_defaults: list<int>,
     *     favorites: list<int>,
     * }
     */
    public function getPersonalizedFilterIds(
        User $user,
        Tenant $tenant,
        ?Category $folder = null,
        int $recentLimit = 10,
    ): array {
        if (! $this->personalization) {
            return [
                'pinned' => [],
                'recent' => [],
                'role_defaults' => [],
                'favorites' => [],
            ];
        }

        return [
            'pinned' => $this->personalization->getPinnedFilterIds($user, $tenant, $folder),
            'recent' => $this->personalization->getRecentlyUsedFilterIds($user, $tenant, $folder, $recentLimit),
            'role_defaults' => $this->personalization->getRoleDefaultFilterIds($user, $tenant, $folder),
            'favorites' => $this->personalization->getFavoriteFilterIds($user, $tenant, $folder),
        ];
    }

    // -----------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------

    /**
     * Enable a filter as a quick filter on a folder.
     *
     * @param  array<string, mixed>  $opts Optional attributes:
     *   - order:  int|null
     *   - weight: int|null
     *   - source: string|null  defaults to SOURCE_MANUAL
     *
     * @throws InvalidArgumentException When the filter is not eligible. Callers
     *   that want a soft check should use {@see supportsFolderQuickFiltering()}
     *   before invoking this.
     */
    public function enableQuickFilter(Category $folder, MetadataField $filter, array $opts = []): MetadataFieldVisibility
    {
        $this->assertEligible($filter);
        $this->assertFolder($folder);

        $source = $this->normalizeSource($opts['source'] ?? self::SOURCE_MANUAL);
        $order = $this->normalizeOrder($opts['order'] ?? null);
        $weight = $this->normalizeWeight($opts['weight'] ?? null);

        $row = $this->upsertRow($folder, $filter, [
            'show_in_folder_quick_filters' => true,
            'folder_quick_filter_order' => $order,
            'folder_quick_filter_weight' => $weight,
            'folder_quick_filter_source' => $source,
        ]);

        return $row;
    }

    /**
     * Disable a filter as a quick filter on a folder. Does NOT modify any of
     * the existing visibility flags — the filter is still part of the folder's
     * normal field list, it just no longer appears in the quick-filter strip.
     */
    public function disableQuickFilter(Category $folder, MetadataField $filter): void
    {
        $this->assertFolder($folder);

        $row = $this->findRow($folder, $filter);
        if ($row === null) {
            return;
        }

        $row->fill([
            'show_in_folder_quick_filters' => false,
            'folder_quick_filter_order' => null,
            'folder_quick_filter_weight' => null,
            // Source is intentionally retained so we can audit whether the row
            // was originally seeded vs manually configured. Phase 3+ may use
            // this to decide whether re-running the seeder should re-enable
            // a previously-seeded row.
        ])->save();
    }

    public function updateQuickFilterOrder(Category $folder, MetadataField $filter, ?int $order): void
    {
        $this->assertEligible($filter);
        $this->assertFolder($folder);

        $this->upsertRow($folder, $filter, [
            'folder_quick_filter_order' => $this->normalizeOrder($order),
        ]);
    }

    public function updateQuickFilterWeight(Category $folder, MetadataField $filter, ?int $weight): void
    {
        $this->assertEligible($filter);
        $this->assertFolder($folder);

        $this->upsertRow($folder, $filter, [
            'folder_quick_filter_weight' => $this->normalizeWeight($weight),
        ]);
    }

    public function setQuickFilterSource(Category $folder, MetadataField $filter, ?string $source): void
    {
        $this->assertFolder($folder);

        $this->upsertRow($folder, $filter, [
            'folder_quick_filter_source' => $source === null ? null : $this->normalizeSource($source),
        ]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Locate the (tenant, brand, category, filter) visibility row, if any.
     */
    private function findRow(Category $folder, MetadataField $filter): ?MetadataFieldVisibility
    {
        $query = MetadataFieldVisibility::query()
            ->where('tenant_id', $folder->tenant_id)
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $filter->id);

        if ($folder->brand_id) {
            $query->where(function ($q) use ($folder) {
                $q->where('brand_id', $folder->brand_id)->orWhereNull('brand_id');
            });
        } else {
            $query->whereNull('brand_id');
        }

        return $query->orderByDesc('brand_id')->first();
    }

    /**
     * Idempotent upsert of a category-scoped visibility row. Other visibility
     * flags retain their current values (or false on first insert) — this
     * service strictly owns the four quick-filter columns.
     *
     * @param  array<string, mixed>  $quickFilterAttrs
     */
    private function upsertRow(Category $folder, MetadataField $filter, array $quickFilterAttrs): MetadataFieldVisibility
    {
        $existing = $this->findRow($folder, $filter);

        if ($existing !== null) {
            $existing->fill($quickFilterAttrs)->save();

            return $existing;
        }

        $row = new MetadataFieldVisibility();
        $row->fill(array_merge([
            'metadata_field_id' => $filter->id,
            'tenant_id' => $folder->tenant_id,
            'brand_id' => $folder->brand_id,
            'category_id' => $folder->id,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'is_edit_hidden' => false,
            'is_primary' => false,
            'is_required' => false,
        ], $quickFilterAttrs));
        $row->save();

        return $row;
    }

    private function assertEligible(MetadataField $filter): void
    {
        if ($this->eligibility->isEligible($filter)) {
            return;
        }

        $reason = $this->eligibility->reasonIneligible($filter);
        $explanation = $this->eligibility->explainReason($reason) ?? 'Filter is not eligible.';

        throw new InvalidArgumentException(
            "Cannot enable quick filter for metadata_field {$filter->id}: {$explanation}"
        );
    }

    private function assertFolder(Category $folder): void
    {
        if (! $folder->exists || $folder->id === null) {
            throw new InvalidArgumentException('Folder must be a persisted Category.');
        }
        if ($folder->tenant_id === null) {
            throw new InvalidArgumentException('Folder must have a tenant_id.');
        }
    }

    /**
     * @param  string|null  $source One of the allowed source values.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeSource(?string $source): string
    {
        if ($source === null || $source === '') {
            return self::SOURCE_MANUAL;
        }

        $allowed = array_values((array) config('categories.folder_quick_filters.sources', []));
        // Be permissive when config is unset (eg in unit-test fixtures) but
        // strict when an explicit allow-list is configured.
        if ($allowed === []) {
            return $source;
        }

        if (! in_array($source, $allowed, true)) {
            throw new InvalidArgumentException(
                "Unknown folder_quick_filter_source '{$source}'. Allowed: ".implode(', ', $allowed)
            );
        }

        return $source;
    }

    private function normalizeOrder(?int $order): ?int
    {
        if ($order === null) {
            return null;
        }
        if ($order < 0) {
            throw new InvalidArgumentException('folder_quick_filter_order must be >= 0.');
        }

        return $order;
    }

    private function normalizeWeight(?int $weight): ?int
    {
        if ($weight === null) {
            return null;
        }
        if ($weight < 0) {
            throw new InvalidArgumentException('folder_quick_filter_weight must be >= 0.');
        }

        return $weight;
    }

    /**
     * Convenience for callers that want to report a precise error message
     * matching whatever the eligibility service's current vocabulary is.
     */
    public function explainIneligible(MetadataField $filter): ?string
    {
        return $this->eligibility->explainReason($this->eligibility->reasonIneligible($filter));
    }

    /**
     * Phase-2-internal helper to assert the schema is present. Used by the
     * seeder so missing migrations fail loudly rather than silently no-oping.
     */
    public static function assertSchemaInstalled(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn(
            'metadata_field_visibility',
            'show_in_folder_quick_filters'
        )) {
            throw new RuntimeException(
                'Folder quick filter columns missing on metadata_field_visibility. '
                .'Run migration 2026_05_14_140000_add_folder_quick_filter_columns_*.'
            );
        }
    }
}
