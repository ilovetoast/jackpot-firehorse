<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Category;
use App\Models\User;
use App\Services\AiMetadataConfidenceService;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\BrandLibraryCategoryCountService;
use App\Services\FileTypeService;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterQualityService;
use App\Services\Lifecycle\LifecycleResolver;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\DerivativeFailureUserMessaging;
use App\Support\Preview3dDeliveryUrls;
use App\Support\ThumbnailModeDeliveryUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\BuildsBulkAssignCategoryOptions;
use Inertia\Inertia;
use Inertia\Response;

class DeliverableController extends Controller
{
    use BuildsBulkAssignCategoryOptions;

    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService,
        protected MetadataFilterService $metadataFilterService,
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected AiMetadataConfidenceService $confidenceService,
        protected LifecycleResolver $lifecycleResolver,
        protected AssetSearchService $assetSearchService,
        protected AssetSortService $assetSortService,
        protected BrandLibraryCategoryCountService $brandLibraryCategoryCountService,
        protected FileTypeService $fileTypeService
    ) {}

    /**
     * Display a listing of deliverables.
     * Returns JsonResponse for format=json (pickers/modals); otherwise Inertia Response.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $t0 = microtime(true);
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand) {
            $folderQuickFilterSettings = (array) config('categories.folder_quick_filters', []);
            $folderQuickFiltersEnabled = (bool) ($folderQuickFilterSettings['enabled'] ?? false);

            return Inertia::render('Deliverables/Index', [
                'categories' => [],
                'bulk_categories_by_asset_type' => [
                    AssetType::ASSET->value => [],
                    AssetType::DELIVERABLE->value => [],
                    AssetType::AI_GENERATED->value => [],
                ],
                'total_asset_count' => 0,
                'selected_category' => null,
                'assets' => [],
                'next_page_url' => null,
                'filtered_grid_total' => 0,
                'grid_folder_total' => 0,
                'sort' => AssetSortService::DEFAULT_SORT,
                'sort_direction' => AssetSortService::DEFAULT_DIRECTION,
                'compliance_filter' => '',
                'show_compliance_filter' => false,
                'q' => '',
                'folder_quick_filter_settings' => [
                    'enabled' => $folderQuickFiltersEnabled,
                    'desktop_only' => (bool) ($folderQuickFilterSettings['desktop_only'] ?? true),
                    'max_visible_per_folder' => (int) ($folderQuickFilterSettings['max_visible_per_folder'] ?? 3),
                    'can_manage_filters' => false,
                ],
            ]);
        }

        // Get only deliverable categories for the brand
        // IMPORTANT: Always get ALL categories (including hidden) to check for existence
        // We'll filter hidden categories later when building the response, but we need
        // to know if a category exists (even if hidden) to avoid adding templates
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::DELIVERABLE)
            ->ordered()
            ->with(['tenant', 'brand', 'accessRules']);

        // Don't filter hidden categories here - we need them to check template existence
        // Hidden categories will be filtered in the response building below
        $allCategoriesIncludingHidden = $query->get();

        // Filter out hidden categories for users without 'manage categories' permission
        // This is for the final response, but we keep allCategoriesIncludingHidden for template checking
        if (! $user || ! $user->can('manage categories')) {
            $categories = $allCategoriesIncludingHidden->filter(fn ($cat) => ! $cat->is_hidden)->values();
        } else {
            $categories = $allCategoriesIncludingHidden;
        }

        // Filter out private categories that the user doesn't have access to
        $categories = $categories->filter(function ($category) use ($user) {
            return $user ? Gate::forUser($user)->allows('view', $category) : false;
        });

        // Security: only assets in categories the user can view (locked/private folder visibility)
        $viewableCategoryIds = $categories->pluck('id')->filter()->values()->toArray();

        // Phase B2: Lifecycle (trash) normalization and permission (match AssetController)
        $lifecycleParam = $request->get('lifecycle');
        $normalizedLifecycle = $this->lifecycleResolver->normalizeState($lifecycleParam, $user, $tenant, $brand);
        $canViewTrash = $user && (
            in_array($user->getRoleForTenant($tenant), ['admin', 'owner'], true) ||
            ($brand && $user->hasPermissionForBrand($brand, 'assets.delete'))
        );
        if ($lifecycleParam === 'deleted' && $normalizedLifecycle !== 'deleted') {
            abort(403, 'You do not have permission to view trash.');
        }
        $isTrashView = $normalizedLifecycle === 'deleted';

        // Get deliverable system category templates
        $systemTemplates = $this->systemCategoryService->getTemplatesByAssetType(AssetType::DELIVERABLE)
            ->filter(fn ($template) => ! $template->is_hidden || ($user && $user->can('manage categories')));

        // Create merged list
        $allCategories = collect();

        // Add existing categories
        foreach ($categories as $category) {
            // Find matching system template to get sort_order
            $matchingTemplate = $systemTemplates->first(function ($template) use ($category) {
                return $category->slug === $template->slug &&
                       $category->asset_type->value === $template->asset_type->value;
            });

            // Get access rules for private categories
            $accessRules = [];
            if ($category->is_private && ! $category->is_system) {
                $accessRules = $category->accessRules()->get()->map(function ($rule) {
                    if ($rule->access_type === 'role') {
                        return ['type' => 'role', 'role' => $rule->role];
                    } elseif ($rule->access_type === 'user') {
                        return ['type' => 'user', 'user_id' => $rule->user_id];
                    }

                    return null;
                })->filter()->values()->toArray();
            }

            $allCategories->push([
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
                'asset_type' => $category->asset_type->value,
                'is_system' => $category->is_system,
                'is_private' => $category->is_private,
                'is_locked' => $category->is_locked,
                'is_hidden' => $category->is_hidden,
                'sort_order' => $category->sort_order ?? ($matchingTemplate ? $matchingTemplate->sort_order : 999), // Prefer category's sort_order (from Metadata reorder)
                'access_rules' => $accessRules,
                // Drawer Brand Intelligence / Revue: must match AssetController (sidebar category list)
                'ebi_enabled' => $category->isEbiEnabled(),
            ]);
        }

        // Add system templates that don't have matching brand categories
        // IMPORTANT: Check against allCategoriesIncludingHidden, not $categories
        // This ensures we don't add a template if the category exists but is hidden
        foreach ($systemTemplates as $template) {
            $exists = $allCategoriesIncludingHidden->contains(function ($category) use ($template) {
                return $category->slug === $template->slug;
            });

            if (! $exists) {
                $allCategories->push([
                    'id' => null, // Template, no ID
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon ?? 'folder',
                    'asset_type' => $template->asset_type->value,
                    'is_system' => true,
                    'is_private' => $template->is_private,
                    'is_locked' => true,
                    'is_hidden' => $template->is_hidden,
                    'sort_order' => $template->sort_order,
                ]);
            }
        }

        // Sort by sort_order first, then by name
        $allCategories = $allCategories->sortBy([
            ['sort_order', 'asc'],
            ['name', 'asc'],
        ])->values();

        // Per-category and total deliverable counts (match Assets page; apply same lifecycle so counts reflect visible list)
        $categoryIds = $allCategories->pluck('id')->filter()->toArray();
        $assetCounts = [];
        $totalDeliverableCount = 0;
        if (! empty($viewableCategoryIds)) {
            $countResult = $this->brandLibraryCategoryCountService->getCounts(
                $tenant,
                $brand,
                $user,
                $viewableCategoryIds,
                $categoryIds,
                $normalizedLifecycle,
                $isTrashView,
                AssetType::DELIVERABLE,
                false,
                true
            );
            $totalDeliverableCount = $countResult['total'];
            $assetCounts = $countResult['by_category'];
        }
        $allCategories = $allCategories->map(function ($category) use ($assetCounts) {
            $id = isset($category['id']) ? (int) $category['id'] : 0;
            $category['asset_count'] = $id > 0 ? (int) (($assetCounts[$id] ?? $assetCounts[(string) $id] ?? 0)) : 0;

            return $category;
        });

        // Phase 3 — Folder Quick Filters: mirror AssetController::index() so the
        // shared AssetSidebar + FolderQuickFilters work on /app/executions.
        $folderQuickFilterSettings = (array) config('categories.folder_quick_filters', []);
        $folderQuickFiltersEnabled = (bool) ($folderQuickFilterSettings['enabled'] ?? false);
        if ($folderQuickFiltersEnabled && $categories->isNotEmpty()) {
            /** @var FolderQuickFilterAssignmentService $quickFilterAssignment */
            $quickFilterAssignment = app(FolderQuickFilterAssignmentService::class);
            /** @var FolderQuickFilterQualityService $quickFilterQuality */
            $quickFilterQuality = app(FolderQuickFilterQualityService::class);
            $quickFilterRowsByCategoryId = $quickFilterAssignment->getQuickFiltersForFolders($categories);

            $allCategories = $allCategories->map(function ($category) use ($quickFilterRowsByCategoryId, $quickFilterQuality, $tenant) {
                $id = isset($category['id']) ? (int) $category['id'] : 0;
                $rows = $id > 0 ? ($quickFilterRowsByCategoryId[$id] ?? []) : [];

                $category['quick_filters'] = array_map(static function ($row) use ($quickFilterQuality, $tenant) {
                    $field = $row->metadataField;

                    $quality = $field
                        ? $quickFilterQuality->evaluate($field, $tenant)
                        : [
                            'estimated_distinct_value_count' => null,
                            'last_facet_usage_at' => null,
                            'facet_usage_count' => 0,
                            'is_high_cardinality' => false,
                            'is_low_quality_candidate' => false,
                            'alias_count' => 0,
                            'duplicate_candidate_count' => 0,
                            'warnings' => [],
                        ];

                    return [
                        'metadata_field_id' => (int) $row->metadata_field_id,
                        'field_key' => (string) ($field?->key ?? ''),
                        'label' => (string) ($field?->system_label ?? ($field?->key ?? '')),
                        'field_type' => (string) ($field?->type ?? ''),
                        'order' => $row->folder_quick_filter_order !== null
                            ? (int) $row->folder_quick_filter_order
                            : null,
                        'weight' => $row->folder_quick_filter_weight !== null
                            ? (int) $row->folder_quick_filter_weight
                            : null,
                        'source' => $row->folder_quick_filter_source,
                        'pinned' => (bool) $row->is_pinned_folder_quick_filter,
                        'quality' => [
                            'is_high_cardinality' => (bool) $quality['is_high_cardinality'],
                            'is_low_quality_candidate' => (bool) $quality['is_low_quality_candidate'],
                            'estimated_distinct_value_count' => $quality['estimated_distinct_value_count'],
                            'alias_count' => (int) ($quality['alias_count'] ?? 0),
                            'duplicate_candidate_count' => (int) ($quality['duplicate_candidate_count'] ?? 0),
                            'warnings' => $quality['warnings'],
                        ],
                    ];
                }, $rows);

                return $category;
            });
        } else {
            $allCategories = $allCategories->map(function ($category) {
                $category['quick_filters'] = [];

                return $category;
            });
        }

        // Phase B2: Trash count for sidebar (show "Trash" only when it has items or we're on trash view)
        $trashCount = 0;
        if ($canViewTrash) {
            if ($isTrashView) {
                $trashCount = $totalDeliverableCount;
            } elseif (! empty($viewableCategoryIds)) {
                $trashCount = Asset::query()
                    ->excludeBuilderStaged()
                    ->onlyTrashed()
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->where('type', AssetType::DELIVERABLE)
                    ->whereNotNull('metadata')
                    ->whereIn(DB::raw(Asset::categoryIdMetadataCastExpression()), array_map('intval', $viewableCategoryIds))
                    ->count();
            }
        }

        // Check if plan is not free (to show "All" button)
        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $showAllButton = $currentPlan !== 'free';

        // Phase L.5.1: Permission checks for lifecycle filters (match AssetController)
        // - asset.publish: Required for "Pending Approval" filter (approvers can see pending assets)
        // - metadata.bypass_approval: Required for "Unpublished" filter (full viewing privileges)
        // - asset.archive: Required for "Archived" filter (users who can archive assets)
        $canPublish = $user && $user->hasPermissionForTenant($tenant, 'asset.publish');
        $canBypassApproval = $user && $user->hasPermissionForTenant($tenant, 'metadata.bypass_approval');
        // Check tenant permission first, then brand permission (matches AssetPolicy pattern)
        $canArchive = $user && (
            $user->hasPermissionForTenant($tenant, 'asset.archive') ||
            ($brand && $user->hasPermissionForBrand($brand, 'asset.archive'))
        );

        // For backward compatibility and default visibility: users with asset.publish can see unpublished
        // But unpublished filter specifically requires metadata.bypass_approval (full viewing privileges)
        $canSeeUnpublished = $canPublish || $canBypassApproval;

        // Resolve category slug → ID for filtering (slug-based URLs: ?category=rarr)
        $categorySlug = $request->get('category');
        $category = null;
        $categoryId = null;

        if ($categorySlug) {
            $category = Category::where('slug', $categorySlug)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', AssetType::DELIVERABLE)
                ->active()
                ->first();

            if ($category) {
                $categoryId = $category->id;
            }
        }

        // Query deliverables - match Assets page behavior with lifecycle filters
        // Use qualified column names to avoid ambiguity when compliance join is added
        $assetsQuery = Asset::query()
            ->excludeBuilderStaged()
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.type', AssetType::DELIVERABLE)
            ->when($isTrashView, fn ($q) => $q->onlyTrashed(), fn ($q) => $q->whereNull('assets.deleted_at'));

        // Restrict to viewable categories only (search and grid must not bypass locked/private folders)
        if (empty($viewableCategoryIds)) {
            $assetsQuery->whereRaw('0 = 1');
        } else {
            $assetsQuery->whereNotNull('metadata')
                ->whereIn(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED)'), array_map('intval', $viewableCategoryIds));
        }

        // Filter by category if provided
        // Use same JSON extraction as count query to ensure count/grid parity (handles string vs int in metadata)
        if ($categoryId) {
            $assetsQuery->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) = ?',
                [(int) $categoryId]
            );
        }

        $timeoutGuard = app(\App\Services\ThumbnailTimeoutGuard::class);

        // Phase L.5.1: Apply lifecycle filtering via LifecycleResolver
        // This is the SINGLE SOURCE OF TRUTH for lifecycle logic
        // CRITICAL: Lifecycle resolver MUST run LAST to override any other filters
        // Used by both AssetController and DeliverableController for consistency
        $this->lifecycleResolver->apply(
            $assetsQuery,
            $normalizedLifecycle,
            $user,
            $tenant,
            $brand
        );

        // Total in current library scope (All vs one category + lifecycle), plus search q only — excludes metadata filters, compliance, uploader, etc.
        $gridFolderTotalQuery = (clone $assetsQuery);
        $folderSearchQ = $request->input('q') ?? $request->input('search');
        if (is_string($folderSearchQ) && trim($folderSearchQ) !== '') {
            $this->assetSearchService->applyScopedSearch($gridFolderTotalQuery, trim($folderSearchQ));
        }
        $gridFolderTotal = (int) (clone $gridFolderTotalQuery)->reorder()->count();

        // Phase M: Base query for "has values" check (tenant, brand, category, lifecycle only; filters/search applied below)
        $baseQueryForFilterVisibility = (clone $assetsQuery);

        // Compliance filter (Brand DNA alignment)
        $complianceFilter = $request->input('compliance_filter');
        if (in_array($complianceFilter, ['superb', 'strong', 'needs_review', 'failing', 'unscored'], true)) {
            if ($complianceFilter === 'superb') {
                $assetsQuery->withCompliance()->where('bis_scope.overall_score', '>=', 90);
            } elseif ($complianceFilter === 'strong') {
                $assetsQuery->strong();
            } elseif ($complianceFilter === 'needs_review') {
                $assetsQuery->needsReview();
            } elseif ($complianceFilter === 'failing') {
                $assetsQuery->failing();
            } elseif ($complianceFilter === 'unscored') {
                $assetsQuery->unscored();
            }
        }

        // Apply metadata filters from request (same as AssetController: JSON 'filters' or flat params; tags/collection preserved for load_more)
        $fileType = 'image';
        $schema = $categoryId && $category
            ? $this->metadataSchemaResolver->resolve($tenant->id, $brand->id, $categoryId, $fileType)
            : $this->metadataSchemaResolver->resolve($tenant->id, $brand->id, null, $fileType);
        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?? [];
        }
        if (empty($filters) || ! is_array($filters)) {
            $filterKeys = array_values(array_filter(array_column($schema['fields'] ?? [], 'key')));
            $specialFilterKeys = ['tags', 'collection'];
            $filterKeys = array_values(array_unique(array_merge($filterKeys, $specialFilterKeys)));
            $reserved = ['category', 'sort', 'sort_direction', 'lifecycle', 'uploaded_by', 'file_type', 'asset', 'edit_metadata', 'page', 'filters', 'q'];
            $filters = [];
            foreach ($filterKeys as $key) {
                if (in_array($key, $reserved, true)) {
                    continue;
                }
                $val = $request->input($key);
                if ($val !== null && $val !== '') {
                    $filters[$key] = ['operator' => 'equals', 'value' => $val];
                }
            }
        }
        if (! empty($filters) && is_array($filters)) {
            $this->metadataFilterService->applyFilters($assetsQuery, $filters, $schema);
        }

        // Scoped search: filename, title, tags, collection name only (before order/pagination)
        $searchQ = $request->input('q') ?? $request->input('search');
        if (is_string($searchQ) && trim($searchQ) !== '') {
            $this->assetSearchService->applyScopedSearch($assetsQuery, trim($searchQ));
            $this->assetSearchService->applyScopedSearch($baseQueryForFilterVisibility, trim($searchQ));
        }

        // Uploaded-by filter: distinct uploaders in current scope (before applying uploaded_by), for avatar dropdown
        $uploadedByUsersPayload = [];
        if (! $request->boolean('load_more')) {
            $uploaderIds = (clone $assetsQuery)
                ->reorder()
                ->select('assets.user_id')
                ->whereNotNull('assets.user_id')
                ->distinct()
                ->pluck('assets.user_id')
                ->filter()
                ->values();
            if ($uploaderIds->isNotEmpty()) {
                $uploadedByUsersPayload = User::whereIn('id', $uploaderIds)
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->orderBy('email')
                    ->get()
                    ->map(fn (User $u) => [
                        'id' => (int) $u->id,
                        'name' => $u->name,
                        'first_name' => $u->first_name,
                        'last_name' => $u->last_name,
                        'email' => $u->email,
                        'avatar_url' => $u->avatar_url,
                    ])
                    ->values()
                    ->all();
            }
            $selectedUploadedBy = filter_var($request->input('uploaded_by'), FILTER_VALIDATE_INT);
            if ($selectedUploadedBy && ! collect($uploadedByUsersPayload)->contains(fn (array $row) => (int) $row['id'] === $selectedUploadedBy)) {
                $extra = User::query()->find($selectedUploadedBy);
                if ($extra) {
                    $uploadedByUsersPayload[] = [
                        'id' => (int) $extra->id,
                        'name' => $extra->name,
                        'first_name' => $extra->first_name,
                        'last_name' => $extra->last_name,
                        'email' => $extra->email,
                        'avatar_url' => $extra->avatar_url,
                    ];
                    usort($uploadedByUsersPayload, function (array $a, array $b) {
                        $na = strtolower((string) (($a['name'] ?? '') !== '' ? $a['name'] : ($a['email'] ?? '')));
                        $nb = strtolower((string) (($b['name'] ?? '') !== '' ? $b['name'] : ($b['email'] ?? '')));

                        return $na <=> $nb;
                    });
                }
            }
        }

        $uploadedByRequest = $request->input('uploaded_by');
        if ($uploadedByRequest !== null && $uploadedByRequest !== '') {
            $uploadedById = filter_var($uploadedByRequest, FILTER_VALIDATE_INT);
            if ($uploadedById !== false && $uploadedById > 0) {
                $assetsQuery->where('assets.user_id', $uploadedById);
                $baseQueryForFilterVisibility->where('assets.user_id', $uploadedById);
            }
        }

        $gridFileType = $request->input('file_type');
        if (is_string($gridFileType) && trim($gridFileType) !== '' && strtolower(trim($gridFileType)) !== 'all') {
            $this->fileTypeService->applyGridFileTypeFilterToAssetQuery($assetsQuery, $gridFileType);
            $this->fileTypeService->applyGridFileTypeFilterToAssetQuery($baseQueryForFilterVisibility, $gridFileType);
        }

        // Phase L: Centralized sort (after search/filters, before pagination)
        $sort = $this->assetSortService->normalizeSort($request->input('sort'));
        $sortDirection = $this->assetSortService->normalizeSortDirection($request->input('sort_direction'));
        $this->assetSortService->applySort($assetsQuery, $sort, $sortDirection);

        // Hue cluster counts for filter badges (clone before paginate consumes the builder)
        // Clear ORDER BY: GROUP BY + aggregate incompatible with only_full_group_by when ORDER BY references non-grouped columns.
        $hueClusterCounts = [];
        $hueCountQuery = (clone $assetsQuery)
            ->select('assets.dominant_hue_group', \DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('assets.dominant_hue_group')
            ->where('assets.dominant_hue_group', '!=', '')
            ->groupBy('assets.dominant_hue_group')
            ->reorder();
        foreach ($hueCountQuery->get() as $row) {
            $hueClusterCounts[(string) $row->dominant_hue_group] = (int) $row->cnt;
        }

        // Paginate: server-driven pagination (36 per page); next_page_url built from request query so filters/category/sort preserved (match AssetController)
        $perPage = 36;
        $paginator = $assetsQuery->with(['latestBrandIntelligenceScore', 'brandReferenceAsset'])->paginate($perPage);
        $assetModels = $paginator->getCollection();
        $t1 = microtime(true);

        $nextPageUrl = null;
        if ($paginator->hasMorePages()) {
            $query = array_merge($request->query(), ['page' => $paginator->currentPage() + 1]);
            $nextPageUrl = $request->url().'?'.http_build_query($query);
        }

        // HARD TERMINAL STATE: Check for stuck assets and repair them
        // This prevents infinite processing states by automatically failing
        // assets that have been processing longer than the timeout threshold
        foreach ($assetModels as $asset) {
            if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::PROCESSING) {
                $timeoutGuard->checkAndRepair($asset);
                // Reload asset to get updated status if it was repaired
                $asset->refresh();
            }
        }

        // Enhanced logging for debugging missing assets
        if ($assetModels->count() === 0) {
            $totalDeliverables = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::DELIVERABLE)
                ->whereNull('deleted_at')
                ->count();

            $visibleDeliverables = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::DELIVERABLE)
                ->where('status', AssetStatus::VISIBLE)
                ->whereNull('deleted_at')
                ->count();

            $mostRecentDeliverable = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::DELIVERABLE)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();

            \Illuminate\Support\Facades\Log::info('[DELIVERABLE_QUERY_AUDIT] DeliverableController::index() query results (empty)', [
                'query_tenant_id' => $tenant->id,
                'query_brand_id' => $brand->id,
                'category_filter' => $categoryId ?? 'none',
                'total_deliverables' => $totalDeliverables,
                'visible_deliverables' => $visibleDeliverables,
                'most_recent_asset' => $mostRecentDeliverable ? [
                    'id' => $mostRecentDeliverable->id,
                    'status' => $mostRecentDeliverable->status?->value ?? 'null',
                    'type' => $mostRecentDeliverable->type?->value ?? 'null',
                    'thumbnail_status' => $mostRecentDeliverable->thumbnail_status?->value ?? 'null',
                    'category_id' => $mostRecentDeliverable->metadata['category_id'] ?? 'null',
                    'created_at' => $mostRecentDeliverable->created_at?->toIso8601String(),
                ] : 'none',
                'note' => 'No deliverables found - check status, type, brand_id, tenant_id, and category filter',
            ]);
        }

        // Asset health badge: worst incident severity per asset
        $assetIds = $assetModels->pluck('id')->all();
        $incidentSeverityByAsset = [];
        if (! empty($assetIds)) {
            $incidents = \App\Models\SystemIncident::whereNull('resolved_at')
                ->whereIn('source_type', ['asset', 'job'])
                ->whereIn('source_id', $assetIds)
                ->get(['source_id', 'severity']);
            $order = ['critical' => 1, 'error' => 2, 'warning' => 3];
            foreach ($incidents as $inc) {
                $aid = $inc->source_id;
                $sev = $inc->severity ?? 'warning';
                if (! isset($incidentSeverityByAsset[$aid]) || ($order[$sev] ?? 99) < ($order[$incidentSeverityByAsset[$aid]] ?? 99)) {
                    $incidentSeverityByAsset[$aid] = $sev;
                }
            }
        }

        $publishedByIds = $assetModels->pluck('published_by_id')->filter()->unique()->values()->all();
        $publishedByUsers = collect();
        if ($publishedByIds !== []) {
            $publishedByUsers = User::query()
                ->whereIn('id', $publishedByIds)
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->keyBy('id');
        }

        // Brand Intelligence debug thumbnails: batch-load reference assets (avoids N+1 Asset queries in hydrateBrandIntelligenceDebug).
        $biTopReferenceIds = collect();
        foreach ($assetModels as $deliverable) {
            $bisRow = $deliverable->latestBrandIntelligenceScore;
            if (! $bisRow) {
                continue;
            }
            $bj = $bisRow->breakdown_json ?? [];
            $debug = $bj['debug'] ?? null;
            if (! is_array($debug)) {
                continue;
            }
            $refs = $debug['top_references'] ?? null;
            if (! is_array($refs) || $refs === []) {
                continue;
            }
            foreach ($refs as $refRow) {
                if (! is_array($refRow)) {
                    continue;
                }
                $rid = $refRow['id'] ?? null;
                if (is_string($rid) && $rid !== '') {
                    $biTopReferenceIds->push($rid);
                }
            }
        }
        $biTopReferenceIds = $biTopReferenceIds->unique()->values()->all();
        $brandIntelligenceReferenceAssetsById = [];
        if ($biTopReferenceIds !== []) {
            $brandIntelligenceReferenceAssetsById = Asset::query()
                ->whereIn('id', $biTopReferenceIds)
                ->get()
                ->keyBy(fn (Asset $a) => (string) $a->id)
                ->all();
        }

        // Batch-load categories and uploaders for grid mapping (avoids N+1 Category/User queries per asset).
        $metaCategoryIds = $assetModels
            ->map(fn (Asset $asset) => $asset->metadata['category_id'] ?? null)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $categoriesById = collect();
        if ($metaCategoryIds !== []) {
            $categoriesById = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('id', $metaCategoryIds)
                ->get()
                ->keyBy('id');
        }

        $uploaderIds = $assetModels->pluck('user_id')->filter()->unique()->values()->all();
        $uploadedByUsersMap = collect();
        if ($uploaderIds !== []) {
            $uploadedByUsersMap = User::query()
                ->whereIn('id', $uploaderIds)
                ->get()
                ->keyBy('id');
        }

        // STARRED CANONICAL: Same as AssetController — assets.metadata.starred (boolean) only.
        $mappedAssets = $assetModels
            ->map(function ($asset) use ($incidentSeverityByAsset, $publishedByUsers, $categoriesById, $uploadedByUsersMap, $brandIntelligenceReferenceAssetsById) {
                // Derive file extension from original_filename, with mime_type fallback
                $fileExtension = null;
                if ($asset->original_filename && $asset->original_filename !== 'unknown') {
                    $ext = pathinfo($asset->original_filename, PATHINFO_EXTENSION);
                    // Normalize extension (lowercase, remove leading dot if any)
                    if ($ext && ! empty(trim($ext))) {
                        $fileExtension = strtolower(trim($ext, '.'));
                    }
                }

                // Fallback to deriving from mime_type if extension not found or filename is "unknown"
                if (empty($fileExtension) && $asset->mime_type) {
                    $mimeToExt = [
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'image/svg+xml' => 'svg',
                        'image/tiff' => 'tif',
                        'image/tif' => 'tif',
                        'image/bmp' => 'bmp',
                        'application/pdf' => 'pdf',
                        'application/zip' => 'zip',
                        'application/x-zip-compressed' => 'zip',
                        'video/mpeg' => 'mpg',
                        'video/mp4' => 'mp4',
                        'video/quicktime' => 'mov',
                        'video/x-msvideo' => 'avi',
                        'image/vnd.adobe.photoshop' => 'psd',
                        'image/x-canon-cr2' => 'cr2',
                        'application/vnd.adobe.illustrator' => 'ai',
                        'application/x-adobe-indesign' => 'indd',
                        'application/x-indesign' => 'indd',
                        'application/vnd.adobe.indesign-idml' => 'idml',
                    ];
                    $mimeTypeLower = strtolower(trim($asset->mime_type));
                    $fileExtension = $mimeToExt[$mimeTypeLower] ?? null;

                    // If not in map, try extracting from mime type subtype (e.g., "image/jpeg" -> "jpeg")
                    if (empty($fileExtension) && strpos($mimeTypeLower, '/') !== false) {
                        $mimeParts = explode('/', $mimeTypeLower);
                        $subtype = $mimeParts[1] ?? null;
                        if ($subtype) {
                            // Remove "+xml" suffix if present (e.g., "svg+xml" -> "svg")
                            $subtype = str_replace('+xml', '', $subtype);
                            $subtype = str_replace('+zip', '', $subtype);
                            // Normalize common subtypes
                            if ($subtype === 'jpeg') {
                                $subtype = 'jpg';
                            } elseif ($subtype === 'tiff') {
                                $subtype = 'tif';
                            }
                            $fileExtension = $subtype;
                        }
                    }
                }

                // Derive title from asset.title or original_filename without extension
                $title = $asset->title;
                // If title is empty, null, or "Unknown", derive from filename
                if (empty($title) || $title === 'Unknown' || $title === 'Untitled Asset') {
                    if ($asset->original_filename) {
                        $pathInfo = pathinfo($asset->original_filename);
                        $title = $pathInfo['filename'] ?? $asset->original_filename;
                    } else {
                        $title = null; // Use null instead of "Unknown" or empty string
                    }
                }
                // Ensure title is not empty string (convert to null)
                if ($title === '') {
                    $title = null;
                }

                // Category from preloaded map (metadata.category_id)
                $category = null;
                if ($asset->metadata && isset($asset->metadata['category_id'])) {
                    $cid = (int) $asset->metadata['category_id'];
                    $category = $categoriesById->get($cid);
                }

                // Uploader from preloaded map
                $uploadedBy = null;
                if ($asset->user_id) {
                    $user = $uploadedByUsersMap->get($asset->user_id);
                    if ($user) {
                        $uploadedBy = [
                            'id' => $user->id,
                            'name' => trim($user->name) ?: null, // Convert empty string to null for proper fallback
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'avatar_url' => $user->avatar_url,
                        ];
                    }
                }

                // Step 6: Thumbnail URLs — CDN-only (no app proxy)
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                    ? $asset->thumbnail_status->value
                    : ($asset->thumbnail_status ?? 'pending');

                $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;
                $finalThumbnailUrl = null;
                $thumbnailVersion = null;

                if ($thumbnailStatus === 'completed') {
                    $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
                    $thumbnailPath = $asset->thumbnailPathForStyle($thumbnailStyle);
                    if ($thumbnailPath) {
                        $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                        $variant = $thumbnailStyle === 'medium' ? AssetVariant::THUMB_MEDIUM : AssetVariant::THUMB_SMALL;
                        $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
                        // Do NOT append ?v= to presigned URLs — invalidates S3 signature
                        if ($thumbnailVersion && $finalThumbnailUrl && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                            $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Deliverable marked as completed but thumbnail path missing', [
                            'asset_id' => $asset->id,
                            'thumbnail_status' => $thumbnailStatus,
                            'metadata_thumbnails' => isset($metadata['thumbnails']) ? array_keys($metadata['thumbnails'] ?? []) : 'not set',
                        ]);
                    }
                }

                $publisher = $asset->published_by_id ? $publishedByUsers->get($asset->published_by_id) : null;

                return [
                    'id' => $asset->id,
                    'title' => $title,
                    'original_filename' => $asset->original_filename,
                    'mime_type' => $asset->mime_type,
                    'file_extension' => $fileExtension,
                    'status' => $asset->status instanceof \App\Enums\AssetStatus ? $asset->status->value : (string) $asset->status, // AssetStatus enum value
                    'size_bytes' => $asset->size_bytes,
                    'created_at' => $asset->created_at?->toIso8601String(),
                    'metadata' => DerivativeFailureUserMessaging::workspaceMetadata($asset->metadata), // Full metadata object (internal pipeline diagnostics stripped for workspace UI)
                    'starred' => $this->assetIsStarred($metadata['starred'] ?? null), // boolean; source: assets.metadata.starred only
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'ebi_enabled' => $category->isEbiEnabled(),
                    ] : null,
                    'user_id' => $asset->user_id, // For delete-own permission check
                    'uploaded_by' => $uploadedBy, // User who uploaded the asset
                    // Phase L.4: Lifecycle fields (read-only display)
                    'published_at' => $asset->published_at?->toIso8601String(),
                    'is_published' => $asset->published_at !== null, // Canonical boolean for publication state
                    'published_by' => $asset->published_by_id ? [
                        'id' => $publisher?->id,
                        'name' => $publisher ? (trim(($publisher->first_name ?? '').' '.($publisher->last_name ?? '')) ?: null) : null,
                        'email' => $publisher?->email,
                    ] : null,
                    'archived_at' => $asset->archived_at?->toIso8601String(),
                    // Thumbnail URLs - distinct paths prevent cache confusion
                    'preview_thumbnail_url' => $previewThumbnailUrl, // Preview thumbnail (available even when pending/processing)
                    'final_thumbnail_url' => $finalThumbnailUrl, // Only set if file exists and is valid
                    'thumbnail_url_large' => $this->thumbnailUrlLarge($asset), // Large style for drawer/zoom
                    'thumbnail_version' => $thumbnailVersion, // Version timestamp for cache busting
                    // Legacy thumbnail_url for backward compatibility (points to final if available, otherwise null)
                    'thumbnail_url' => $finalThumbnailUrl ?? null,
                    'thumbnail_status' => $thumbnailStatus, // Thumbnail generation status (pending, processing, completed, failed, skipped)
                    'thumbnail_error' => DerivativeFailureUserMessaging::workspaceThumbnailError($asset->thumbnail_error), // Error message if thumbnail generation failed or skipped
                    'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null, // Skip reason for skipped assets
                    'preview_url' => null, // Reserved for future full-size preview endpoint
                    'url' => null, // Reserved for future download endpoint
                    // Phase V-1: Same hover preview MP4 + poster as main Assets grid (GenerateVideoPreviewJob pipeline)
                    'video_preview_url' => $this->videoPreviewUrl($asset),
                    'video_poster_url' => $this->videoPosterUrl($asset),
                    // Phase 3: Audio playback + waveform — keys parallel to video_preview_url for consistency.
                    'audio_playback_url' => $this->audioPlaybackUrl($asset),
                    'audio_waveform_url' => $this->audioWaveformUrl($asset),
                ] + Preview3dDeliveryUrls::forAuthenticatedAsset($asset) + [
                    'analysis_status' => $asset->analysis_status ?? 'uploading',
                    'health_status' => $asset->computeHealthStatus($incidentSeverityByAsset[$asset->id] ?? null),
                    'brand_intelligence' => $asset->brandIntelligencePayloadForFrontend($brandIntelligenceReferenceAssetsById),
                    'reference_promotion' => $asset->brandReferenceAsset?->toFrontendArray(),
                    // Grid Original / Enhanced / Presentation thumbnails (same as AssetController::index)
                    'thumbnail_mode_urls' => ThumbnailModeDeliveryUrls::map($asset),
                    'thumbnail_modes_meta' => ThumbnailModeDeliveryUrls::modesMetaForApi($asset),
                    'thumbnail_modes_status' => $metadata['thumbnail_modes_status'] ?? null,
                ];
                if ($finalThumbnailUrl && str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                    Log::info('ASSET API RESPONSE URL', [
                        'asset_id' => $asset->id,
                        'thumbnail_url' => $finalThumbnailUrl,
                        'thumbnail_url_large' => $this->thumbnailUrlLarge($asset),
                    ]);
                }
            })
            ->values()
            ->all();

        $t2 = microtime(true);

        $isLoadMore = $request->boolean('load_more');
        if (! $isLoadMore) {
            Log::info('[DELIVERABLE_GRID_TIMING] DeliverableController::index', [
                'total_ms' => round((microtime(true) - $t0) * 1000),
                'after_query_ms' => round(($t1 - $t0) * 1000),
                'after_transform_ms' => round(($t2 - $t1) * 1000),
                'assets_count' => count($mappedAssets),
                's3_presign_count' => 0,
                'note' => 'Deliverables include video_preview_url when job completed (same as Assets)',
            ]);
        }

        // Keep collection for availableValues block
        $assets = collect($mappedAssets);

        // format=json: return plain JSON for pickers/modals (e.g. Brand DNA visual reference selector)
        if ($request->get('format') === 'json') {
            return response()->json([
                'assets' => $mappedAssets,
                'categories' => $allCategories->values()->all(),
                'categories_by_type' => ['all' => $allCategories->values()->all()],
                'next_page_url' => $nextPageUrl,
            ]);
        }

        // Phase L.5.1: Enable filters in "All Categories" view (reuse $schema resolved above for applyFilters)
        $filterableSchema = $this->metadataFilterService->getFilterableFields($schema, $category, $tenant);

        // Phase M: Hide filters with zero values in scoped dataset, except always-visible library keys
        if (! empty($filterableSchema)) {
            $keysWithValues = $this->metadataFilterService->getFieldKeysWithValuesInScope($baseQueryForFilterVisibility, $filterableSchema);
            $filterableSchema = $this->metadataFilterService->restrictFilterableSchemaToKeysWithValuesInScope($filterableSchema, $keysWithValues);
        }

        // available_values is required by Phase H filter visibility rules
        // Do not remove without updating Phase H contract
        // Compute distinct metadata values for the current asset grid result set
        $availableValues = [];
        $hueGroupToDisplayHex = []; // cluster key -> display_hex from HueClusterService

        // When page > 1, use first page's asset IDs for filter options so dropdowns stay consistent
        $assetIdsForAvailableValues = $assetModels->pluck('id')->toArray();
        if (! empty($filterableSchema) && count($assetIdsForAvailableValues) > 0) {
            // Get asset IDs from the current grid result set (or first page when loading page > 1)
            $assetIds = $assetIdsForAvailableValues;

            // Build map of filterable field keys for quick lookup
            // Note: filterableSchema from getFilterableFields() already contains only filterable fields,
            // so we don't need to check is_filterable - all fields in the array are filterable
            $filterableFieldKeys = [];
            foreach ($filterableSchema as $field) {
                $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
                if ($fieldKey) {
                    $filterableFieldKeys[$fieldKey] = true;
                }
            }

            if (! empty($filterableFieldKeys)) {
                // Source 1: Query asset_metadata table (Phase G.4 structure)
                // This is the authoritative source for approved metadata values
                // CRITICAL: Automatic/system fields (population_mode = 'automatic') do NOT require approval
                // They should be included in available_values regardless of approved_at

                // Get automatic field IDs (fields with population_mode = 'automatic')
                $automaticFieldIds = \DB::table('metadata_fields')
                    ->where('population_mode', 'automatic')
                    ->pluck('id')
                    ->toArray();

                // Build query: Include automatic fields regardless of approved_at, require approved_at for others
                $assetMetadataValues = \DB::table('asset_metadata')
                    ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
                    ->whereIn('asset_metadata.asset_id', $assetIds)
                    ->whereIn('metadata_fields.key', array_keys($filterableFieldKeys))
                    ->whereNotNull('asset_metadata.value_json')
                    ->where(function ($query) use ($automaticFieldIds) {
                        // Automatic fields: include if value exists (no approval required)
                        if (! empty($automaticFieldIds)) {
                            $query->whereIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                                ->orWhere(function ($q) use ($automaticFieldIds) {
                                    // Non-automatic fields require approval
                                    $q->whereNotIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                                        ->whereNotNull('asset_metadata.approved_at');
                                });
                        } else {
                            // No automatic fields, require approval for all
                            $query->whereNotNull('asset_metadata.approved_at');
                        }
                    })
                    ->select('metadata_fields.key', 'metadata_fields.population_mode', 'asset_metadata.value_json', 'asset_metadata.confidence')
                    ->distinct()
                    ->get();

                // Group values by field key (with confidence filtering for AI metadata)
                foreach ($assetMetadataValues as $row) {
                    $fieldKey = $row->key;
                    $confidence = $row->confidence !== null ? (float) $row->confidence : null;
                    $populationMode = $row->population_mode ?? 'manual';

                    // CRITICAL: Confidence suppression applies ONLY to AI fields
                    // Automatic/system fields are never suppressed (they are authoritative)
                    $isAiField = $populationMode === 'ai';
                    if ($isAiField && $this->confidenceService->shouldSuppress($fieldKey, $confidence)) {
                        continue; // Skip this value - treat as if it doesn't exist
                    }

                    $value = json_decode($row->value_json, true);

                    // Skip null values
                    if ($value !== null) {
                        if (! isset($availableValues[$fieldKey])) {
                            $availableValues[$fieldKey] = [];
                        }

                        // Handle arrays (multiselect fields) and scalar values
                        if (is_array($value)) {
                            foreach ($value as $item) {
                                if ($item !== null && ! in_array($item, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $item;
                                }
                            }
                        } else {
                            if (! in_array($value, $availableValues[$fieldKey], true)) {
                                $availableValues[$fieldKey][] = $value;
                            }
                        }
                    }
                }

                // Source 2a: dominant_hue_group from assets.dominant_hue_group column
                if (isset($filterableFieldKeys['dominant_hue_group'])) {
                    $hueGroupValues = \DB::table('assets')
                        ->whereIn('id', $assetIds)
                        ->whereNotNull('dominant_hue_group')
                        ->where('dominant_hue_group', '!=', '')
                        ->distinct()
                        ->pluck('dominant_hue_group')
                        ->all();
                    if (! empty($hueGroupValues)) {
                        $availableValues['dominant_hue_group'] = array_values(array_unique(array_merge(
                            $availableValues['dominant_hue_group'] ?? [],
                            $hueGroupValues
                        )));
                    }
                }

                // Source 2: Query metadata JSON column (legacy/fallback)
                // Extract values from metadata->fields structure for assets not in asset_metadata
                $assetsWithMetadata = $assets->filter(function ($asset) {
                    return ! empty($asset->metadata) && isset($asset->metadata['fields']);
                });

                foreach ($assetsWithMetadata as $asset) {
                    $fields = $asset->metadata['fields'] ?? [];
                    foreach ($fields as $fieldKey => $value) {
                        // Only include if field is filterable
                        if (isset($filterableFieldKeys[$fieldKey]) && $value !== null) {
                            // Initialize array if field doesn't exist yet
                            if (! isset($availableValues[$fieldKey])) {
                                $availableValues[$fieldKey] = [];
                            }

                            // Handle arrays (multiselect fields) and scalar values
                            // Deduplicate values (values from asset_metadata are authoritative)
                            if (is_array($value)) {
                                foreach ($value as $item) {
                                    if ($item !== null && ! in_array($item, $availableValues[$fieldKey], true)) {
                                        $availableValues[$fieldKey][] = $item;
                                    }
                                }
                            } else {
                                if (! in_array($value, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $value;
                                }
                                // dominant_hue_group: swatch from HueClusterService (handled below)
                            }
                        }
                    }
                }

                // C9.2 / Phase C: Source 3 - Collection filter values from asset_collections pivot (not asset_metadata)
                // Primary filter for collection requires available_values; harvest from pivot for current asset set.
                if (isset($filterableFieldKeys['collection'])) {
                    $collectionIds = \DB::table('asset_collections')
                        ->whereIn('asset_id', $assetIds)
                        ->distinct()
                        ->pluck('collection_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all();
                    if (! empty($collectionIds)) {
                        $availableValues['collection'] = array_values(array_unique(array_merge(
                            $availableValues['collection'] ?? [],
                            $collectionIds
                        )));
                    }
                }

                // Tags filter values from asset_tags table (tags are stored in asset_tags, not asset_metadata)
                // Primary filter for tags requires available_values; harvest from asset_tags for current asset set.
                if (isset($filterableFieldKeys['tags'])) {
                    $tagValues = \DB::table('asset_tags')
                        ->whereIn('asset_id', $assetIds)
                        ->distinct()
                        ->pluck('tag')
                        ->filter()
                        ->values()
                        ->all();
                    if (! empty($tagValues)) {
                        $availableValues['tags'] = array_values(array_unique(array_merge(
                            $availableValues['tags'] ?? [],
                            $tagValues
                        )));
                        sort($availableValues['tags']);
                    }
                }

                // Seed available_values for primary rating/select fields so primary filter shows when no asset has a value yet.
                foreach ($filterableSchema as $field) {
                    $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
                    $isPrimary = ($field['is_primary'] ?? false) === true;
                    if (! $fieldKey || ! $isPrimary || ! isset($filterableFieldKeys[$fieldKey])) {
                        continue;
                    }
                    $optionValues = [];
                    $options = $field['options'] ?? [];
                    if (! empty($options)) {
                        foreach ($options as $opt) {
                            $v = is_array($opt) ? ($opt['value'] ?? $opt['id'] ?? null) : $opt;
                            if ($v !== null && $v !== '') {
                                $optionValues[] = $v;
                            }
                        }
                    }
                    // Rating type (e.g. quality_rating) has no options in schema; seed 1–5 so primary filter shows
                    if (empty($optionValues) && ($field['type'] ?? '') === 'rating') {
                        $optionValues = [1, 2, 3, 4, 5];
                    }
                    if (! empty($optionValues)) {
                        $availableValues[$fieldKey] = array_values(array_unique(array_merge(
                            $availableValues[$fieldKey] ?? [],
                            $optionValues
                        )));
                        sort($availableValues[$fieldKey]);
                    }
                }

                // Dominant hue: options must include every hue present in the full filtered scope (hueClusterCounts),
                // not only on the first page.
                if (isset($filterableFieldKeys['dominant_hue_group']) && ! empty($hueClusterCounts)) {
                    $availableValues['dominant_hue_group'] = array_values(array_unique(array_merge(
                        $availableValues['dominant_hue_group'] ?? [],
                        array_keys($hueClusterCounts)
                    )));
                }

                // Remove empty arrays (filters with no values should not appear)
                $availableValues = array_filter($availableValues, function ($values) {
                    return ! empty($values);
                });

                // Sort values for consistent output
                foreach ($availableValues as $fieldKey => $values) {
                    sort($availableValues[$fieldKey]);
                }
            }
        }

        // Attach color swatch data to dominant_hue_group; attach collection options (id => name) for primary filter dropdown
        $hueClusterService = app(\App\Services\Color\HueClusterService::class);
        foreach ($filterableSchema as &$field) {
            $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
            if ($fieldKey === 'dominant_hue_group') {
                $hueValues = $availableValues['dominant_hue_group'] ?? [];
                $field['options'] = array_values(array_map(function ($clusterKey) use ($hueClusterService, $hueClusterCounts) {
                    $meta = $hueClusterService->getClusterMeta((string) $clusterKey);
                    $label = $meta['label'] ?? (string) $clusterKey;
                    $threshold = $meta['threshold_deltaE'] ?? 18;
                    $count = $hueClusterCounts[(string) $clusterKey] ?? 0;

                    return [
                        'value' => (string) $clusterKey,
                        'label' => $label,
                        'swatch' => $meta['display_hex'] ?? '#999999',
                        'row_group' => $meta['row_group'] ?? 4,
                        'tooltip' => $label."\nTypical ΔE threshold: ".$threshold,
                        'count' => $count,
                    ];
                }, $hueValues));
            }
            // C9.2: Attach collection options (id => name) so primary filter is a dropdown with labels (Phase C checklist #6)
            if ($fieldKey === 'collection') {
                $collectionIds = $availableValues['collection'] ?? [];
                $collections = $collectionIds
                    ? \App\Models\Collection::whereIn('id', $collectionIds)->pluck('name', 'id')->all()
                    : [];
                $field['options'] = array_values(array_map(fn ($id) => [
                    'value' => (string) $id,
                    'label' => $collections[$id] ?? (string) $id,
                    'display_label' => $collections[$id] ?? (string) $id,
                ], $collectionIds));
            }
            // Rating type (e.g. quality_rating): schema has no options; attach 1–5 so primary filter dropdown has labels
            if (($field['type'] ?? '') === 'rating') {
                $ratingValues = $availableValues[$fieldKey] ?? [1, 2, 3, 4, 5];
                $field['options'] = array_values(array_map(fn ($v) => [
                    'value' => (string) $v,
                    'label' => (string) $v,
                    'display_label' => (string) $v,
                ], $ratingValues));
            }
        }
        unset($field);

        // Load-more: return JSON only so the client can append without Inertia replacing the list
        if ($request->boolean('load_more')) {
            return response()->json([
                'data' => $mappedAssets,
                'next_page_url' => $nextPageUrl,
            ]);
        }

        // Brand DNA: show compliance filter only when enabled with active version
        $brandModel = $brand->brandModel;
        $showComplianceFilter = $brandModel && $brandModel->is_enabled && $brandModel->active_version_id !== null;

        $t3 = microtime(true);
        if (! $isLoadMore) {
            Log::info('[DELIVERABLE_GRID_TIMING] DeliverableController::index before Inertia', [
                'total_ms' => round(($t3 - $t0) * 1000),
                'before_return_ms' => round(($t3 - $t2) * 1000),
            ]);
        }

        $bulkCategoriesByAssetType = $this->buildBulkAssignCategoryOptionsByAssetType($tenant, $brand, $user);

        return Inertia::render('Deliverables/Index', [
            'categories' => $allCategories,
            'folder_quick_filter_settings' => [
                'enabled' => $folderQuickFiltersEnabled,
                'desktop_only' => (bool) ($folderQuickFilterSettings['desktop_only'] ?? true),
                'max_visible_per_folder' => (int) ($folderQuickFilterSettings['max_visible_per_folder'] ?? 3),
                'can_manage_filters' => (bool) ($user
                    && $tenant
                    && $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')),
            ],
            'bulk_categories_by_asset_type' => $bulkCategoriesByAssetType,
            'total_asset_count' => $totalDeliverableCount, // Total count for "All" and sidebar parity with Assets
            'selected_category' => $categoryId ? (int) $categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'assets' => $mappedAssets,
            'next_page_url' => $nextPageUrl,
            'filtered_grid_total' => (int) $paginator->total(),
            'grid_folder_total' => $gridFolderTotal,
            'filterable_schema' => $filterableSchema, // Phase 2 – Step 8: Filterable metadata fields
            'available_values' => $availableValues, // available_values is required by Phase H filter visibility rules
            'sort' => $sort,
            'sort_direction' => $sortDirection,
            'compliance_filter' => $request->input('compliance_filter', ''),
            'show_compliance_filter' => $showComplianceFilter,
            'q' => $request->input('q', ''),
            'lifecycle' => $lifecycleParam, // Phase B2: e.g. 'deleted' for trash view
            'can_view_trash' => $canViewTrash,
            'trash_count' => $trashCount,
            'uploaded_by_users' => $uploadedByUsersPayload,
        ]);
    }

    /**
     * Get large thumbnail URL with fallback chain (large -> medium -> small).
     */
    private function thumbnailUrlLarge(Asset $asset): ?string
    {
        $url = $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::AUTHENTICATED);
        if ($url !== '') {
            return $url;
        }
        $url = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);
        if ($url !== '') {
            return $url;
        }
        $url = $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Short hover MP4 via AssetDeliveryService (empty until GenerateVideoPreviewJob writes video_preview_url).
     */
    private function videoPreviewUrl(Asset $asset): ?string
    {
        $url = $asset->deliveryUrl(AssetVariant::VIDEO_PREVIEW, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    private function videoPosterUrl(Asset $asset): ?string
    {
        $url = $asset->deliveryUrl(AssetVariant::VIDEO_POSTER, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Phase 3: Signed playback URL for an audio asset (the original file
     * itself). Mirrors {@see AssetController::audioPlaybackUrl()}.
     */
    private function audioPlaybackUrl(Asset $asset): ?string
    {
        $mime = (string) ($asset->mime_type ?? '');
        if (! str_starts_with($mime, 'audio/')) {
            $ext = strtolower((string) pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
            if (! in_array($ext, ['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac', 'weba'], true)) {
                return null;
            }
        }
        $url = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Phase 3: Signed URL for the FFmpeg-rendered waveform PNG strip.
     * Mirrors {@see AssetController::audioWaveformUrl()}.
     */
    private function audioWaveformUrl(Asset $asset): ?string
    {
        if (empty($asset->metadata['audio']['waveform_path'] ?? null)) {
            return null;
        }
        $url = $asset->deliveryUrl(AssetVariant::AUDIO_WAVEFORM, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }
}
