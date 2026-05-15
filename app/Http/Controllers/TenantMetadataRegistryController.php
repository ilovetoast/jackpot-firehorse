<?php

/**
 * ⚠️ ARCHITECTURE RULE:
 * Schema::hasColumn() and other runtime schema introspection
 * are forbidden in request lifecycle code.
 *
 * Schema is controlled by migrations and must be assumed valid.
 */

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataVisibilityProfile;
use App\Models\SystemCategory;
use App\Models\Tenant;
use App\Services\CategoryVisibilityLimitService;
use App\Services\MetadataOptionEditGuard;
use App\Services\SystemCategoryService;
use App\Services\SystemMetadataOptionProvisioningService;
use App\Services\TenantMetadataFieldService;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use App\Services\TenantMetadataRegistryService;
use App\Services\TenantMetadataVisibilityService;
use App\Support\Metadata\CategoryTypeResolver;
use App\Support\MetadataCache;
use App\Support\MetadataFieldFilterEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant Metadata Registry Controller
 *
 * Phase C4 + Phase G: Tenant-level metadata registry and visibility management.
 *
 * ⚠️ PHASE LOCK: Phase G complete. This controller is production-locked. Do not refactor.
 *
 * This controller provides:
 * - View of system and tenant metadata fields
 * - Management of tenant visibility overrides
 * - Category suppression for tenant fields
 *
 * Authorization:
 * - View: metadata.registry.view OR metadata.tenant.visibility.manage
 * - Manage: metadata.tenant.visibility.manage
 * - Tenant fields: metadata.tenant.field.manage
 */
class TenantMetadataRegistryController extends Controller
{
    public function __construct(
        protected TenantMetadataRegistryService $registryService,
        protected TenantMetadataVisibilityService $visibilityService,
        protected TenantMetadataFieldService $fieldService
    ) {}

    /**
     * Legacy URL: the Inertia "Categories & Fields" workspace moved to Manage → Categories.
     *
     * GET /tenant/metadata/registry → redirect to manage.categories (same permission gate).
     * Preserves ?category= and ?filter=; maps ?category_id= to a slug when possible.
     */
    public function index(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $categorySlug = $request->query('category');
        $categorySlug = is_string($categorySlug) && $categorySlug !== '' ? $categorySlug : null;

        if ($categorySlug === null && $request->filled('category_id')) {
            $cid = (int) $request->query('category_id');
            if ($cid > 0) {
                $row = Category::query()
                    ->where('id', $cid)
                    ->whereHas('brand', fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->first();
                if ($row) {
                    $categorySlug = $row->slug ?? Str::slug($row->name);
                }
            }
        }

        $filter = $request->query('filter');
        $filter = is_string($filter) && $filter !== '' ? $filter : null;

        $params = array_filter([
            'category' => $categorySlug,
            'filter' => $filter,
        ], fn ($v) => $v !== null && $v !== '');

        return redirect()->route('manage.categories', $params);
    }

    /**
     * Platform catalog folders not yet copied to this brand (for Metadata → By category sidebar).
     * Readable with metadata registry view permission — unlike category-form-data, which requires brand_categories.manage.
     *
     * GET /api/tenant/metadata/brands/{brand}/available-system-categories
     */
    public function availableSystemCategories(Brand $brand): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $systemTemplates = app(SystemCategoryService::class)->getAllTemplates();
        $categories = $brand->categories()->get();

        // All template row ids per slug + asset_type (every version), so brand rows linked to an older
        // system_categories.id still count as "already on brand" vs the latest template row.
        $templateIdsBySlugKey = [];
        foreach (SystemCategory::query()->get(['id', 'slug', 'asset_type']) as $row) {
            $at = $row->asset_type instanceof \BackedEnum ? $row->asset_type->value : (string) $row->asset_type;
            $key = Str::lower((string) $row->slug).'|'.$at;
            $templateIdsBySlugKey[$key] ??= [];
            $templateIdsBySlugKey[$key][] = (int) $row->id;
        }

        $available = [];
        $limitService = app(CategoryVisibilityLimitService::class);
        $visibleCategoryLimits = $limitService->limitsPayloadForBrand($brand);

        foreach ($systemTemplates as $template) {
            $typeVal = $template->asset_type->value;
            $slugKey = Str::lower((string) $template->slug).'|'.$typeVal;
            $relatedTemplateIds = $templateIdsBySlugKey[$slugKey] ?? [(int) $template->id];

            $exists = $categories->contains(function (Category $c) use ($template, $typeVal, $relatedTemplateIds) {
                if ($c->asset_type->value !== $typeVal) {
                    return false;
                }
                if (Str::lower((string) $c->slug) === Str::lower((string) $template->slug)) {
                    return true;
                }
                $sid = $c->system_category_id !== null ? (int) $c->system_category_id : null;

                return $sid !== null && $sid > 0 && in_array($sid, $relatedTemplateIds, true);
            });

            if (! $exists) {
                $assetTypeEnum = AssetType::tryFrom($typeVal);
                $visibleCapBlocksAdd = false;
                $visibleSlotsRemaining = null;
                if ($assetTypeEnum && $limitService->appliesTo($assetTypeEnum)) {
                    $max = $limitService->maxFor($assetTypeEnum);
                    $visible = $limitService->countVisible($brand, $assetTypeEnum);
                    $visibleSlotsRemaining = max(0, $max - $visible);
                    if (! $template->is_hidden && $visible >= $max) {
                        $visibleCapBlocksAdd = true;
                    }
                }

                $available[] = [
                    'system_category_id' => $template->id,
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon ?? 'folder',
                    'asset_type' => $template->asset_type->value,
                    'is_private' => $template->is_private,
                    'is_hidden' => $template->is_hidden,
                    'visible_slots_remaining' => $visibleSlotsRemaining,
                    'visible_cap_blocks_add' => $visibleCapBlocksAdd,
                ];
            }
        }

        return response()->json([
            'available_system_templates' => $available,
            'visible_category_limits' => $visibleCategoryLimits,
        ]);
    }

    /**
     * Count platform select options hidden until tenant opts in (provision_source=system_seed).
     */
    public function pendingSystemOptionRevealCount(): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $count = app(SystemMetadataOptionProvisioningService::class)->countPendingSystemSeededHides($tenant->id);

        return response()->json(['pending_count' => $count]);
    }

    /**
     * Reveal all auto-hidden platform options for this tenant (does not touch manual hides).
     */
    public function revealPendingSystemOptions(): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $canManage = $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canManage) {
            abort(403, 'You do not have permission to update metadata visibility.');
        }

        $deleted = app(SystemMetadataOptionProvisioningService::class)->revealSystemSeededOptionHidesForTenant($tenant->id);

        MetadataCache::bumpVersion($tenant->id);

        return response()->json(['success' => true, 'rows_removed' => $deleted]);
    }

    /**
     * Count category-scoped field visibility rows waiting for tenant opt-in (provision_source=system_seed).
     */
    public function pendingSystemFieldSeedCount(): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $count = $this->visibilityService->countPendingSystemSeededFieldRows((int) $tenant->id);

        return response()->json(['pending_count' => $count]);
    }

    /**
     * Reveal all platform field surfaces held back with system_seed for this tenant.
     */
    public function revealPendingSystemFieldSeeds(): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $canManage = $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canManage) {
            abort(403, 'You do not have permission to update metadata visibility.');
        }

        $updated = $this->visibilityService->revealSystemSeededFieldVisibilityForTenant((int) $tenant->id);

        MetadataCache::bumpVersion($tenant->id);

        return response()->json(['success' => true, 'rows_updated' => $updated]);
    }

    /**
     * Get the metadata registry (API endpoint).
     *
     * GET /api/tenant/metadata/registry
     */
    public function getRegistry(): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $registry = $this->registryService->getRegistry($tenant);

        return response()->json($registry);
    }

    /**
     * Folder schema helper: fields on/off for this folder, option previews, access summary.
     *
     * {@see \App\Services\Metadata\AssetMetadataDrawerFieldIdsResolver} defines which manageable fields
     * count as "on" for the first list: folder enabled in Manage and shown on the asset metadata form
     * (not only the folder visibility toggle).
     *
     * GET /api/tenant/metadata/categories/{category}/folder-schema
     */
    public function folderSchema(int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $categoryModel = Category::query()
            ->where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->with(['accessRules'])
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin'], true);
        $canToggleFolderField = $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');
        $canEditDefinitions = $isTenantOwnerOrAdmin || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage');
        $canManageOptionValues = $isTenantOwnerOrAdmin
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')
            || $user->hasPermissionForTenant($tenant, 'metadata.fields.values.manage')
            || $user->hasPermissionForTenant($tenant, 'metadata.bulk_edit');

        $registry = $this->registryService->getRegistry($tenant);
        $resolvedType = CategoryTypeResolver::resolve($categoryModel->slug ?? null);
        $primaryTypeKey = $resolvedType['field_key'] ?? null;
        $typeFamilyKeys = config('metadata_field_families.type.fields', []);
        if (! is_array($typeFamilyKeys)) {
            $typeFamilyKeys = [];
        }
        $descriptorKeys = ['environment_type', 'subject_type'];

        $shouldIncludeManageable = function (string $key) use ($primaryTypeKey, $typeFamilyKeys, $descriptorKeys): bool {
            if (in_array($key, $descriptorKeys, true)) {
                return true;
            }
            if ($primaryTypeKey !== null && $key === $primaryTypeKey) {
                return true;
            }
            if ($typeFamilyKeys !== [] && in_array($key, $typeFamilyKeys, true)) {
                return $primaryTypeKey !== null && $key === $primaryTypeKey;
            }

            return true;
        };

        $rows = [];
        foreach ($registry['system_fields'] ?? [] as $f) {
            $rows[] = ['field' => (array) $f, 'is_system' => true];
        }
        foreach ($registry['tenant_fields'] ?? [] as $f) {
            $rows[] = ['field' => (array) $f, 'is_system' => false];
        }

        $schemaRows = [];
        foreach ($rows as $row) {
            $f = $row['field'];
            $key = (string) ($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $isSystem = $row['is_system'];
            $isAutomated = $isSystem
                && ($f['population_mode'] ?? 'manual') === 'automatic'
                && ($f['readonly'] ?? false) === true;

            if ($isAutomated) {
                $schemaRows[] = array_merge($row, ['is_automated' => true]);
                continue;
            }
            if (! $shouldIncludeManageable($key)) {
                continue;
            }
            $schemaRows[] = array_merge($row, ['is_automated' => false]);
        }

        $fieldIds = array_values(array_unique(array_filter(array_map(
            fn (array $r) => (int) ($r['field']['id'] ?? 0),
            $schemaRows
        ))));

        $suppressedByField = $this->visibilityService->getSuppressedCategoryIdsByFieldIds(
            $tenant,
            $fieldIds,
            (int) $categoryModel->brand_id
        );

        $systemFieldIdsForOptions = [];
        foreach ($schemaRows as $row) {
            if (! $row['is_system']) {
                continue;
            }
            $f = $row['field'];
            $t = (string) ($f['field_type'] ?? $f['type'] ?? '');
            if (in_array($t, ['select', 'multiselect'], true)) {
                $systemFieldIdsForOptions[] = (int) ($f['id'] ?? 0);
            }
        }
        $systemFieldIdsForOptions = array_values(array_unique(array_filter($systemFieldIdsForOptions)));

        $systemOptionsLabels = [];
        if ($systemFieldIdsForOptions !== []) {
            $optionRows = DB::table('metadata_options')
                ->whereIn('metadata_field_id', $systemFieldIdsForOptions)
                ->orderBy('metadata_field_id')
                ->orderBy('id')
                ->get(['metadata_field_id', 'value', 'system_label']);

            foreach ($optionRows as $opt) {
                $fid = (int) $opt->metadata_field_id;
                $systemOptionsLabels[$fid] ??= [];
                $label = (string) ($opt->system_label ?? '');
                if ($label === '') {
                    $label = (string) ($opt->value ?? '');
                }
                if ($label !== '') {
                    $systemOptionsLabels[$fid][] = $label;
                }
            }
        }

        $categoryId = (int) $categoryModel->id;

        // Phase 2 — Folder Quick Filters: pre-load per-(category, field) quick
        // filter rows so buildPayload runs in O(1) per field. Eligibility is
        // evaluated per-row from the field's existing payload — no extra DB
        // query needed for that.
        $quickFilterRowsByField = [];
        $quickFiltersEnabledFeature = (bool) config('categories.folder_quick_filters.enabled', false);
        if ($quickFiltersEnabledFeature && $fieldIds !== []) {
            $quickFilterQuery = DB::table('metadata_field_visibility')
                ->where('tenant_id', $tenant->id)
                ->where('category_id', $categoryId)
                ->whereIn('metadata_field_id', $fieldIds)
                ->select([
                    'metadata_field_id',
                    'show_in_folder_quick_filters',
                    'folder_quick_filter_order',
                    'folder_quick_filter_weight',
                    'folder_quick_filter_source',
                    // Phase 5.2 — pinned per visibility row.
                    'is_pinned_folder_quick_filter',
                    'brand_id',
                ]);
            if ($categoryModel->brand_id) {
                $quickFilterQuery->where(function ($q) use ($categoryModel) {
                    $q->where('brand_id', $categoryModel->brand_id)->orWhereNull('brand_id');
                });
            } else {
                $quickFilterQuery->whereNull('brand_id');
            }
            // Brand-specific row wins over a tenant-wide row when both exist.
            $rowsForCategory = $quickFilterQuery->orderByDesc('brand_id')->get();
            foreach ($rowsForCategory as $r) {
                $fid = (int) $r->metadata_field_id;
                if (! array_key_exists($fid, $quickFilterRowsByField)) {
                    $quickFilterRowsByField[$fid] = $r;
                }
            }
        }
        $quickFilterEligibility = app(FolderQuickFilterEligibilityService::class);

        // Phase 5.2 — Quality summaries keyed by field_id. Evaluated in one
        // batch (single SELECT) so the schema admin endpoint stays O(1) per
        // row. The MetadataField model carries the persisted quality columns
        // and the service derives flags + warning copy from them.
        /** @var array<int, array<string, mixed>> $quickFilterQualityByField */
        $quickFilterQualityByField = [];
        if ($quickFiltersEnabledFeature && $fieldIds !== []) {
            /** @var \App\Services\Filters\FolderQuickFilterQualityService $qualityService */
            $qualityService = app(\App\Services\Filters\FolderQuickFilterQualityService::class);
            $fieldsForQuality = \App\Models\MetadataField::query()
                ->whereIn('id', $fieldIds)
                ->get();
            foreach ($fieldsForQuality as $field) {
                // Phase 5.3 — pass tenant context so the alias_count signal
                // is computed (alias scope is per-tenant; without tenant
                // we'd silently report 0).
                $quickFilterQualityByField[(int) $field->id] = $qualityService->evaluate($field, $tenant);
            }
        }

        // Phase 6 — pending Contextual Navigation Intelligence hints for this
        // (tenant, folder). Batch-loaded so per-field rendering avoids N+1.
        /** @var array<int, list<array<string, mixed>>> $contextualNavHintsByField */
        $contextualNavHintsByField = [];
        if (
            $quickFiltersEnabledFeature
            && $fieldIds !== []
            && $categoryId !== null
            && (bool) config('contextual_navigation_insights.enabled', true)
        ) {
            $contextualNavHintsByField = app(
                \App\Services\ContextualNavigation\ContextualNavigationPayloadService::class
            )->hintsForFolderFields((int) $tenant->id, (int) $categoryId, $fieldIds);
        }

        $buildPayload = function (array $row) use (
            $categoryId,
            $suppressedByField,
            $primaryTypeKey,
            $systemOptionsLabels,
            $quickFilterRowsByField,
            $quickFiltersEnabledFeature,
            $quickFilterEligibility,
            $quickFilterQualityByField,
            $contextualNavHintsByField
        ): array {
            $f = $row['field'];
            $fid = (int) ($f['id'] ?? 0);
            $isSystem = $row['is_system'];
            $isAutomated = $row['is_automated'];
            $key = (string) ($f['key'] ?? '');
            $fieldType = (string) ($f['field_type'] ?? $f['type'] ?? 'text');

            $suppressed = $suppressedByField[$fid] ?? [];
            $enabled = ! in_array($categoryId, $suppressed, true);

            $labels = [];
            if (in_array($fieldType, ['select', 'multiselect'], true)) {
                if ($isSystem) {
                    $labels = $systemOptionsLabels[$fid] ?? [];
                } else {
                    foreach ($f['options'] ?? [] as $opt) {
                        $opt = (array) $opt;
                        $lab = (string) ($opt['label'] ?? $opt['system_label'] ?? $opt['value'] ?? '');
                        if ($lab !== '') {
                            $labels[] = $lab;
                        }
                    }
                }
            }

            $total = count($labels);
            $preview = array_slice($labels, 0, 12);

            $optionEditingRestricted = false;
            if ($isSystem) {
                $optionEditingRestricted = MetadataOptionEditGuard::isRestricted([
                    'key' => $key,
                    'scope' => 'system',
                    'type' => $fieldType,
                    'display_widget' => $f['display_widget'] ?? null,
                ]);
            }

            $hasOptions = in_array($fieldType, ['select', 'multiselect'], true);

            // Phase 2 — Folder Quick Filters: enrich the row with quick-filter
            // status. Strictly additive: never overrides existing keys, and
            // every nested key is null-safe so older clients that ignore the
            // sub-payload behave identically.
            $qfRow = $quickFilterRowsByField[$fid] ?? null;
            $eligibilityInput = ['type' => $fieldType] + $f; // adapter: $f uses field_type
            $isEligibleForQuickFilter = $quickFiltersEnabledFeature
                && $quickFilterEligibility->isEligible($eligibilityInput);
            $ineligibleReason = null;
            if ($quickFiltersEnabledFeature && ! $isEligibleForQuickFilter) {
                $ineligibleReason = $quickFilterEligibility->explainReason(
                    $quickFilterEligibility->reasonIneligible($eligibilityInput)
                );
            }
            $quality = $quickFilterQualityByField[$fid] ?? [
                'estimated_distinct_value_count' => null,
                'last_facet_usage_at' => null,
                'facet_usage_count' => 0,
                'is_high_cardinality' => false,
                'is_low_quality_candidate' => false,
                'warnings' => [],
            ];

            $quickFilter = [
                'feature_enabled' => $quickFiltersEnabledFeature,
                'supported' => $isEligibleForQuickFilter,
                'enabled' => $qfRow !== null && (bool) ($qfRow->show_in_folder_quick_filters ?? false),
                'order' => $qfRow !== null && $qfRow->folder_quick_filter_order !== null
                    ? (int) $qfRow->folder_quick_filter_order
                    : null,
                'weight' => $qfRow !== null && $qfRow->folder_quick_filter_weight !== null
                    ? (int) $qfRow->folder_quick_filter_weight
                    : null,
                'source' => $qfRow?->folder_quick_filter_source ?? null,
                // Phase 5.2 — pinning + quality summary surface.
                'pinned' => $qfRow !== null && (bool) ($qfRow->is_pinned_folder_quick_filter ?? false),
                'quality' => [
                    'is_high_cardinality' => (bool) $quality['is_high_cardinality'],
                    'is_low_quality_candidate' => (bool) $quality['is_low_quality_candidate'],
                    'estimated_distinct_value_count' => $quality['estimated_distinct_value_count'],
                    'facet_usage_count' => (int) ($quality['facet_usage_count'] ?? 0),
                    'last_facet_usage_at' => $quality['last_facet_usage_at'] ?? null,
                    // Phase 5.3 — hygiene signals.
                    'alias_count' => (int) ($quality['alias_count'] ?? 0),
                    'duplicate_candidate_count' => (int) ($quality['duplicate_candidate_count'] ?? 0),
                    'warnings' => $quality['warnings'],
                ],
                // Phase 6 — Contextual Navigation Intelligence hints.
                // Up to 3 pending recommendations / warnings for this
                // (folder, field). Empty array when none or feature off.
                'contextual_nav_hints' => $contextualNavHintsByField[$fid] ?? [],
                'ineligible_reason' => $ineligibleReason,
            ];

            return [
                'id' => $fid,
                'key' => $key,
                'label' => self::folderSchemaFieldLabel($f, $primaryTypeKey),
                'field_type' => $fieldType,
                'is_system' => $isSystem,
                'is_automated' => $isAutomated,
                'enabled_for_folder' => $enabled,
                'options_preview' => $hasOptions ? $preview : [],
                'options_total' => $hasOptions ? $total : 0,
                'values_expandable' => $hasOptions && $total > 0,
                'option_editing_restricted' => $optionEditingRestricted,
                'quick_filter' => $quickFilter,
            ];
        };

        $brandId = $categoryModel->brand_id !== null ? (int) $categoryModel->brand_id : null;
        $brand = $brandId !== null
            ? Brand::query()->where('id', $brandId)->where('tenant_id', $tenant->id)->first()
            : null;
        $userRole = $brand !== null
            ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member')
            : ($user->getRoleForTenant($tenant) ?? 'member');

        $drawerFieldIds = app(\App\Services\Metadata\AssetMetadataDrawerFieldIdsResolver::class)
            ->fieldIdsForCategory($tenant, $brandId, $categoryModel, $userRole);

        $enabledOn = [];
        $enabledAutomated = [];
        $offManageable = [];
        $offAutomated = [];
        foreach ($schemaRows as $row) {
            $payload = $buildPayload($row);
            if ($row['is_automated']) {
                if ($payload['enabled_for_folder']) {
                    $enabledAutomated[] = $payload;
                } else {
                    $offAutomated[] = $payload;
                }
            } elseif ($payload['enabled_for_folder'] && isset($drawerFieldIds[(int) $payload['id']])) {
                $enabledOn[] = $payload;
            } else {
                $offManageable[] = $payload;
            }
        }

        $labelSort = static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']);
        usort($enabledOn, $labelSort);
        usort($enabledAutomated, $labelSort);
        usort($offManageable, $labelSort);
        usort($offAutomated, $labelSort);

        $accessRules = [];
        if (! $categoryModel->is_system && $categoryModel->is_private) {
            foreach ($categoryModel->accessRules as $rule) {
                if ($rule->access_type === 'role') {
                    $accessRules[] = ['type' => 'role', 'role' => $rule->role];
                } elseif ($rule->access_type === 'user') {
                    $accessRules[] = ['type' => 'user', 'user_id' => (int) $rule->user_id];
                }
            }
        }

        $assetType = $categoryModel->asset_type instanceof \BackedEnum
            ? $categoryModel->asset_type->value
            : (string) ($categoryModel->asset_type ?? 'asset');

        return response()->json([
            'category' => [
                'id' => $categoryModel->id,
                'name' => $categoryModel->name,
                'slug' => $categoryModel->slug,
                'asset_type' => $assetType,
                'is_system' => (bool) $categoryModel->is_system,
                'is_private' => (bool) $categoryModel->is_private,
                'access_rules' => $accessRules,
            ],
            'permissions' => [
                'can_toggle_folder_field' => $canToggleFolderField,
                'can_edit_definitions' => $canEditDefinitions,
                'can_manage_option_values' => $canManageOptionValues,
            ],
            'fields_on' => $enabledOn,
            'fields_on_automated' => $enabledAutomated,
            'fields_off' => $offManageable,
            'fields_off_automated' => $offAutomated,
        ]);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function folderSchemaFieldLabel(array $field, ?string $primaryTypeKey): string
    {
        $key = (string) ($field['key'] ?? '');
        if ($key !== '' && str_ends_with($key, '_type')) {
            if ($primaryTypeKey !== null && $key === $primaryTypeKey) {
                return 'Type';
            }

            $fallback = preg_replace('/_type$/', '', $key);
            $fallback = $fallback !== null ? str_replace('_', ' ', $fallback) : '';

            return (string) ($field['label'] ?? $field['system_label'] ?? $fallback ?: 'Field');
        }

        return (string) ($field['label'] ?? $field['system_label'] ?? $key ?: 'Field');
    }

    /**
     * Get archived tenant metadata fields.
     *
     * GET /api/tenant/metadata/fields/archived
     */
    public function getArchivedFields(): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $archived = $this->fieldService->listArchivedFieldsByTenant($tenant);

        return response()->json(['archived_fields' => $archived]);
    }

    /**
     * Set visibility override for a field.
     *
     * POST /api/tenant/metadata/fields/{field}/visibility
     */
    public function setVisibility(Request $request, int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Validate request
        $validated = $request->validate([
            'show_on_upload' => 'nullable|boolean',
            'show_on_edit' => 'nullable|boolean',
            'show_in_filters' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
            'is_required' => 'nullable|boolean', // Category-scoped required field for upload
            'category_id' => 'nullable|integer|exists:categories,id', // Category-scoped primary placement
        ]);

        // Verify field exists and belongs to tenant (if tenant field)
        $fieldRecord = \DB::table('metadata_fields')
            ->where('id', $field)
            ->first();

        if (! $fieldRecord) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        // Reject archived fields
        if (($fieldRecord->archived_at ?? null) !== null) {
            return response()->json(['error' => 'Cannot modify visibility of an archived field'], 422);
        }

        // If tenant field, verify it belongs to this tenant
        if ($fieldRecord->scope === 'tenant' && $fieldRecord->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Field does not belong to this tenant'], 403);
        }

        // C9.2: Handle category-scoped visibility settings (Upload/Edit/Filter) and is_primary
        // If category_id is provided, save category-level overrides instead of tenant-level
        if (isset($validated['category_id'])) {
            $categoryId = (int) $validated['category_id'];
            $brand = app()->bound('brand') ? app('brand') : null;

            // Resolve category (must belong to tenant)
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $tenant->id)
                ->first();

            if (! $category) {
                \Log::error('[TenantMetadataRegistryController] Category not found', [
                    'category_id' => $categoryId,
                    'tenant_id' => $tenant->id,
                ]);

                return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
            }

            // Use brand from context, or resolve from category so save works when context is missing (e.g. fetch from Metadata Registry)
            if (! $brand) {
                $brand = $category->brand;
                if (! $brand) {
                    \Log::error('[TenantMetadataRegistryController] Brand not found for category', [
                        'category_id' => $categoryId,
                        'brand_id' => $category->brand_id,
                    ]);

                    return response()->json(['error' => 'Brand not found for category'], 500);
                }
                \Log::info('[TenantMetadataRegistryController] Resolved brand from category', [
                    'category_id' => $categoryId,
                    'brand_id' => $brand->id,
                ]);
            } else {
                // Ensure category belongs to the context brand
                if ($category->brand_id !== $brand->id) {
                    \Log::error('[TenantMetadataRegistryController] Category does not belong to context brand', [
                        'category_id' => $categoryId,
                        'category_brand_id' => $category->brand_id,
                        'context_brand_id' => $brand->id,
                    ]);

                    return response()->json(['error' => 'Category does not belong to current brand'], 404);
                }
            }

            \Log::info('[TenantMetadataRegistryController] Saving category-scoped visibility', [
                'field_id' => $field,
                'category_id' => $categoryId,
                'validated' => $validated,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);

            // Get or create category-level visibility override
            $existing = \DB::table('metadata_field_visibility')
                ->where('metadata_field_id', $field)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('category_id', $categoryId)
                ->first();

            // Convert show_* flags to is_*_hidden flags
            // Handle string "true"/"false" from JSON (ensure boolean conversion)
            // C9.2: is_hidden is ONLY for category suppression (big toggle), NOT for edit visibility
            // Use is_edit_hidden for Quick View checkbox (show_on_edit)
            $isUploadHidden = isset($validated['show_on_upload']) ? ! filter_var($validated['show_on_upload'], FILTER_VALIDATE_BOOLEAN) : null;
            $isEditHidden = isset($validated['show_on_edit']) ? ! filter_var($validated['show_on_edit'], FILTER_VALIDATE_BOOLEAN) : null;

            $reqShowInFilters = array_key_exists('show_in_filters', $validated)
                ? filter_var($validated['show_in_filters'], FILTER_VALIDATE_BOOLEAN) : null;
            $reqIsPrimary = array_key_exists('is_primary', $validated)
                ? filter_var($validated['is_primary'], FILTER_VALIDATE_BOOLEAN) : null;
            [$normShowInFilters, $normIsPrimary] = MetadataFieldFilterEligibility::normalizeFilterAndPrimaryForSave(
                (string) ($fieldRecord->type ?? 'text'),
                $reqShowInFilters,
                $reqIsPrimary
            );
            $isFilterHidden = null;
            $isPrimary = null;
            if ($normShowInFilters !== null) {
                $isFilterHidden = ! $normShowInFilters;
            }
            if ($normIsPrimary !== null) {
                $isPrimary = $normIsPrimary;
            }

            $isRequired = isset($validated['is_required']) ? filter_var($validated['is_required'], FILTER_VALIDATE_BOOLEAN) : null;

            // always_hidden_fields: never in filters (dimensions, dominant_colors)
            // filter_only_enforced_fields: never in Quick View/Upload/Primary; secondary only when user enables
            $fieldKey = \DB::table('metadata_fields')->where('id', $field)->value('key');
            $alwaysHiddenFields = config('metadata_category_defaults.always_hidden_fields', []);
            $filterOnlyEnforcedFields = config('metadata_category_defaults.filter_only_enforced_fields', []);

            if ($fieldKey && in_array($fieldKey, $alwaysHiddenFields, true)) {
                $isFilterHidden = true;
                $isPrimary = false;
            }
            if ($fieldKey && in_array($fieldKey, $filterOnlyEnforcedFields, true)) {
                $isPrimary = false;
                $isUploadHidden = true;
                $isEditHidden = true;
                // Do NOT force is_filter_hidden - user may enable for secondary filters
            }

            // NOTE: is_hidden is NOT set here - it's only set by category suppression toggle (toggleCategoryField)

            \Log::info('[TenantMetadataRegistryController] Converted visibility flags', [
                'show_on_upload' => $validated['show_on_upload'] ?? 'not set',
                'is_upload_hidden' => $isUploadHidden,
                'existing_record' => $existing ? 'yes' : 'no',
            ]);

            if ($existing) {
                // Update existing category override - only update provided fields
                // C9.2: is_hidden is ONLY for category suppression, NOT for edit visibility
                // Use is_edit_hidden for Quick View checkbox
                $updateData = ['updated_at' => now()];
                if ($isUploadHidden !== null) {
                    $updateData['is_upload_hidden'] = $isUploadHidden;
                }
                if ($isEditHidden !== null) {
                    $updateData['is_edit_hidden'] = $isEditHidden;
                }
                if ($isFilterHidden !== null) {
                    $updateData['is_filter_hidden'] = $isFilterHidden;
                }
                if ($isPrimary !== null) {
                    $updateData['is_primary'] = $isPrimary;
                }
                if ($isRequired !== null) {
                    $updateData['is_required'] = $isRequired;
                }

                \Log::info('[TenantMetadataRegistryController] Updating existing category override', [
                    'record_id' => $existing->id,
                    'update_data' => $updateData,
                ]);

                \DB::table('metadata_field_visibility')
                    ->where('id', $existing->id)
                    ->update($updateData);
            } else {
                // Create new category override
                // Inherit other visibility flags from tenant-level override if exists
                $tenantOverride = \DB::table('metadata_field_visibility')
                    ->where('metadata_field_id', $field)
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('brand_id')
                    ->whereNull('category_id')
                    ->select(['id', 'is_hidden', 'is_upload_hidden', 'is_filter_hidden', 'is_primary', 'is_edit_hidden', 'is_required'])
                    ->first();

                $insertData = [
                    'metadata_field_id' => $field,
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand->id,
                    'category_id' => $categoryId,
                    // C9.2: is_hidden is ONLY for category suppression (big toggle), NOT for edit visibility
                    // Keep is_hidden from tenant override (for category suppression) or default to false
                    'is_hidden' => $tenantOverride ? (bool) $tenantOverride->is_hidden : false,
                    'is_upload_hidden' => $isUploadHidden !== null ? $isUploadHidden : ($tenantOverride ? (bool) $tenantOverride->is_upload_hidden : false),
                    'is_filter_hidden' => $isFilterHidden !== null ? $isFilterHidden : ($tenantOverride ? (bool) $tenantOverride->is_filter_hidden : false),
                    'is_primary' => $isPrimary !== null ? $isPrimary : ($tenantOverride ? (bool) ($tenantOverride->is_primary ?? false) : false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $insertData['is_edit_hidden'] = $isEditHidden !== null ? $isEditHidden : ($tenantOverride ? (bool) ($tenantOverride->is_edit_hidden ?? false) : false);
                $insertData['is_required'] = $isRequired !== null ? $isRequired : ($tenantOverride ? (bool) ($tenantOverride->is_required ?? false) : false);

                \Log::info('[TenantMetadataRegistryController] Creating new category override', [
                    'insert_data' => $insertData,
                ]);

                \DB::table('metadata_field_visibility')->insert($insertData);
            }
        } else {
            // No category_id - save at tenant level (existing behavior)
            $this->visibilityService->setFieldVisibility($tenant, $field, $validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visibility updated successfully',
        ]);
    }

    /**
     * Remove visibility override for a field.
     *
     * DELETE /api/tenant/metadata/fields/{field}/visibility
     */
    public function removeVisibility(int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Remove visibility override
        $this->visibilityService->removeFieldVisibility($tenant, $field);

        return response()->json([
            'success' => true,
            'message' => 'Visibility override removed',
        ]);
    }

    /**
     * PATCH category field visibility (enable/disable toggle).
     * Returns JSON only — no redirect, no flash. Updated field state only.
     *
     * PATCH /api/tenant/metadata/fields/{field}/categories/{category}/visibility
     */
    public function patchCategoryFieldVisibility(Request $request, int $field, int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            return response()->json(['error' => 'You do not have permission to manage metadata visibility.'], 403);
        }

        $categoryModel = Category::where('id', $category)
            ->whereHas('brand', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->id);
            })
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        $isHidden = filter_var($request->input('is_hidden'), FILTER_VALIDATE_BOOLEAN);

        if ($isHidden) {
            $this->visibilityService->suppressForCategory($tenant, $field, $categoryModel);
        } else {
            $this->visibilityService->unsuppressForCategory($tenant, $field, $categoryModel);
        }

        return response()->json([
            'field_id' => $field,
            'category_id' => $category,
            'is_hidden' => $isHidden,
        ]);
    }

    /**
     * Suppress a field for a category.
     *
     * POST /api/tenant/metadata/fields/{field}/categories/{category}/suppress
     */
    public function suppressForCategory(int $field, int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Verify category belongs to tenant
        $categoryModel = Category::where('id', $category)
            ->whereHas('brand', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->id);
            })
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        // Suppress field for category
        $this->visibilityService->suppressForCategory($tenant, $field, $categoryModel);

        return response()->json([
            'success' => true,
            'message' => 'Field suppressed for category',
        ]);
    }

    /**
     * Unsuppress a field for a category.
     *
     * DELETE /api/tenant/metadata/fields/{field}/categories/{category}/suppress
     */
    public function unsuppressForCategory(int $field, int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Verify category belongs to tenant
        $categoryModel = Category::where('id', $category)
            ->whereHas('brand', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->id);
            })
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        // Unsuppress field for category
        $this->visibilityService->unsuppressForCategory($tenant, $field, $categoryModel);

        return response()->json([
            'success' => true,
            'message' => 'Field unsuppressed for category',
        ]);
    }

    /**
     * Get suppressed categories for a field.
     *
     * GET /api/tenant/metadata/fields/{field}/categories
     */
    public function getSuppressedCategories(int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view metadata visibility.');
        }

        // Get category-specific overrides (including is_primary)
        // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
        $brand = app('brand');
        $suppressedCategoryIds = $this->visibilityService->getSuppressedCategories($tenant, $field, $brand?->id);
        $categoryOverrides = $this->visibilityService->getCategoryOverrides($tenant, $field, $brand?->id);

        return response()->json([
            'suppressed_category_ids' => $suppressedCategoryIds,
            'category_overrides' => $categoryOverrides, // Keyed by category_id, includes is_primary
        ]);
    }

    /**
     * Copy metadata visibility settings from one category to another.
     *
     * POST /api/tenant/metadata/categories/{targetCategory}/copy-from/{sourceCategory}
     *
     * @deprecated SUNSET (2026): UI removed from Advanced Settings; no new callers. Safe to delete this
     * method, its web route (`tenant.metadata.category.copy-from`), and `copyCategoryVisibility` usage once
     * confirmed no external integrations rely on it.
     */
    public function copyCategoryFrom(int $targetCategory, int $sourceCategory): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $sourceModel = Category::where('id', $sourceCategory)
            ->where('tenant_id', $tenant->id)
            ->first();
        $targetModel = Category::where('id', $targetCategory)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $sourceModel || ! $targetModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        try {
            $count = $this->visibilityService->copyCategoryVisibility($tenant, $sourceModel, $targetModel);

            return response()->json([
                'success' => true,
                'message' => "Settings copied from {$sourceModel->name} to {$targetModel->name}.",
                'rows_copied' => $count,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Reset a category's metadata visibility to default (remove all category-level overrides).
     *
     * POST /api/tenant/metadata/categories/{category}/reset
     */
    public function resetCategory(int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $categoryModel = Category::where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        $count = $this->visibilityService->applySeededDefaultsForCategory($tenant, $categoryModel);

        return response()->json([
            'success' => true,
            'message' => 'Category reset to seeded default. Visibility now matches the configured defaults for this category type.',
            'rows_written' => $count,
        ]);
    }

    /**
     * Get target categories for "Apply to other brands" (same slug + asset_type in other brands).
     *
     * GET /api/tenant/metadata/categories/{category}/apply-to-other-brands
     *
     * @deprecated SUNSET (2026): UI removed from Advanced Settings alongside copy-from-category.
     */
    public function getApplyToOtherBrandsTargets(int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $categoryModel = Category::where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        try {
            $targets = $this->visibilityService->getApplyToOtherBrandsTargets($tenant, $categoryModel);

            return response()->json(['targets' => $targets]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Apply current category's metadata visibility settings to the same category type in other brands.
     *
     * POST /api/tenant/metadata/categories/{category}/apply-to-other-brands
     *
     * @deprecated SUNSET (2026): UI removed from Advanced Settings alongside copy-from-category.
     */
    public function applyToOtherBrands(int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $categoryModel = Category::where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        try {
            $results = $this->visibilityService->applyCategoryVisibilityToOtherBrands($tenant, $categoryModel);
            $count = count($results);

            return response()->json([
                'success' => true,
                'message' => $count > 0
                    ? "Settings applied to {$count} categor".($count === 1 ? 'y' : 'ies').' in other brands.'
                    : 'No other brands have a category of this type.',
                'results' => $results,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Phase 3a: List metadata visibility profiles for the tenant.
     *
     * GET /api/tenant/metadata/profiles?brand_id= (optional)
     */
    public function listProfiles(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $query = MetadataVisibilityProfile::where('tenant_id', $tenant->id)
            ->orderBy('name');

        if ($request->has('brand_id') && $request->brand_id !== null && $request->brand_id !== '') {
            $brandId = (int) $request->brand_id;
            $query->where(function ($q) use ($brandId) {
                $q->where('brand_id', $brandId)->orWhereNull('brand_id');
            });
        }

        $profiles = $query->get(['id', 'tenant_id', 'brand_id', 'name', 'category_slug', 'created_at'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'tenant_id' => $p->tenant_id,
                'brand_id' => $p->brand_id,
                'name' => $p->name,
                'category_slug' => $p->category_slug,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        return response()->json(['profiles' => $profiles]);
    }

    /**
     * Phase 3a: Get a single profile (including snapshot for preview).
     *
     * GET /api/tenant/metadata/profiles/{profile}
     */
    public function getProfile(int $profile): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $profileModel = MetadataVisibilityProfile::where('id', $profile)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $profileModel) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        return response()->json([
            'profile' => [
                'id' => $profileModel->id,
                'name' => $profileModel->name,
                'category_slug' => $profileModel->category_slug,
                'snapshot' => $profileModel->snapshot ?? [],
            ],
        ]);
    }

    /**
     * Phase 3a: Save current category visibility as a named profile.
     *
     * POST /api/tenant/metadata/profiles
     * Body: name (required), category_id (required), brand_id (optional, for scope)
     */
    public function storeProfile(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
        ]);

        $category = Category::where('id', $validated['category_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $category) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        try {
            $snapshot = $this->visibilityService->snapshotFromCategory($tenant, $category);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $profile = MetadataVisibilityProfile::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $validated['brand_id'] ?? null,
            'name' => $validated['name'],
            'category_slug' => $category->slug,
            'snapshot' => $snapshot,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile saved.',
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'category_slug' => $profile->category_slug,
            ],
        ]);
    }

    /**
     * Phase 3a: Apply a saved profile to a category.
     *
     * POST /api/tenant/metadata/profiles/{profile}/apply
     * Body: category_id (required)
     */
    public function applyProfile(Request $request, int $profile): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $profileModel = MetadataVisibilityProfile::where('id', $profile)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $profileModel) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
        ]);

        $category = Category::where('id', $validated['category_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $category) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        try {
            $count = $this->visibilityService->applySnapshotToCategory($tenant, $category, $profileModel->snapshot ?? []);

            return response()->json([
                'success' => true,
                'message' => "Profile \"{$profileModel->name}\" applied. {$count} visibility settings updated.",
                'rows_written' => $count,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
