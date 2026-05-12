<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\AiMetadataSuggestionJob;
use App\Jobs\AiTagAutoApplyJob;
use App\Jobs\RunAudioAiAnalysisJob;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\AiMetadataConfidenceService;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\Audio\AudioAiAnalysisService;
use App\Services\AssetArchiveService;
use App\Services\AssetDeletionService;
use App\Services\AssetPublicationService;
use App\Services\Assets\AssetProcessingGuardService;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\Assets\StagedFiledAssetAiService;
use App\Services\BrandDNA\GoogleFontLibraryEntriesService;
use App\Services\BrandLibraryCategoryCountService;
use App\Services\FeatureGate;
use App\Services\FileTypeService;
use App\Services\Lifecycle\LifecycleResolver;
use App\Services\Metadata\MetadataValueNormalizer;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\Prostaff\GetProstaffDamFilterOptions;
use App\Services\SystemCategoryService;
use App\Services\UploadInitiationService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\DerivativeFailureUserMessaging;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\Metadata\CategoryTypeResolver;
use App\Support\ThumbnailMetadata;
use App\Support\ThumbnailModeDeliveryUrls;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Http\Controllers\Concerns\BuildsBulkAssignCategoryOptions;
use App\Http\Support\AssetSessionWorkspace;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    use BuildsBulkAssignCategoryOptions;

    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService,
        protected AssetDeletionService $deletionService,
        protected AssetPublicationService $publicationService,
        protected AssetArchiveService $archiveService,
        protected MetadataFilterService $metadataFilterService,
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected AiMetadataConfidenceService $confidenceService,
        protected LifecycleResolver $lifecycleResolver,
        protected AssetSearchService $assetSearchService,
        protected AssetSortService $assetSortService,
        protected UploadInitiationService $uploadInitiationService,
        protected BrandLibraryCategoryCountService $brandLibraryCategoryCountService,
        protected FileTypeService $fileTypeService
    ) {}

    /**
     * File kind for {@see MetadataSchemaResolver::resolve} (`image` | `video` | `document`).
     * This is not the same as the category's organizational `asset_type` (e.g. asset vs deliverable).
     * The Video asset folder must use `video` so fields such as `video_type` load into schema and filters.
     */
    protected function resolveMetadataFileTypeForCategory(?Category $category): string
    {
        return CategoryTypeResolver::metadataSchemaAssetTypeForSlug(
            $category ? (string) $category->slug : ''
        );
    }

    /**
     * GET /app/assets/staged
     * Staged asset intake: assets without category, shown until classified.
     * Reuses the asset grid with source=staged; no lifecycle filters.
     */
    public function staged(Request $request): Response|JsonResponse
    {
        $request->merge(['source' => 'staged']);

        return $this->index($request);
    }

    /**
     * Display a listing of assets.
     * Returns JsonResponse for load_more (page 2+) so the client can append without Inertia; otherwise Inertia Response.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $t0 = microtime(true);
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        // Eager load tenants once so policy checks (Asset, Category) avoid N+1 tenant_user queries
        $user?->loadMissing('tenants');

        if ($user && $tenant && $brand) {
            $sourceEarly = $request->get('source');
            if (($sourceEarly === 'staged' || $sourceEarly === 'reference_materials')
                && ! $this->userCanViewAssetSystemFolders($user, $tenant, $brand)) {
                if ($request->boolean('load_more') || $request->get('format') === 'json') {
                    abort(403, 'You do not have permission to view system folders.');
                }

                return redirect()->route('assets.index');
            }
        }

        if (! $tenant || ! $brand) {
            // Handle case where tenant or brand is not resolved (e.g., no active tenant/brand)
            if ($request->get('format') === 'json') {
                return response()->json(['assets' => [], 'categories' => [], 'categories_by_type' => ['all' => []]]);
            }

            return Inertia::render('Assets/Index', [
                'categories' => [],
                'bulk_categories_by_asset_type' => [
                    'asset' => [],
                    'deliverable' => [],
                    'ai_generated' => [],
                ],
                'categories_by_type' => ['all' => []],
                'selected_category' => null,
                'assets' => [],
                'filtered_grid_total' => 0,
                'grid_folder_total' => 0,
                'sort' => AssetSortService::DEFAULT_SORT,
                'sort_direction' => AssetSortService::DEFAULT_DIRECTION,
                'q' => '',
                'pending_publication_review_count' => 0,
                'prostaff_filter_options' => [],
                'dam_prostaff_filter_config' => GetProstaffDamFilterOptions::damProstaffFilterConfig(),
            ]);
        }

        // Get only BASIC categories for the brand
        // Use the active() scope to filter out soft-deleted, templates, and deleted system categories
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->active()
            ->ordered()
            ->with(['tenant', 'brand', 'accessRules']);

        $includeHiddenFontsNav = $this->shouldIncludeHiddenFontsCategory($tenant, $brand, $user, $request->get('category'));

        // If user does not have 'manage categories' permission, filter out hidden categories
        // (except Fonts when it should appear: filter, manage, or font assets present).
        if (! $user || ! $user->can('manage categories')) {
            if ($includeHiddenFontsNav) {
                $query->where(function ($q) {
                    $q->where('is_hidden', false)
                        ->orWhere(function ($q2) {
                            $q2->where('slug', 'fonts')->where('is_hidden', true);
                        });
                });
            } else {
                $query->visible();
            }
        }

        $categories = $query->get();

        // Filter out private categories that the user doesn't have access to
        // Use CategoryPolicy to check access for each category
        $categories = $categories->filter(function ($category) use ($user) {
            // Use the policy to check if user can view this category
            return $user ? Gate::forUser($user)->allows('view', $category) : false;
        });

        $templateExistsByCategoryId = Category::templateExistsLookupForCategories($categories);

        // Get only BASIC system category templates
        $systemTemplates = $this->systemCategoryService->getTemplatesByAssetType(AssetType::ASSET)
            ->filter(function ($template) use ($user, $includeHiddenFontsNav) {
                if (! $template->is_hidden) {
                    return true;
                }
                if ($user && $user->can('manage categories')) {
                    return true;
                }

                return $template->slug === 'fonts' && $includeHiddenFontsNav;
            });

        // Create merged list of categories
        $allCategories = collect();

        // Add existing categories
        // Note: Categories are already filtered by active() scope, but we still need to check
        // template_exists for system categories to set the flag correctly
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

            // Check if system category template still exists (for deleted system categories)
            $templateExists = $category->is_system
                ? ($templateExistsByCategoryId[$category->id] ?? false)
                : true;
            $deletionAvailable = $category->is_system ? ! $templateExists : false;

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
                'template_exists' => $templateExists, // Flag to indicate if system template still exists
                'deletion_available' => $deletionAvailable, // Flag to indicate if category can be deleted (template deleted)
            ]);
        }

        // Add system templates that don't have matching brand categories
        foreach ($systemTemplates as $template) {
            $exists = $categories->contains(function ($category) use ($template) {
                return $category->slug === $template->slug &&
                       $category->asset_type->value === $template->asset_type->value;
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

        // Category and total counts: use same lifecycle as grid (published by default; unpublished/pending when filter active)
        $viewableCategoryIds = $categories->pluck('id')->filter()->values()->toArray();
        $categoryIds = $allCategories->pluck('id')->filter()->toArray();
        $assetCounts = [];
        $totalAssetCount = 0;
        $lifecycleParam = $request->get('lifecycle');
        $normalizedLifecycle = $this->lifecycleResolver->normalizeState($lifecycleParam, $user, $tenant, $brand);
        if ($lifecycleParam === 'deleted' && $normalizedLifecycle !== 'deleted') {
            abort(403, 'You do not have permission to view trash.');
        }
        $isTrashView = $normalizedLifecycle === 'deleted';
        $sourceParam = $request->get('source');
        $isStagedView = $sourceParam === 'staged';
        // Per-category counts always reflect the library (published lifecycle, etc.), even on the staged
        // queue view — so the sidebar stays accurate. total_asset_count for the response is still
        // overridden to stagedCount when source=staged (see Inertia props below).
        if (! empty($viewableCategoryIds)) {
            $countResult = $this->brandLibraryCategoryCountService->getCounts(
                $tenant,
                $brand,
                $user,
                $viewableCategoryIds,
                $categoryIds,
                $normalizedLifecycle,
                $isTrashView,
                [AssetType::ASSET, AssetType::AI_GENERATED],
                true,
                false
            );
            $totalAssetCount = $countResult['total'];
            $assetCounts = $countResult['by_category'];
        }
        // Add counts to categories (integer keys — MySQL/pluck often returns string category_id keys)
        $allCategories = $allCategories->map(function ($category) use ($assetCounts) {
            $id = isset($category['id']) ? (int) $category['id'] : 0;
            $category['asset_count'] = $id > 0 ? (int) (($assetCounts[$id] ?? $assetCounts[(string) $id] ?? 0)) : 0;

            return $category;
        });

        // Phase B2: Trash count for sidebar (show "Trash" only when it has items or we're on trash view)
        $trashCount = 0;
        $canViewTrash = $user && (
            in_array($user->getRoleForTenant($tenant), ['admin', 'owner'], true) ||
            ($brand && $user->hasPermissionForBrand($brand, 'assets.delete'))
        );
        if ($canViewTrash) {
            if ($isTrashView) {
                $trashCount = $totalAssetCount;
            } elseif (! empty($viewableCategoryIds)) {
                $trashCount = Asset::query()
                    ->excludeBuilderStaged()
                    ->onlyTrashed()
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->forAssetLibraryTypes()
                    ->whereNotNull('metadata')
                    ->whereIn(DB::raw(Asset::categoryIdMetadataCastExpression()), array_map('intval', $viewableCategoryIds))
                    ->count();
            }
        }

        // Check if plan is not free (to show "All" button)
        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $showAllButton = $currentPlan !== 'free';

        // Resolve category slug → ID for filtering (slug-based URLs: ?category=rarr)
        $categorySlug = $request->get('category');
        $category = null;
        $categoryId = null;

        if ($categorySlug) {
            // Slug is unique per (tenant, brand, asset_type). The library sidebar only lists
            // ASSET-type folders; without this scope, first() can return the deliverable row with
            // the same slug, so the grid filters the wrong category_id and shows empty while the
            // sidebar count still reflects the library folder.
            $category = Category::where('slug', $categorySlug)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('asset_type', AssetType::ASSET)
                ->active()
                ->first();

            if ($category) {
                $categoryId = $category->id;
            }
        }

        // Security: only assets in categories the user can view (private/locked folder visibility)
        $viewableCategoryIds = $categories->pluck('id')->filter()->values()->toArray();

        $isReferenceMaterialsView = $sourceParam === 'reference_materials';

        // Query assets for this brand and asset type
        // Phase B2: lifecycle=deleted shows trash (onlyTrashed); otherwise exclude deleted (default scope).
        // AssetStatus and published_at filtering is handled by LifecycleResolver (single source of truth).
        // Intake: main grid excludes staged (intake_state=normal); staged view shows intake_state=staged only.
        // Type: standard uploads (ASSET) + AI outputs (e.g. studio MP4) until filed; non-reference library rows use forAssetLibraryTypes.
        $assetsQuery = Asset::query()
            ->when($isStagedView, fn ($q) => $q->stagedOnly(), fn ($q) => $q->when(! $isReferenceMaterialsView, fn ($q2) => $q2->normalIntakeOnly()))
            ->when($isReferenceMaterialsView, fn ($q) => $q->referenceMaterialsOnly(), fn ($q) => $q->when(! $isStagedView, fn ($q2) => $q2->excludeBuilderStaged()))
            ->when($isTrashView, fn ($q) => $q->onlyTrashed(), fn ($q) => $q)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->when($isReferenceMaterialsView, fn ($q) => $q, fn ($q) => $q->forAssetLibraryTypes());

        // Staged view: no category filter (staged assets have no category)
        // Reference materials: no category filter (builder-staged assets have no category)
        // Main grid: restrict to viewable categories only
        if ($isStagedView || $isReferenceMaterialsView) {
            // No category filter; show all staged/reference assets
        } elseif (empty($viewableCategoryIds)) {
            $assetsQuery->whereRaw('0 = 1');
        } else {
            $assetsQuery->whereNotNull('metadata')
                ->whereIn(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED)'), array_map('intval', $viewableCategoryIds));
        }

        // Filter by category if provided — skip for staged and reference materials
        if (! $isStagedView && ! $isReferenceMaterialsView && $categoryId) {
            $assetsQuery->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) = ?',
                [(int) $categoryId]
            );
        }

        // Content type: all | image (non-video) | video — additive query narrowing
        $contentType = $request->input('content_type');
        if (is_string($contentType) && $contentType !== '' && $contentType !== 'all') {
            if ($contentType === 'video') {
                $assetsQuery->where('mime_type', 'like', 'video/%');
            } elseif ($contentType === 'image') {
                $assetsQuery->where(function ($q) {
                    $q->whereNull('mime_type')
                        ->orWhere('mime_type', 'not like', 'video/%');
                });
            }
        }

        // Reference materials count for sidebar — no lifecycle filter (reference materials are unpublished working assets)
        $referenceMaterialsCount = 0;
        if (! $isTrashView) {
            $referenceMaterialsCount = Asset::query()
                ->referenceMaterialsOnly()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->count();
        }

        // Staged count for sidebar (intake_state=staged, not in trash)
        $stagedCount = 0;
        if (! $isTrashView) {
            $stagedCount = Asset::query()
                ->stagedOnly()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->forAssetLibraryTypes()
                ->count();
        }

        // Approver banner (Assets index): tenant admin/owner or brand_manager only — same audience as UI callout
        $tenantRoleLower = strtolower((string) ($user?->getRoleForTenant($tenant) ?? ''));
        $isTenantAdminOrOwner = in_array($tenantRoleLower, ['admin', 'owner'], true);
        $brandMembership = ($user && $brand) ? $user->activeBrandMembership($brand) : null;
        $isBrandManager = $brandMembership && (($brandMembership['role'] ?? null) === 'brand_manager');
        $canSeePendingReviewBanner = $user && ($isTenantAdminOrOwner || $isBrandManager);

        // Approver banner (Assets index): count assets in scope of lifecycle=pending_publication (brand-wide, viewable categories)
        $pendingPublicationReviewCount = 0;
        if (
            $canSeePendingReviewBanner
            && ! $isTrashView
            && ! $isStagedView
            && ! $isReferenceMaterialsView
            && $user->hasPermissionForTenant($tenant, 'asset.publish')
            && $viewableCategoryIds !== []
        ) {
            $pendingPubQuery = Asset::query()
                ->normalIntakeOnly()
                ->excludeBuilderStaged()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->forAssetLibraryTypes()
                ->whereNotNull('metadata')
                ->whereIn(DB::raw(Asset::categoryIdMetadataCastExpression()), array_map('intval', $viewableCategoryIds));

            $this->lifecycleResolver->apply($pendingPubQuery, 'pending_publication', $user, $tenant, $brand);
            // Banner = items still awaiting an approver decision (exclude rejected — creator’s turn).
            $pendingPubQuery->where('approval_status', ApprovalStatus::PENDING);
            $pendingPublicationReviewCount = (int) $pendingPubQuery->count();
        }

        $rejectedMyUploadsCount = 0;
        if ($user && $user->hasPermissionForBrand($brand, 'asset.view')) {
            $rejectedMyUploadsCount = (int) Asset::query()
                ->rejectedPublicationForUploader($user, $tenant, $brand)
                ->count();
        }

        // Phase L.5.1: Apply lifecycle filtering via LifecycleResolver
        // Skip for staged and reference materials views — these are working materials, not published library assets
        // Phase B2: Resolver validates viewTrash for lifecycle=deleted and applies exclusions
        if (! $isStagedView && ! $isReferenceMaterialsView) {
            $this->lifecycleResolver->apply(
                $assetsQuery,
                $normalizedLifecycle,
                $user,
                $tenant,
                $brand
            );
        }

        // Phase M: Base query for "has values" check (tenant, brand, category, lifecycle only; search applied below)
        // Must NOT include request metadata filters so empty filters are hidden by value presence, not by current selection
        $baseQueryForFilterVisibility = (clone $assetsQuery);

        // Special filter: missing_metadata=1 — assets with no approved metadata (from "What Needs Attention" signal)
        if ($request->boolean('missing_metadata')) {
            $assetsQuery->whereNotExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('asset_metadata')
                    ->whereColumn('asset_metadata.asset_id', 'assets.id')
                    ->whereNotNull('asset_metadata.approved_at');
            });
            $baseQueryForFilterVisibility->whereNotExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('asset_metadata')
                    ->whereColumn('asset_metadata.asset_id', 'assets.id')
                    ->whereNotNull('asset_metadata.approved_at');
            });
        }

        // Special filter: missing_tags=1 — library assets with no rows in asset_tags (Manage → Tags deep link)
        if ($request->boolean('missing_tags')) {
            $assetsQuery->whereNotExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('asset_tags')
                    ->whereColumn('asset_tags.asset_id', 'assets.id');
            });
            $baseQueryForFilterVisibility->whereNotExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('asset_tags')
                    ->whereColumn('asset_tags.asset_id', 'assets.id');
            });
        }

        // Special filter: pending_suggestions=1 — assets with pending AI metadata or tag suggestions
        if ($request->boolean('pending_suggestions')) {
            $assetsQuery->where(function ($query) {
                $query->whereExists(function ($q) {
                    $q->select(\DB::raw(1))
                        ->from('asset_metadata_candidates')
                        ->whereColumn('asset_metadata_candidates.asset_id', 'assets.id')
                        ->whereNull('asset_metadata_candidates.resolved_at')
                        ->whereNull('asset_metadata_candidates.dismissed_at')
                        ->where('asset_metadata_candidates.producer', 'ai');
                })->orWhereExists(function ($q) {
                    $q->select(\DB::raw(1))
                        ->from('asset_tag_candidates')
                        ->whereColumn('asset_tag_candidates.asset_id', 'assets.id')
                        ->whereNull('asset_tag_candidates.resolved_at')
                        ->whereNull('asset_tag_candidates.dismissed_at')
                        ->where('asset_tag_candidates.producer', 'ai');
                });
            });
            $baseQueryForFilterVisibility->where(function ($query) {
                $query->whereExists(function ($q) {
                    $q->select(\DB::raw(1))
                        ->from('asset_metadata_candidates')
                        ->whereColumn('asset_metadata_candidates.asset_id', 'assets.id')
                        ->whereNull('asset_metadata_candidates.resolved_at')
                        ->whereNull('asset_metadata_candidates.dismissed_at')
                        ->where('asset_metadata_candidates.producer', 'ai');
                })->orWhereExists(function ($q) {
                    $q->select(\DB::raw(1))
                        ->from('asset_tag_candidates')
                        ->whereColumn('asset_tag_candidates.asset_id', 'assets.id')
                        ->whereNull('asset_tag_candidates.resolved_at')
                        ->whereNull('asset_tag_candidates.dismissed_at')
                        ->where('asset_tag_candidates.producer', 'ai');
                });
            });
        }

        // Apply metadata filters from request (JSON 'filters' or readable flat params)
        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?? [];
        }
        $fileType = $this->resolveMetadataFileTypeForCategory($categoryId ? $category : null);
        $schema = $categoryId && $category
            ? $this->metadataSchemaResolver->resolve($tenant->id, $brand->id, $categoryId, $fileType)
            : $this->metadataSchemaResolver->resolve($tenant->id, $brand->id, null, $fileType);
        // If no filters JSON, build from flat query params (e.g. photo_type=action&scene_classification=product)
        // Schema returns 'fields' (not field_map); derive filter keys from field keys.
        // Include special keys (tags, collection) so load_more and any GET with flat params apply all filters.
        if (empty($filters) || ! is_array($filters)) {
            $filterKeys = array_values(array_filter(array_column($schema['fields'] ?? [], 'key')));
            $specialFilterKeys = ['tags', 'collection']; // Applied from asset_tags/asset_collections; may be missing from schema
            $filterKeys = array_values(array_unique(array_merge($filterKeys, $specialFilterKeys)));
            $reserved = ['category', 'sort', 'sort_direction', 'lifecycle', 'uploaded_by', 'submitted_by_prostaff', 'prostaff_user_id', 'file_type', 'asset', 'edit_metadata', 'page', 'filters', 'q', 'content_type'];
            $filters = [];
            $multiValueKeys = ['tags', 'collection', 'dominant_hue_group'];
            foreach ($filterKeys as $key) {
                if (in_array($key, $reserved, true)) {
                    continue;
                }
                $val = $request->input($key);
                if ($val !== null && $val !== '') {
                    if (in_array($key, $multiValueKeys, true) && is_array($val)) {
                        $val = array_values(array_unique(array_map('strval', array_filter($val))));
                    }
                    $filters[$key] = ['operator' => 'equals', 'value' => $val];
                }
            }
        }
        $gridFolderTotalQuery = (clone $assetsQuery);
        if (! empty($filters) && is_array($filters)) {
            $this->metadataFilterService->applyFilters(
                $assetsQuery,
                $filters,
                $schema
            );
        }

        // Scoped search: filename, title, tags, collection name only (before order/pagination)
        $searchQ = $request->input('q') ?? $request->input('search');
        if (is_string($searchQ) && trim($searchQ) !== '') {
            $this->assetSearchService->applyScopedSearch($assetsQuery, trim($searchQ));
            $this->assetSearchService->applyScopedSearch($baseQueryForFilterVisibility, trim($searchQ));
            $this->assetSearchService->applyScopedSearch($gridFolderTotalQuery, trim($searchQ));
        }

        // Library scope total for "filtered of folder" UI: category + lifecycle + view modes above, search q, excluding metadata filters / uploader / special URL filters not on $gridFolderTotalQuery
        $gridFolderTotal = (int) (clone $gridFolderTotalQuery)->reorder()->count();

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

        if ($tenant && app(FeatureGate::class)->creatorModuleEnabled($tenant)) {
            if ($request->boolean('submitted_by_prostaff')) {
                $assetsQuery->where('assets.submitted_by_prostaff', true);
                $baseQueryForFilterVisibility->where('assets.submitted_by_prostaff', true);
            }

            $prostaffUserId = filter_var($request->input('prostaff_user_id'), FILTER_VALIDATE_INT);
            if ($prostaffUserId !== false && $prostaffUserId > 0) {
                $assetsQuery->where('assets.prostaff_user_id', $prostaffUserId);
                $baseQueryForFilterVisibility->where('assets.prostaff_user_id', $prostaffUserId);
            }
        }

        // Phase L: Centralized sort (after search/filters, before pagination)
        $sort = $this->assetSortService->normalizeSort($request->input('sort'));
        $sortDirection = $this->assetSortService->normalizeSortDirection($request->input('sort_direction'));
        $this->assetSortService->applySort($assetsQuery, $sort, $sortDirection);

        // Hue cluster counts for filter badges (clone before paginate consumes the builder)
        // Clear ORDER BY from cloned query: GROUP BY + aggregate is incompatible with only_full_group_by
        // when ORDER BY references non-grouped columns (e.g. metadata, quality_rating).
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

        // Paginate: server-driven pagination (36 per page); infinite scroll loads next via next_page_url
        // Eager load relations used in the map to avoid N+1 and lazy-load errors on load_more (page 2+)
        $perPage = 36;
        $paginator = $assetsQuery->with([
            'user',
            'prostaffUser' => static function ($query): void {
                $query->select(['id', 'first_name', 'last_name', 'email']);
            },
            'publishedBy',
            'archivedBy',
            'currentVersion',
            'tenant',
            'latestBrandIntelligenceScore',
            'brandReferenceAsset',
        ])->paginate($perPage);
        $assetModels = $paginator->getCollection();
        $t1 = microtime(true);

        // Build next_page_url from current request query so category, sort, filters, q, etc. are always preserved
        $nextPageUrl = null;
        if ($paginator->hasMorePages()) {
            $query = array_merge($request->query(), ['page' => $paginator->currentPage() + 1]);
            $nextPageUrl = $request->url().'?'.http_build_query($query);
        }

        $isLoadMore = $request->boolean('load_more');

        if (! $isLoadMore) {
            // HARD TERMINAL STATE: Check for stuck assets and repair them (first page only)
            $timeoutGuard = app(\App\Services\ThumbnailTimeoutGuard::class);
            foreach ($assetModels as $asset) {
                if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::PROCESSING) {
                    $timeoutGuard->checkAndRepair($asset);
                    $asset->refresh();
                }
            }
            // AUDIT: Log query results (first page only)
            if ($assetModels->count() > 0) {
                $sampleAssetBrandIds = $assetModels->take(5)->pluck('brand_id')->unique()->values()->toArray();
                Log::info('[ASSET_QUERY_AUDIT] AssetController::index() query results', [
                    'query_tenant_id' => $tenant->id,
                    'query_brand_id' => $brand->id,
                    'assets_count' => $assetModels->count(),
                    'sample_asset_brand_ids' => $sampleAssetBrandIds,
                    'brand_id_mismatch_count' => $assetModels->filter(fn ($a) => $a->brand_id != $brand->id)->count(),
                    'note' => 'If brand_id_mismatch_count > 0, query brand_id does not match stored asset brand_id',
                ]);
            } else {
                Log::info('[ASSET_QUERY_AUDIT] AssetController::index() query results (empty)', [
                    'query_tenant_id' => $tenant->id,
                    'query_brand_id' => $brand->id,
                    'assets_count' => 0,
                    'note' => 'No assets found - cannot compare brand_ids',
                ]);
            }
        }

        // STARRED CANONICAL: Prefer assets.metadata.starred; fallback to asset_metadata so grid star icon
        // shows even when sync was skipped (e.g. approval required) or backfill not run.
        $assetIds = $assetModels->pluck('id')->all();
        $starredFromTable = [];
        if (! empty($assetIds)) {
            $starredFieldId = DB::table('metadata_fields')
                ->where('key', 'starred')
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
                ->orderByRaw('tenant_id IS NOT NULL DESC')
                ->value('id');
            if ($starredFieldId) {
                $rows = DB::table('asset_metadata')
                    ->whereIn('asset_id', $assetIds)
                    ->where('metadata_field_id', $starredFieldId)
                    ->whereNotNull('value_json')
                    ->orderByRaw('approved_at IS NULL DESC')
                    ->orderByDesc('id')
                    ->get(['asset_id', 'value_json']);
                foreach ($rows as $row) {
                    if (! array_key_exists($row->asset_id, $starredFromTable)) {
                        $decoded = json_decode($row->value_json, true);
                        $starredFromTable[$row->asset_id] = $decoded;
                    }
                }
            }
        }

        // Asset health badge: worst incident severity per asset (source_type asset or job, source_id = asset_id)
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

        $assetCategoryIds = $assetModels
            ->map(fn ($asset) => isset($asset->metadata['category_id']) ? (int) $asset->metadata['category_id'] : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categoriesByIdForGrid = collect();
        if ($assetCategoryIds !== []) {
            $categoriesByIdForGrid = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('id', $assetCategoryIds)
                ->get(['id', 'name', 'slug', 'settings'])
                ->keyBy('id');
        }

        $searchQueryTrimmed = is_string($searchQ) ? trim($searchQ) : '';

        try {
            $mappedAssets = $assetModels->map(function ($asset) use ($starredFromTable, $incidentSeverityByAsset, $categoriesByIdForGrid, $searchQueryTrimmed) {
                try {
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

                    // Get category name if category_id exists in metadata (preloaded per page to avoid N+1)
                    $categoryName = null;
                    $categoryId = null;
                    $categorySlug = null;
                    if ($asset->metadata && isset($asset->metadata['category_id'])) {
                        $categoryId = (int) $asset->metadata['category_id'];
                        $categoryRow = $categoriesByIdForGrid->get($categoryId);
                        if ($categoryRow) {
                            $categoryName = $categoryRow->name;
                            $categorySlug = $categoryRow->slug ?? null;
                        }
                    }

                    // Get user who uploaded the asset (eager-loaded via ->with(['user', ...]) to avoid N+1 / 500 on load_more)
                    $uploadedBy = null;
                    if ($asset->user) {
                        $uploader = $asset->user;
                        $uploadedBy = [
                            'id' => $uploader->id,
                            'name' => $uploader->name,
                            'first_name' => $uploader->first_name,
                            'last_name' => $uploader->last_name,
                            'email' => $uploader->email,
                            'avatar_url' => $uploader->avatar_url,
                        ];
                    }

                    // Step 6: Thumbnail URLs — CDN-only (no app proxy)
                    // preview_thumbnail_url: LQIP during processing
                    // final_thumbnail_url: permanent CDN URL when thumbnails exist
                    $metadata = $asset->metadata ?? [];
                    $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                        ? $asset->thumbnail_status->value
                        : ($asset->thumbnail_status ?? 'pending');

                    $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;
                    if (config('app.debug') && str_starts_with((string) ($asset->mime_type ?? ''), 'image/')
                        && in_array($thumbnailStatus, ['processing', 'pending'], true) && ! $previewThumbnailUrl) {
                        Log::debug('[AssetGrid] No LQIP URL while thumbnail pending/processing', [
                            'asset_id' => $asset->id,
                            'thumbnail_status' => $thumbnailStatus,
                            'has_preview_metadata' => ! empty($metadata['preview_thumbnails']['preview']['path'] ?? null),
                        ]);
                    }
                    $finalThumbnailUrl = null;
                    $thumbnailVersion = null;
                    $isPdf = strtolower((string) ($asset->mime_type ?? '')) === 'application/pdf'
                        || strtolower((string) ($fileExtension ?? '')) === 'pdf';
                    $fileTypeService = app(\App\Services\FileTypeService::class);
                    $usesPdfPagePreview = $isPdf || (
                        $fileTypeService->isOfficeDocument(strtolower((string) ($asset->mime_type ?? '')), strtolower((string) ($fileExtension ?? '')))
                        && ! empty($metadata['office']['preview_pdf_path'] ?? null)
                    );
                    $pdfPageCount = $asset->pdf_page_count ?? ($metadata['pdf_page_count'] ?? null);
                    $pdfPageApiEndpoint = $usesPdfPagePreview
                        ? route('assets.pdf-page.show', ['asset' => $asset->id, 'page' => '__PAGE__'])
                        : null;
                    $firstPageUrl = null;

                    $thumbnailsExistInMetadata = ThumbnailMetadata::hasThumb($metadata);
                    if ($thumbnailStatus === 'completed' || $thumbnailsExistInMetadata) {
                        $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                        $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
                        $pathExists = $asset->thumbnailPathForStyle($thumbnailStyle) ?? $asset->thumbnailPathForStyle('thumb');
                        if ($pathExists) {
                            $variant = $thumbnailStyle === 'medium' ? AssetVariant::THUMB_MEDIUM : AssetVariant::THUMB_SMALL;
                            $finalThumbnailUrl = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
                            // Do NOT append ?v= to presigned URLs — invalidates S3 signature
                            if ($thumbnailVersion && $finalThumbnailUrl && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                                $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
                            }
                            if ($thumbnailStatus !== 'completed' && $thumbnailsExistInMetadata) {
                                Log::info('[AssetController] Providing final_thumbnail_url despite status mismatch', [
                                    'asset_id' => $asset->id,
                                    'thumbnail_status' => $thumbnailStatus,
                                ]);
                            }
                        }
                    }
                    if ($usesPdfPagePreview) {
                        $firstPageUrl = $finalThumbnailUrl ?? $previewThumbnailUrl;
                    }

                    return [
                        'id' => $asset->id,
                        'title' => $title,
                        'original_filename' => $asset->original_filename,
                        'mime_type' => $asset->mime_type,
                        'file_extension' => $fileExtension,
                        'status' => $asset->status instanceof \App\Enums\AssetStatus ? $asset->status->value : (string) $asset->status, // AssetStatus enum value
                        'size_bytes' => $asset->size_bytes,
                        'video_duration' => $asset->video_duration !== null ? (int) $asset->video_duration : null,
                        'created_at' => $asset->created_at?->toIso8601String(),
                        'metadata' => DerivativeFailureUserMessaging::workspaceMetadata($asset->metadata), // Internal pipeline diagnostics stripped for workspace UI
                        'starred' => $this->assetIsStarred($metadata['starred'] ?? $starredFromTable[$asset->id] ?? null), // prefer assets.metadata; fallback asset_metadata for display
                        'category' => $categoryName ? [
                            'id' => $categoryId,
                            'name' => $categoryName,
                            'slug' => $categorySlug,
                            'ebi_enabled' => $categoryRow?->isEbiEnabled() ?? false,
                        ] : null,
                        'user_id' => $asset->user_id, // For delete-own permission check
                        'uploaded_by' => $uploadedBy, // User who uploaded the asset
                        'submitted_by_prostaff' => (bool) $asset->submitted_by_prostaff,
                        'prostaff_user_id' => $asset->prostaff_user_id !== null ? (int) $asset->prostaff_user_id : null,
                        'prostaff_user_name' => $asset->prostaffUser
                            ? (trim((string) $asset->prostaffUser->name) !== '' ? trim((string) $asset->prostaffUser->name) : (string) ($asset->prostaffUser->email ?? ''))
                            : null,
                        'is_prostaff_asset' => (bool) $asset->submitted_by_prostaff,
                        // Thumbnail URLs — all via AssetDeliveryService (AUTHENTICATED context)
                        'thumbnail_small' => $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED) ?: null,
                        'thumbnail_medium' => $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED) ?: null,
                        'thumbnail_large' => $this->thumbnailUrlLarge($asset),
                        'thumbnail_preview' => $previewThumbnailUrl, // LQIP during processing
                        'original' => $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null,
                        'thumbnail_version' => $thumbnailVersion, // Version timestamp for cache busting
                        // Legacy aliases for frontend migration (remove when frontend updated)
                        'thumbnail_url' => $finalThumbnailUrl ?? $previewThumbnailUrl ?? null,
                        'preview_thumbnail_url' => $previewThumbnailUrl,
                        'final_thumbnail_url' => $finalThumbnailUrl,
                        'thumbnail_url_large' => $this->thumbnailUrlLarge($asset),
                        'thumbnail_status' => $thumbnailStatus, // Thumbnail generation status (pending, processing, completed, failed, skipped)
                        'thumbnail_error' => DerivativeFailureUserMessaging::workspaceThumbnailError($asset->thumbnail_error), // Error message if thumbnail generation failed or skipped
                        'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null, // Skip reason for skipped assets
                        'preview_unavailable_user_message' => $metadata['preview_unavailable_user_message'] ?? null,
                        'pdf_page_count' => $asset->pdf_page_count,
                        'pdf_pages_rendered' => (bool) ($asset->pdf_pages_rendered ?? false),
                        'uses_pdf_page_preview' => $usesPdfPagePreview,
                        'first_page_url' => $firstPageUrl,
                        'pdf_page_api_endpoint' => $pdfPageApiEndpoint,
                        // Phase L.4: Lifecycle fields (Actions dropdown: Publish/Unpublish/Archive/Restore)
                        'published_at' => $asset->published_at?->toIso8601String(),
                        'is_published' => $asset->published_at !== null,
                        'published_by' => $asset->published_by_id ? [
                            'id' => $asset->publishedBy?->id ?? null,
                            'name' => $asset->publishedBy?->name ?? null,
                            'first_name' => $asset->publishedBy?->first_name ?? null,
                            'last_name' => $asset->publishedBy?->last_name ?? null,
                            'email' => $asset->publishedBy?->email ?? null,
                        ] : null,
                        'archived_at' => $asset->archived_at?->toIso8601String(),
                        'archived_by' => $asset->archived_by_id ? [
                            'id' => $asset->archivedBy?->id ?? null,
                            'name' => $asset->archivedBy?->name ?? null,
                            'first_name' => $asset->archivedBy?->first_name ?? null,
                            'last_name' => $asset->archivedBy?->last_name ?? null,
                            'email' => $asset->archivedBy?->email ?? null,
                        ] : null,
                        // Phase B2: Trash view and drawer "Deleted X days ago"
                        'deleted_at' => $asset->deleted_at?->toIso8601String(),
                        // Bulk actions contextual filtering: approval state (approved/pending/rejected; not_required excluded)
                        'approval_status' => $asset->approval_status instanceof \App\Enums\ApprovalStatus ? $asset->approval_status->value : ($asset->approval_status ?? null),
                        'preview_url' => null, // Reserved for future full-size preview endpoint
                        'url' => null, // Reserved for future download endpoint
                        // Phase V-1: Video quick preview on hover (grid and drawer)
                        'video_preview_url' => $this->videoPreviewUrl($asset),
                        'video_poster_url' => $this->videoPosterUrl($asset),
                        // Phase 3: Audio asset signed playback + waveform PNG. Both null until the
                        // upload pipeline completes (frontend falls back to synthetic waveform).
                        'audio_playback_url' => $this->audioPlaybackUrl($asset),
                        'audio_waveform_url' => $this->audioWaveformUrl($asset),
                        // Pipeline status for visible progression (uploading → complete)
                        'analysis_status' => $asset->analysis_status ?? 'uploading',
                        // Asset health badge (healthy|warning|critical) for support visibility
                        'health_status' => $asset->computeHealthStatus($incidentSeverityByAsset[$asset->id] ?? null),
                        // Brand Guidelines Builder: staged reference materials (unpublished, no category)
                        'builder_staged' => (bool) ($asset->builder_staged ?? false),
                        // Intake queue: staged = awaiting category before main library grid
                        'intake_state' => (string) ($asset->intake_state ?? 'normal'),
                        // Brand Intelligence (EBI): human-readable drawer payload (no raw overall score)
                        'brand_intelligence' => $asset->brandIntelligencePayloadForFrontend(),
                        'reference_promotion' => $asset->brandReferenceAsset?->toFrontendArray(),
                        'thumbnail_mode_urls' => ThumbnailModeDeliveryUrls::map($asset),
                        'thumbnail_modes_meta' => ThumbnailModeDeliveryUrls::modesMetaForApi($asset),
                        'thumbnail_modes_status' => $metadata['thumbnail_modes_status'] ?? null,
                        'matched_moment' => $this->firstMatchedVideoMoment($metadata, $searchQueryTrimmed),
                    ];
                    if ($finalThumbnailUrl && str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                        Log::info('ASSET API RESPONSE URL', [
                            'asset_id' => $asset->id,
                            'thumbnail_url' => $finalThumbnailUrl,
                            'thumbnail_url_large' => $this->thumbnailUrlLarge($asset),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('[AssetController::index] map asset failed', [
                        'asset_id' => $asset->id ?? null,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    return [
                        'id' => $asset->id,
                        'title' => $asset->original_filename ?? 'Asset',
                        'original_filename' => $asset->original_filename,
                        'mime_type' => $asset->mime_type,
                        'file_extension' => null,
                        'status' => 'visible',
                        'metadata' => DerivativeFailureUserMessaging::workspaceMetadata($asset->metadata ?? []),
                        'starred' => false,
                        'category' => null,
                        'user_id' => $asset->user_id ?? null,
                        'uploaded_by' => null,
                        'submitted_by_prostaff' => (bool) ($asset->submitted_by_prostaff ?? false),
                        'prostaff_user_id' => isset($asset->prostaff_user_id) && $asset->prostaff_user_id !== null ? (int) $asset->prostaff_user_id : null,
                        'prostaff_user_name' => null,
                        'is_prostaff_asset' => (bool) ($asset->submitted_by_prostaff ?? false),
                        'preview_thumbnail_url' => null,
                        'final_thumbnail_url' => null,
                        'thumbnail_url_large' => null,
                        'thumbnail_url' => null,
                        'thumbnail_small' => null,
                        'thumbnail_medium' => null,
                        'thumbnail_large' => null,
                        'thumbnail_preview' => null,
                        'original' => null,
                        'thumbnail_status' => 'pending',
                        'thumbnail_error' => null,
                        'pdf_page_count' => null,
                        'pdf_pages_rendered' => false,
                        'published_at' => null,
                        'is_published' => false,
                        'published_by' => null,
                        'archived_at' => null,
                        'archived_by' => null,
                        'video_preview_url' => null,
                        'video_poster_url' => null,
                        'audio_playback_url' => null,
                        'audio_waveform_url' => null,
                        'analysis_status' => 'uploading',
                        'health_status' => 'healthy',
                        'brand_intelligence' => null,
                        'reference_promotion' => null,
                        'thumbnail_mode_urls' => [],
                        'thumbnail_modes_meta' => [],
                        'thumbnail_modes_status' => null,
                        'matched_moment' => null,
                    ];
                }
            })
                ->values()
                ->all();

            $mappedAssets = $this->prependGoogleFontVirtualAssetsIfNeeded(
                $request,
                $mappedAssets,
                $brand,
                $category,
                $paginator,
                $isStagedView,
                $isReferenceMaterialsView,
                $isTrashView,
                $isLoadMore
            );

            $t2 = microtime(true);

            // Count assets that trigger video preview URL generation
            $assetsWithVideoPreview = $assetModels->filter(function ($a) {
                return $a->mime_type && str_starts_with((string) $a->mime_type, 'video/')
                    && ! empty($a->metadata['video_preview'] ?? null);
            })->count();

            if (! $isLoadMore) {
                Log::info('[ASSET_GRID_TIMING] AssetController::index', [
                    'total_ms' => round((microtime(true) - $t0) * 1000),
                    'after_query_ms' => round(($t1 - $t0) * 1000),
                    'after_transform_ms' => round(($t2 - $t1) * 1000),
                    'assets_count' => count($mappedAssets),
                    's3_presign_count' => $assetsWithVideoPreview,
                    'note' => 'video_preview_url via AssetDeliveryService per video asset',
                ]);
            }

            // Keep collection for availableValues block (expects $assets as collection of arrays)
            $assets = collect($mappedAssets);

            // Load-more: return JSON only so the client can append without Inertia replacing the list.
            // Do this before filterableSchema/availableValues so page 2+ never runs that heavy logic.
            if ($isLoadMore) {
                return response()->json([
                    'data' => $mappedAssets,
                    'next_page_url' => $nextPageUrl,
                ]);
            }

            // format=json: return plain JSON for pickers/modals (avoids Inertia 409 version mismatch)
            if ($request->get('format') === 'json') {
                return response()->json([
                    'assets' => $mappedAssets,
                    'categories' => $allCategories->values()->all(),
                    'categories_by_type' => ['all' => $allCategories->values()->all()],
                    'next_page_url' => $nextPageUrl,
                ]);
            }

        } catch (\Throwable $e) {
            if ($isLoadMore) {
                Log::error('[LOAD_MORE_500] AssetController::index load_more exception', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            throw $e;
        }

        // Phase 2 – Step 8: Get filterable schema for frontend
        // Note: asset_type in category is organizational (asset/marketing/ai_generated),
        // but MetadataSchemaResolver expects file type (image/video/document)
        // Default to 'image' for schema resolution when category context doesn't provide file type
        $filterableSchema = [];

        // Phase L.5.1: Enable filters in "All Categories" view
        // Resolve schema even when categoryId is null to allow system-level filters
        if ($categoryId && $category) {
            $fileType = $this->resolveMetadataFileTypeForCategory($category);

            $schema = $this->metadataSchemaResolver->resolve(
                $tenant->id,
                $brand->id,
                $categoryId,
                $fileType
            );

            // Phase C2/C4: Pass category and tenant models for suppression check (via MetadataVisibilityResolver)
            $filterableSchema = $this->metadataFilterService->getFilterableFields($schema, $category, $tenant);
        } elseif (! $categoryId) {
            // "All Categories" view: Resolve schema without category context
            // This enables system-level metadata fields (orientation, color_space, resolution_class, dominant_hue_group)
            // that are computed from assets themselves, not category-specific
            $fileType = 'image'; // Default file type for metadata schema resolution

            $schema = $this->metadataSchemaResolver->resolve(
                $tenant->id,
                $brand->id,
                null, // No category context
                $fileType
            );

            // Pass null category to mark system fields as global
            $filterableSchema = $this->metadataFilterService->getFilterableFields($schema, null, $tenant);
        }

        // Phase M: Hide filters with zero values in scoped dataset, except always-visible library keys
        if (! empty($filterableSchema)) {
            $keysWithValues = $this->metadataFilterService->getFieldKeysWithValuesInScope($baseQueryForFilterVisibility, $filterableSchema);
            $filterableSchema = $this->metadataFilterService->restrictFilterableSchemaToKeysWithValuesInScope($filterableSchema, $keysWithValues);
        }

        // available_values is required by Phase H filter visibility rules
        // Do not remove without updating Phase H contract
        // Compute distinct metadata values for the current asset grid result set
        //
        // 🔍 CRITICAL: Filter harvesting MUST use the SAME asset query scope as the grid
        // - Same lifecycle filtering (via LifecycleResolver)
        // - Same category filtering
        // - Same visibility rules
        // If these differ, filter options won't match visible assets
        $availableValues = [];
        $hueGroupToDisplayHex = []; // cluster key -> display_hex from HueClusterService

        // 🔍 Step 1: Prove assets used for option harvesting
        // CRITICAL: Use the SAME asset collection that appears in the grid
        // This ensures filter options match what users see
        foreach ($filterableSchema as $field) {
            $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
            if ($fieldKey === 'dominant_hue_group') {
                break;
            }
        }

        // Use current page asset IDs for filter options (initial request only; load_more does not run this block)
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

                // Safeguard: Always include dominant_hue_group so it's never excluded
                $hueGroupField = \DB::table('metadata_fields')->where('key', 'dominant_hue_group')->first();
                if ($hueGroupField && ! in_array($hueGroupField->id, $automaticFieldIds, true)) {
                    $automaticFieldIds[] = (int) $hueGroupField->id;
                }

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

                        // TASK 3: Use MetadataValueNormalizer for consistent normalization
                        // This handles edge cases where values may have been incorrectly stored
                        // For scalar fields (like dominant_hue_group), normalize to scalar
                        // For multiselect fields, keep as array
                        $isMultiselectField = in_array($fieldKey, ['dominant_colors', 'tags']); // Known multiselect fields

                        if ($isMultiselectField) {
                            // Multiselect fields: keep as array, extract individual items
                            if (is_array($value)) {
                                foreach ($value as $item) {
                                    $normalizedItem = MetadataValueNormalizer::normalizeScalar($item);
                                    if ($normalizedItem !== null && ! in_array($normalizedItem, $availableValues[$fieldKey], true)) {
                                        $availableValues[$fieldKey][] = $normalizedItem;
                                    }
                                }
                            } else {
                                // Single value in multiselect field - normalize and add
                                $normalized = MetadataValueNormalizer::normalizeScalar($value);
                                if ($normalized !== null && ! in_array($normalized, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $normalized;
                                }
                            }
                        } else {
                            // Scalar fields (like dominant_hue_group): normalize to scalar
                            $normalized = MetadataValueNormalizer::normalizeScalar($value);
                            if ($normalized !== null) {
                                if (! in_array($normalized, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $normalized;
                                }
                            } else {
                                // Invalid format (e.g., array with multiple elements for scalar field)
                                \App\Support\Logging\PipelineLogger::warning('FILTER DEBUG: invalid value format for scalar field', [
                                    'field_key' => $fieldKey,
                                    'value' => $value,
                                    'value_type' => gettype($value),
                                ]);
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
                // Note: $assets here is the mapped collection (arrays), not Asset models
                $assetsWithMetadata = $assets->filter(function ($item) {
                    $meta = $item['metadata'] ?? null;

                    return ! empty($meta) && isset($meta['fields']);
                });

                foreach ($assetsWithMetadata as $item) {
                    $fields = ($item['metadata'] ?? [])['fields'] ?? [];
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

                // Source 2b: Top-level metadata (starred, quality_rating) — synced to metadata root, not metadata.fields
                $topLevelFilterKeys = ['starred', 'quality_rating'];
                foreach ($assets as $item) {
                    $meta = $item['metadata'] ?? [];
                    foreach ($topLevelFilterKeys as $key) {
                        if (! isset($filterableFieldKeys[$key])) {
                            continue;
                        }
                        if (! array_key_exists($key, $meta)) {
                            continue;
                        }
                        $v = $meta[$key];
                        if (! isset($availableValues[$key])) {
                            $availableValues[$key] = [];
                        }
                        $normalized = is_numeric($v) ? (int) $v : $v;
                        if (! in_array($normalized, $availableValues[$key], true)) {
                            $availableValues[$key][] = $normalized;
                        }
                    }
                }

                // TASK 2: Log value availability check before removing empty arrays
                foreach ($availableValues as $fieldKey => $values) {
                    if (empty($values)) {
                        \App\Support\Logging\PipelineLogger::warning('FILTER DEBUG: field excluded', [
                            'key' => $fieldKey,
                            'reason' => 'no_available_values',
                            'values_count' => count($values),
                        ]);
                    }
                }

                // C9.2: Source 3 - Collection filter values from asset_collections pivot (not asset_metadata)
                // Primary filter for collection requires available_values; harvest from pivot for current asset set
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
                // Primary filter for tags requires available_values; harvest from asset_tags for current asset set
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

                // Seed available_values for primary fields only when none exist yet (so filter still shows).
                // When at least one value exists in the current asset set, do NOT merge in all schema options —
                // primary filter dropdown should show only options that exist (limit options based on what exists).
                foreach ($filterableSchema as $field) {
                    $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
                    $isPrimary = ($field['is_primary'] ?? false) === true;
                    if (! $fieldKey || ! $isPrimary || ! isset($filterableFieldKeys[$fieldKey])) {
                        continue;
                    }
                    $existing = $availableValues[$fieldKey] ?? [];
                    if (! empty($existing)) {
                        continue; // Already have values from current asset set — do not seed with all options
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
                        $availableValues[$fieldKey] = array_values(array_unique(array_merge($existing, $optionValues)));
                        sort($availableValues[$fieldKey]);
                    }
                }

                // Dominant hue: options must include every hue present in the full filtered scope (hueClusterCounts),
                // not only on the first page. Otherwise hues missing from page 1 never appear in the swatch row.
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

        // Attach color swatch data to dominant_hue_group filter options (filter_type = 'color')
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
            // C9.2: Attach collection options (id => name) so primary filter is a dropdown with labels
            if ($fieldKey === 'collection') {
                $collectionIds = $availableValues['collection'] ?? [];
                $collections = $collectionIds
                    ? \App\Models\Collection::whereIn('id', $collectionIds)->pluck('name', 'id')->all()
                    : [];
                $field['options'] = array_values(array_map(fn ($id) => [
                    'value' => (string) $id,
                    'label' => $collections[$id] ?? (string) $id,
                    'display_label' => $collections[$id] ?? (string) $id, // FilterFieldInput uses display_label
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

        $t3 = microtime(true);
        if (! $isLoadMore) {
            Log::info('[ASSET_GRID_TIMING] AssetController::index before Inertia', [
                'total_ms' => round(($t3 - $t0) * 1000),
                'before_return_ms' => round(($t3 - $t2) * 1000),
            ]);
        }

        $bulkCategoriesByAssetType = $this->buildBulkAssignCategoryOptionsByAssetType($tenant, $brand, $user);

        return Inertia::render('Assets/Index', [
            'tenant' => $tenant ? ['id' => $tenant->id] : null, // For Tags filter autocomplete
            'categories' => $allCategories,
            'bulk_categories_by_asset_type' => $bulkCategoriesByAssetType,
            'categories_by_type' => [
                'all' => $allCategories,
            ],
            'selected_category' => $categoryId ? (int) $categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'total_asset_count' => $isStagedView ? $stagedCount : $totalAssetCount, // Staged view: staged count; main grid: category total
            'assets' => $mappedAssets,
            'next_page_url' => $nextPageUrl,
            'filtered_grid_total' => (int) $paginator->total(),
            'grid_folder_total' => $gridFolderTotal,
            'filterable_schema' => $filterableSchema, // Phase 2 – Step 8: Filterable metadata fields
            'available_values' => $availableValues, // available_values is required by Phase H filter visibility rules
            'filters' => $filters, // Server-parsed filters for active filter UI sync with URL
            'sort' => $sort,
            'sort_direction' => $sortDirection,
            'q' => $request->input('q', ''),
            'content_type' => is_string($request->input('content_type')) && $request->input('content_type') !== ''
                ? $request->input('content_type')
                : 'all',
            'lifecycle' => $lifecycleParam, // Phase B2: e.g. 'deleted' for trash view
            'can_view_trash' => $canViewTrash,
            'trash_count' => $trashCount,
            'source' => $sourceParam, // e.g. 'reference_materials', 'staged'
            'reference_materials_count' => $referenceMaterialsCount,
            'staged_count' => $stagedCount,
            'uploaded_by_users' => $uploadedByUsersPayload,
            'prostaff_filter_options' => app(GetProstaffDamFilterOptions::class)->activeMemberOptionsForBrand($brand),
            'dam_prostaff_filter_config' => GetProstaffDamFilterOptions::damProstaffFilterConfig(),
            'pending_publication_review_count' => $pendingPublicationReviewCount,
            'rejected_my_uploads_count' => $rejectedMyUploadsCount,
        ]);
    }

    /**
     * GET /app/assets/processing
     *
     * Returns assets currently processing for the **authenticated user only** (uploader),
     * so the processing tray does not appear for teammates when someone else uploads.
     *
     * CRITICAL RULES:
     * - Scoped by tenant, brand, and user_id (uploader)
     * - Only returns assets with terminal states excluded (pending, processing, or null)
     * - Includes TTL check to detect stale jobs (>10 minutes)
     * - Terminal states (completed, failed, skipped) are never returned
     */
    public function activeProcessingJobs(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json([
                'active_jobs' => [],
                'stale_count' => 0,
            ]);
        }

        $now = now();
        $staleThreshold = 10; // 10 minutes
        $staleCount = 0;

        try {
            // CRITICAL: Only this user's uploads — not tenant-wide (avoids tray for every member)
            // Terminal states (failed, skipped, completed) are automatically excluded
            // Studio composition video assets are "done" for users once the job completes; thumbnail generation
            // can lag and wrongly keeps the global tray on "Preparing video preview…". Status is shown in the
            // editor Versions / studio job UI instead.
            $processingAssets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('user_id', $user->id)
                ->where('source', '!=', 'studio_animation')
                ->where(function ($query) {
                    // Only include actively processing states: pending, processing, or null (legacy)
                    $query->where('thumbnail_status', \App\Enums\ThumbnailStatus::PENDING->value)
                        ->orWhere('thumbnail_status', \App\Enums\ThumbnailStatus::PROCESSING->value)
                        ->orWhereNull('thumbnail_status'); // Legacy assets (null = pending)
                })
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->limit(100) // Reasonable limit
                ->get([
                    'id',
                    'user_id',
                    'title',
                    'original_filename',
                    'mime_type',
                    'source',
                    'thumbnail_status',
                    'thumbnail_error',
                    'status',
                    'created_at',
                ])
                ->map(function ($asset) use ($now, $staleThreshold, &$staleCount) {
                    $ageMinutes = $asset->created_at->diffInMinutes($now);
                    $isStale = $ageMinutes > $staleThreshold;

                    if ($isStale) {
                        $staleCount++;
                        Log::warning('[AssetProcessingTray] Stale processing job detected', [
                            'asset_id' => $asset->id,
                            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
                            'age_minutes' => $ageMinutes,
                            'created_at' => $asset->created_at->toIso8601String(),
                        ]);
                    }

                    return [
                        'id' => $asset->id,
                        'user_id' => $asset->user_id,
                        'title' => $asset->title ?? $asset->original_filename ?? 'Untitled Asset',
                        'mime_type' => $asset->mime_type,
                        'source' => $asset->source,
                        'thumbnail_status' => $asset->thumbnail_status?->value ?? 'pending',
                        'thumbnail_error' => DerivativeFailureUserMessaging::workspaceThumbnailError($asset->thumbnail_error),
                        'status' => $asset->status?->value ?? 'pending',
                        'created_at' => $asset->created_at->toIso8601String(),
                        'age_minutes' => $ageMinutes,
                        'is_stale' => $isStale,
                    ];
                })
                ->filter(function ($asset) {
                    // Filter out stale jobs from active list (but count them)
                    return ! $asset['is_stale'];
                })
                ->values()
                ->toArray();

            Log::info('[AssetProcessingTray] Active processing jobs fetched', [
                'active_count' => count($processingAssets),
                'stale_count' => $staleCount,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'active_jobs' => $processingAssets,
                'stale_count' => $staleCount,
                'fetched_at' => $now->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('[AssetProcessingTray] Error fetching active processing jobs', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);

            // 200 so background polling does not trigger global error UI / red network noise
            return response()->json([
                'active_jobs' => [],
                'stale_count' => 0,
                'fetched_at' => now()->toIso8601String(),
                'degraded' => true,
                'error' => 'Failed to fetch processing jobs',
            ]);
        }
    }

    /**
     * GET /app/assets/thumbnail-status/batch
     *
     * Batch endpoint for checking thumbnail status of multiple assets.
     * Used by smart polling to efficiently check which assets have final thumbnails ready.
     */
    public function batchThumbnailStatus(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (! $tenant || ! $brand || ! $user) {
            return response()->json([
                'assets' => [],
            ]);
        }

        // Get asset IDs from request (comma-separated or array)
        $assetIds = $request->input('asset_ids', []);

        if (is_string($assetIds)) {
            $assetIds = explode(',', $assetIds);
        }

        if (! is_array($assetIds) || empty($assetIds)) {
            return response()->json([
                'assets' => [],
            ]);
        }

        // HARD TERMINAL STATE: Check for stuck assets before returning status
        // This prevents infinite processing states by automatically failing
        // assets that have been processing longer than the timeout threshold
        $timeoutGuard = app(\App\Services\ThumbnailTimeoutGuard::class);
        $stuckAssets = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereIn('id', $assetIds)
            ->where('thumbnail_status', \App\Enums\ThumbnailStatus::PROCESSING)
            ->get();

        foreach ($stuckAssets as $asset) {
            $timeoutGuard->checkAndRepair($asset);
        }

        // Limit to reasonable batch size
        $assetIds = array_slice($assetIds, 0, 50);
        $includeEnhancedOutputFreshness = count($assetIds) === 1;

        try {
            $assets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('id', $assetIds)
                ->whereNull('deleted_at')
                ->with('storageBucket')
                ->get(['id', 'thumbnail_status', 'thumbnail_error', 'metadata', 'storage_bucket_id', 'analysis_status'])
                ->map(function ($asset) use ($includeEnhancedOutputFreshness) {
                    $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                        ? $asset->thumbnail_status->value
                        : ($asset->thumbnail_status ?? 'pending');

                    $metadata = $asset->metadata ?? [];
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;

                    // Step 4: CRITICAL - Never return status COMPLETED if final thumbnail URL does not exist
                    // Verify thumbnail file exists before returning COMPLETED status
                    // This prevents UI from showing "completed" when files don't exist
                    $finalThumbnailUrl = null;
                    $verifiedStatus = $thumbnailStatus;

                    if ($thumbnailStatus === 'completed') {
                        // Verify thumbnail file actually exists before returning COMPLETED
                        $thumbnailPath = $asset->thumbnailPathForStyle('thumb');

                        if ($thumbnailPath && $asset->storageBucket) {
                            try {
                                // Create S3 client for verification
                                $s3Client = $this->createS3ClientForVerification();
                                $result = $s3Client->headObject([
                                    'Bucket' => $asset->storageBucket->name,
                                    'Key' => $thumbnailPath,
                                ]);

                                // Verify file size > minimum threshold (1KB)
                                $contentLength = $result['ContentLength'] ?? 0;
                                $minValidSize = 1024; // 1KB

                                if ($contentLength >= $minValidSize) {
                                    // File exists and is valid - return CDN URL
                                    $finalThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);
                                    if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                                        $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
                                    }
                                } else {
                                    // File exists but is too small - downgrade to failed
                                    $verifiedStatus = 'failed';
                                    Log::warning('[batchThumbnailStatus] Thumbnail file too small, downgrading status', [
                                        'asset_id' => $asset->id,
                                        'content_length' => $contentLength,
                                        'expected_min' => $minValidSize,
                                    ]);
                                }
                            } catch (\Aws\S3\Exception\S3Exception $e) {
                                if ($e->getStatusCode() === 404) {
                                    // File doesn't exist - downgrade to failed
                                    $verifiedStatus = 'failed';
                                    Log::warning('[batchThumbnailStatus] Thumbnail file not found, downgrading status', [
                                        'asset_id' => $asset->id,
                                        'thumbnail_path' => $thumbnailPath,
                                    ]);
                                } else {
                                    // Other S3 error - log but don't downgrade (might be transient)
                                    Log::error('[batchThumbnailStatus] S3 error checking thumbnail', [
                                        'asset_id' => $asset->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        } else {
                            // No thumbnail path or bucket - downgrade to failed
                            $verifiedStatus = 'failed';
                            Log::warning('[batchThumbnailStatus] Thumbnail path or bucket missing, downgrading status', [
                                'asset_id' => $asset->id,
                                'has_path' => (bool) $thumbnailPath,
                                'has_bucket' => (bool) $asset->storageBucket,
                            ]);
                        }
                    }

                    // Get skip reason from metadata if status is skipped
                    $skipReason = null;
                    $previewUnavailableMessage = null;
                    if ($verifiedStatus === 'skipped') {
                        $metadata = $asset->metadata ?? [];
                        $skipReason = $metadata['thumbnail_skip_reason'] ?? 'unsupported_file_type';
                        $previewUnavailableMessage = $metadata['preview_unavailable_user_message'] ?? null;
                    }

                    // Preview thumbnail URL - returned even when status is pending or processing
                    $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;

                    $modesStatus = $metadata['thumbnail_modes_status'] ?? null;

                    return [
                        'asset_id' => $asset->id,
                        'analysis_status' => $asset->analysis_status ?? 'uploading',
                        'thumbnail_status' => $verifiedStatus, // Use verified status, not raw status
                        'thumbnail_version' => $thumbnailVersion,
                        'preview_thumbnail_url' => $previewThumbnailUrl, // Preview thumbnail (available even when pending/processing)
                        'final_thumbnail_url' => $finalThumbnailUrl, // Only set if file exists and is valid
                        'thumbnail_error' => DerivativeFailureUserMessaging::workspaceThumbnailError($asset->thumbnail_error),
                        'thumbnail_skip_reason' => $skipReason, // Skip reason for skipped assets
                        'preview_unavailable_user_message' => $previewUnavailableMessage,
                        'thumbnail_mode_urls' => ThumbnailModeDeliveryUrls::map($asset),
                        'thumbnail_modes_meta' => ThumbnailModeDeliveryUrls::modesMetaForApi($asset, $includeEnhancedOutputFreshness),
                        'thumbnail_modes_status' => is_array($modesStatus) ? $modesStatus : null,
                    ];
                })
                ->values()
                ->toArray();

            return response()->json([
                'assets' => $assets,
            ]);
        } catch (\Exception $e) {
            Log::error('[AssetController] Error fetching batch thumbnail status', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);

            return response()->json([
                'assets' => [],
                'degraded' => true,
            ]);
        }
    }

    /**
     * Create S3 client instance for file verification.
     */
    protected function createS3ClientForVerification(): \Aws\S3\S3Client
    {
        if (! class_exists(\Aws\S3\S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new \Aws\S3\S3Client($config);
    }

    /**
     * Get processing status for an asset (thumbnail generation status).
     *
     * GET /assets/{asset}/processing-status
     */
    public function processingStatus(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');

        if (! $tenant || ! $brand) {
            return $this->processingStatusDegradedJson($asset);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, true)) {
            return $response;
        }

        try {
            // Refresh asset to ensure we have the latest thumbnail_status from database
            $asset->refresh();

            // Get thumbnail status from Asset model
            // thumbnail_status is the source of truth for thumbnail generation state
            // Values: 'pending', 'processing', 'completed', 'failed' (ThumbnailStatus enum)
            $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
                ? $asset->thumbnail_status->value
                : ($asset->thumbnail_status ?? 'pending');

            // Generate distinct thumbnail URLs for preview and final (CDN URLs)
            $metadata = $asset->metadata ?? [];
            $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;

            $finalThumbnailUrl = null;
            $thumbnailVersion = null;

            // Final thumbnail URL only provided when thumbnail_status === COMPLETED
            if ($thumbnailStatus === 'completed') {
                $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                $finalThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);
                if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                    $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
                }
            }

            $modesStatus = $metadata['thumbnail_modes_status'] ?? null;

            return response()->json([
                'thumbnail_status' => $thumbnailStatus,
                'preview_thumbnail_url' => $previewThumbnailUrl,
                'final_thumbnail_url' => $finalThumbnailUrl,
                'thumbnail_version' => $thumbnailVersion,
                'thumbnail_url' => $finalThumbnailUrl ?? null, // Legacy compatibility
                'thumbnails_generated_at' => $thumbnailVersion, // Legacy compatibility
                'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null, // Skip reason for skipped assets
                'preview_unavailable_user_message' => $metadata['preview_unavailable_user_message'] ?? null,
                'pdf_page_count' => $asset->pdf_page_count,
                'pdf_pages_rendered' => (bool) ($asset->pdf_pages_rendered ?? false),
                'thumbnail_mode_urls' => ThumbnailModeDeliveryUrls::map($asset),
                'thumbnail_modes_meta' => ThumbnailModeDeliveryUrls::modesMetaForApi($asset, true),
                'thumbnail_modes_status' => is_array($modesStatus) ? $modesStatus : null,
            ], 200);
        } catch (\Throwable $e) {
            Log::warning('[AssetController::processingStatus] Degraded JSON response (thumbnail poll)', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return $this->processingStatusDegradedJson($asset);
        }
    }

    /**
     * Safe 200 for background thumbnail polling when delivery URLs or context fail.
     */
    protected function processingStatusDegradedJson(Asset $asset): JsonResponse
    {
        try {
            $asset->refresh();
        } catch (\Throwable) {
            // keep model as bound
        }

        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
            ? $asset->thumbnail_status->value
            : ($asset->thumbnail_status ?? 'pending');
        $metadata = $asset->metadata ?? [];

        return response()->json([
            'thumbnail_status' => $thumbnailStatus,
            'preview_thumbnail_url' => null,
            'final_thumbnail_url' => null,
            'thumbnail_version' => null,
            'thumbnail_url' => null,
            'thumbnails_generated_at' => null,
            'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null,
            'preview_unavailable_user_message' => $metadata['preview_unavailable_user_message'] ?? null,
            'pdf_page_count' => $asset->pdf_page_count,
            'pdf_pages_rendered' => (bool) ($asset->pdf_pages_rendered ?? false),
            'thumbnail_mode_urls' => [],
            'thumbnail_modes_meta' => [],
            'thumbnail_modes_status' => null,
            'degraded' => true,
        ], 200);
    }

    /**
     * Get activity events for an asset.
     *
     * GET /assets/{asset}/activity
     */
    public function activity(Request $request, Asset $asset): JsonResponse
    {
        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, true)) {
            return $response;
        }

        $tenant = app('tenant');

        // Get activity events for this asset
        $events = ActivityEvent::where('tenant_id', $tenant->id)
            ->where('subject_type', Asset::class)
            ->where('subject_id', $asset->id)
            ->orderBy('created_at', 'desc')
            ->limit(20) // Limit to most recent 20 events
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at?->toIso8601String(),
                    'actor_type' => $event->actor_type,
                    'actor_id' => $event->actor_id,
                ];
            });

        return response()->json([
            'events' => $events,
        ], 200);
    }

    /**
     * View an asset: JSON view URL for AJAX (e.g. AssetDrawer video) or Inertia page for full-page view (e.g. collection-only user).
     *
     * GET /assets/{asset}/view
     */
    public function view(Request $request, Asset $asset): JsonResponse|\Illuminate\Http\Response|Response
    {
        Gate::authorize('view', $asset);

        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return $this->previewUrl($request, $asset);
        }

        if ($this->mayStreamInlineFontPreview($asset) && $this->requestLooksLikeInlineFontFetch($request)) {
            return $this->inlineWebFontBinaryResponse($asset);
        }

        $tenant = app('tenant');
        $collectionOnly = app()->bound('collection_only') && app('collection_only');
        $collection = $collectionOnly && app()->bound('collection') ? app('collection') : null;

        // Build thumbnail URLs (CDN URLs from Asset model)
        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
            ? $asset->thumbnail_status->value
            : ($asset->thumbnail_status ?? 'pending');
        $previewThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;
        $finalThumbnailUrl = null;
        if ($thumbnailStatus === 'completed') {
            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            $finalThumbnailUrl = $asset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED);
            if ($finalThumbnailUrl && $thumbnailVersion && ! str_contains($finalThumbnailUrl, 'X-Amz-Signature')) {
                $finalThumbnailUrl .= (str_contains($finalThumbnailUrl, '?') ? '&' : '?').'v='.urlencode($thumbnailVersion);
            }
        }
        $thumbnailUrl = $finalThumbnailUrl ?: $previewThumbnailUrl;
        $fileExtension = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        $isPdf = strtolower((string) ($asset->mime_type ?? '')) === 'application/pdf' || $fileExtension === 'pdf';
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $usesPdfPagePreview = $isPdf || (
            $fileTypeService->isOfficeDocument(strtolower((string) ($asset->mime_type ?? '')), $fileExtension)
            && ! empty($metadata['office']['preview_pdf_path'] ?? null)
        );
        $pdfPageCount = $asset->pdf_page_count ?? ($metadata['pdf_page_count'] ?? null);
        $firstPageUrl = $usesPdfPagePreview ? ($finalThumbnailUrl ?: $previewThumbnailUrl) : null;
        $pdfPageApiEndpoint = $usesPdfPagePreview
            ? route('assets.pdf-page.show', ['asset' => $asset->id, 'page' => '__PAGE__'])
            : null;

        AssetSessionWorkspace::assertMatchesSession($request, $asset, true);

        $asset->load(['collections' => fn ($q) => $q->select('collections.id', 'collections.name')]);
        $payload = [
            'id' => $asset->id,
            'title' => $asset->title,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'pdf_pages_rendered' => (bool) ($asset->pdf_pages_rendered ?? false),
            'thumbnail_url' => $thumbnailUrl,
            'download_url' => route('assets.download', ['asset' => $asset->id]),
            'collection_only' => $collectionOnly,
            'collection' => $collection ? ['id' => $collection->id, 'name' => $collection->name] : null,
            'is_pdf' => $isPdf,
            'uses_pdf_page_preview' => $usesPdfPagePreview,
            'pdf_page_count' => $pdfPageCount,
            'first_page_url' => $firstPageUrl,
            'pdf_page_api_endpoint' => $pdfPageApiEndpoint,
        ];

        return Inertia::render('Assets/View', ['asset' => $payload]);
    }

    /**
     * Download an asset. Redirects to a signed storage URL.
     * GET /assets/{asset}/download
     */
    public function download(Request $request, Asset $asset): RedirectResponse
    {
        Gate::authorize('view', $asset);

        AssetSessionWorkspace::assertMatchesSession($request, $asset, true);

        $url = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);
        if (! $url) {
            abort(404, 'File not available.');
        }

        return redirect($url);
    }

    /**
     * Get signed preview URL for an asset.
     *
     * GET /assets/{asset}/preview-url
     */
    public function previewUrl(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, true)) {
            return $response;
        }

        // Video playback (AssetDrawer, etc.): do not require full AI/metadata completion — only that
        // the file is integrated enough to stream. Strict isComplete() blocks 422 while tagging lags.
        if ($this->assetIsStreamableVideo($asset)) {
            return $this->jsonVideoStreamUrl($asset);
        }

        // Verify asset processing is completed (check processing state, not status)
        $completionService = app(\App\Services\AssetCompletionService::class);
        if (! $completionService->isComplete($asset)) {
            return response()->json([
                'message' => 'Asset preview not available - asset is still processing',
            ], 422);
        }

        $previewUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;

        return response()->json([
            'url' => $previewUrl,
            'expires_at' => $previewUrl ? now()->addMinutes(15)->toIso8601String() : null,
        ], 200);
    }

    /**
     * True when the asset should be treated as video for relaxed preview-url / view JSON rules.
     */
    private function assetIsStreamableVideo(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_starts_with($mime, 'video/')) {
            return true;
        }

        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpeg', 'mpg'], true);
    }

    /**
     * Signed URL for in-browser video in the drawer/lightbox.
     *
     * VIDEO_PREVIEW is the short muted hover clip only (grid + drawer thumbnail). Fullscreen/lightbox uses this
     * endpoint too and must stream the original so duration, quality, and audio match the source file.
     */
    private function jsonVideoStreamUrl(Asset $asset): JsonResponse
    {
        $url = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);
        if ($url === '' || $url === null) {
            $url = $asset->deliveryUrl(AssetVariant::VIDEO_PREVIEW, DeliveryContext::AUTHENTICATED);
        }

        if (! $url) {
            return response()->json([
                'message' => 'Video file not available yet',
            ], 422);
        }

        return response()->json([
            'url' => $url,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200);
    }

    /**
     * Stable processing JSON for virtual Google font grid rows (brand DNA + campaign; no DB asset).
     *
     * GET /app/assets/google-font-{n}-{hash}/processing-status
     * GET /app/assets/campaign-google-font-{collectionId}-{hash}/processing-status
     */
    public function virtualGoogleFontGridProcessingStatus(string $virtualGoogleFontGridId): JsonResponse
    {
        if (! preg_match('/^(campaign-google-font|google-font)-/', $virtualGoogleFontGridId)) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Satisfies drawer (processingGuardStatus) and thumbnail polling (processingStatus) callers.
        return response()->json([
            'thumbnail_status' => 'completed',
            'preview_thumbnail_url' => null,
            'final_thumbnail_url' => null,
            'thumbnail_version' => null,
            'thumbnail_url' => null,
            'thumbnails_generated_at' => null,
            'thumbnail_skip_reason' => null,
            'preview_unavailable_user_message' => null,
            'pdf_page_count' => null,
            'pdf_pages_rendered' => false,
            'thumbnail_mode_urls' => [],
            'thumbnail_modes_meta' => [],
            'thumbnail_modes_status' => null,
            'analysis_status' => null,
            'actions' => [],
        ], 200);
    }

    /**
     * Processing guard status for drawer UI (cooldowns, last run).
     *
     * GET /app/assets/{asset}/processing-status
     */
    public function processingGuardStatus(Request $request, Asset $asset): JsonResponse
    {
        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, true)) {
            return $response;
        }

        $this->authorize('view', $asset);

        $guard = app(AssetProcessingGuardService::class);
        $types = [
            AssetProcessingGuardService::ACTION_FULL_PIPELINE,
            AssetProcessingGuardService::ACTION_THUMBNAILS,
            AssetProcessingGuardService::ACTION_AI_METADATA,
        ];
        $actions = $guard->statusForAsset($asset, $types);

        return response()->json([
            'thumbnail_status' => $asset->thumbnail_status?->value ?? (string) $asset->thumbnail_status,
            'analysis_status' => $asset->analysis_status,
            'actions' => $actions,
        ]);
    }

    /**
     * Regenerate AI metadata (vision pipeline) for an asset — manual rerun.
     *
     * POST /app/assets/{asset}/ai-metadata/regenerate
     */
    public function regenerateAiMetadata(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        if (! $user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            $tenantRole = $user->getRoleForTenant($tenant);
            if (! in_array($tenantRole, ['owner', 'admin'], true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'You do not have permission to regenerate AI metadata',
                ], 403);
            }
        }

        $policyCheck = app(AiTagPolicyService::class)->shouldProceedWithAiTagging($asset);
        if (! $policyCheck['should_proceed']) {
            return response()->json([
                'success' => false,
                'error' => 'AI tagging is disabled for this tenant',
                'reason' => $policyCheck['reason'] ?? 'policy_denied',
            ], 403);
        }

        try {
            app(AiUsageService::class)->checkUsage($tenant, 'tagging', 1);
        } catch (PlanLimitExceededException $e) {
            return response()->json([
                'error' => 'Plan limit exceeded',
                'message' => $e->getMessage(),
            ], 403);
        }

        app(AssetProcessingGuardService::class)->assertCanDispatch($user, $asset, AssetProcessingGuardService::ACTION_AI_METADATA);

        try {
            // Chain: vision creates tag candidates; auto-apply promotes to asset_tags when tenant enables it.
            // Route to dedicated ai queue so vision calls do not contend with thumbnail/preview workers.
            $aiQueue = (string) config('queue.ai_queue', 'ai');
            Bus::chain([
                (new AiMetadataGenerationJob($asset->id, true))->onQueue($aiQueue),
                (new AiTagAutoApplyJob($asset->id))->onQueue($aiQueue),
            ])
                ->onQueue($aiQueue)
                ->dispatch();

            app(AssetProcessingGuardService::class)->markDispatched($user, $asset, AssetProcessingGuardService::ACTION_AI_METADATA);

            return response()->json([
                'success' => true,
                'message' => 'AI metadata regeneration queued',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[AssetController] Failed to queue AI metadata regeneration', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to queue AI metadata regeneration',
            ], 500);
        }
    }

    /**
     * Re-run AI vision + tag candidates (AiMetadataGenerationJob) on the ai queue after clearing completion flags.
     *
     * POST /app/assets/{asset}/ai-tagging/regenerate
     */
    public function regenerateAiTagging(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        if (! $user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            $tenantRole = $user->getRoleForTenant($tenant);
            if (! in_array($tenantRole, ['owner', 'admin'], true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'You do not have permission to regenerate AI tagging',
                ], 403);
            }
        }

        $policyCheck = app(AiTagPolicyService::class)->shouldProceedWithAiTagging($asset);
        if (! $policyCheck['should_proceed']) {
            return response()->json([
                'success' => false,
                'error' => 'AI tagging is disabled for this tenant',
                'reason' => $policyCheck['reason'] ?? 'policy_denied',
            ], 403);
        }

        $fileType = $this->fileTypeService->detectFileTypeFromAsset($asset);

        // Audio uses the transcript / mood pipeline (RunAudioAiAnalysisJob), not
        // vision-on-thumbnail. Many MP3s have thumbnail_status=skipped — the image
        // tagging chain would 422 here even though audio AI is valid.
        if ($fileType === 'audio') {
            $aiUsage = app(AiUsageService::class);
            $durationSeconds = (float) (($asset->metadata ?? [])['audio']['duration_seconds'] ?? 0.0);
            $creditsRequired = $aiUsage->getAudioInsightsCreditCost($durationSeconds / 60.0);

            try {
                $aiUsage->checkUsage($tenant, AudioAiAnalysisService::FEATURE_KEY, $creditsRequired);
            } catch (PlanLimitExceededException $e) {
                return response()->json([
                    'error' => 'Plan limit exceeded',
                    'message' => $e->getMessage(),
                ], 403);
            }

            $meta = $asset->metadata ?? [];
            $meta['audio'] = array_merge($meta['audio'] ?? [], ['ai_status' => 'queued']);
            unset($meta['_skip_ai_audio_analysis']);
            $asset->update(['metadata' => $meta]);

            $aiQueue = (string) config('queue.ai_queue', 'ai');

            try {
                RunAudioAiAnalysisJob::dispatch((string) $asset->id)->onQueue($aiQueue);

                ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAGGING_REGENERATED, [
                    'file_type' => 'audio',
                    'pipeline' => 'audio_insights',
                ], $user);

                return response()->json([
                    'success' => true,
                    'message' => 'Audio AI analysis regeneration queued',
                    'pipeline' => 'audio_insights',
                ], 200);
            } catch (\Throwable $e) {
                Log::error('[AssetController] Failed to queue audio AI tagging regeneration', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to queue audio AI analysis regeneration',
                ], 500);
            }
        }

        try {
            app(AiUsageService::class)->checkUsage($tenant, 'tagging', 1);
        } catch (PlanLimitExceededException $e) {
            return response()->json([
                'error' => 'Plan limit exceeded',
                'message' => $e->getMessage(),
            ], 403);
        }

        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            return response()->json([
                'success' => false,
                'error' => 'Thumbnails must be complete before regenerating AI tagging',
            ], 422);
        }

        $metadata = $asset->metadata ?? [];
        unset(
            $metadata['ai_tagging_completed'],
            $metadata['ai_tagging_completed_at'],
            $metadata['_ai_tagging_skipped'],
            $metadata['_ai_tagging_skip_reason'],
            $metadata['_ai_tagging_skipped_at'],
            $metadata['_ai_tagging_status'],
        );
        $asset->update(['metadata' => $metadata]);

        try {
            $aiQueue = (string) config('queue.ai_queue', 'ai');
            Bus::chain([
                (new AiMetadataGenerationJob($asset->id, true))->onQueue($aiQueue),
                (new AiTagAutoApplyJob($asset->id))->onQueue($aiQueue),
                (new AiMetadataSuggestionJob($asset->id))->onQueue($aiQueue),
            ])
                ->onQueue($aiQueue)
                ->dispatch();

            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_TAGGING_REGENERATED, [
                'file_type' => $fileType,
                'pipeline' => 'vision_tags',
            ], $user);

            return response()->json([
                'success' => true,
                'message' => 'AI tagging regeneration queued',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[AssetController] Failed to queue AI tagging regeneration', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to queue AI tagging regeneration',
            ], 500);
        }
    }


    /**
     * Rotate the stored original raster (clockwise), bake pixels, normalize orientation metadata, re-queue thumbnails.
     *
     * POST /assets/{asset}/original/rotate
     */
    public function rotateOriginal(Request $request, Asset $asset): JsonResponse
    {
        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        $this->authorize('update', $asset);

        $validated = $request->validate([
            'degrees_clockwise' => 'required|integer|in:90,180,270',
        ]);

        try {
            $result = app(\App\Services\AssetOriginalRasterRotationService::class)
                ->rotateCurrentVersionClockwise($asset, (int) $validated['degrees_clockwise']);

            $asset->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Image rotated and saved. Thumbnails are refreshing.',
                'asset' => [
                    'id' => $asset->id,
                    'width' => $asset->width,
                    'height' => $asset->height,
                    'size_bytes' => $asset->size_bytes,
                    'thumbnail_status' => $asset->thumbnail_status,
                    'updated_at' => $asset->updated_at?->toIso8601String(),
                ],
                'rotation' => $result,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            Log::warning('[AssetController::rotateOriginal] ' . $e->getMessage(), [
                'asset_id' => $asset->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[AssetController::rotateOriginal] Unexpected failure', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to rotate image.',
            ], 500);
        }
    }

    /**
     * Regenerate system metadata for an asset.
     *
     * POST /assets/{asset}/system-metadata/regenerate
     *
     * Regenerates system-computed metadata fields:
     * - orientation (landscape/portrait/square)
     * - color_space (srgb/adobe_rgb/display_p3)
     * - resolution_class (low/medium/high/ultra)
     * - dimensions (widthxheight)
     * - dominant_colors (top 3 dominant colors from image analysis)
     * - dominant_hue_group (perceptual hue cluster for filtering)
     */
    public function regenerateSystemMetadata(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        // Check permission - same as AI metadata regeneration
        if (! $user || ! $user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            // Also allow owners/admins
            $tenantRole = $user?->getRoleForTenant($tenant);
            $isOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

            if (! $isOwnerOrAdmin) {
                return response()->json([
                    'success' => false,
                    'error' => 'You do not have permission to regenerate system metadata',
                ], 403);
            }
        }

        try {
            // Use ComputedMetadataService to regenerate system metadata
            $computedMetadataService = app(\App\Services\ComputedMetadataService::class);
            $computedMetadataService->computeMetadata($asset);

            // Also regenerate dominant colors via PopulateAutomaticMetadataJob
            // This ensures dominant_colors and dominant_hue_group are regenerated
            \App\Jobs\PopulateAutomaticMetadataJob::dispatchSync($asset->id)->onQueue(config('queue.images_queue', 'images'));

            // Log activity event
            ActivityEvent::create([
                'tenant_id' => $tenant->id,
                'event_type' => \App\Enums\EventType::ASSET_SYSTEM_METADATA_REGENERATED,
                'subject_type' => Asset::class,
                'subject_id' => $asset->id,
                'actor_type' => 'user',
                'actor_id' => $user?->id,
                'metadata' => [
                    'fields_regenerated' => ['orientation', 'color_space', 'resolution_class', 'dimensions', 'dominant_colors', 'dominant_hue_group'],
                ],
            ]);

            Log::info('[AssetController] System metadata regenerated', [
                'asset_id' => $asset->id,
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'System metadata regenerated successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to regenerate system metadata', [
                'asset_id' => $asset->id,
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to regenerate system metadata: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Publish an asset.
     *
     * POST /assets/{asset}/publish
     */
    public function publish(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        try {
            $this->publicationService->publish($asset, $user);

            return response()->json([
                'message' => 'Asset published successfully',
                'asset_id' => $asset->id,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[AssetController::publish]', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to publish asset'], 500);
        }
    }

    /**
     * Finalize a staged asset: assign category, clear builder staging when applicable, set intake to normal, publish.
     *
     * Covers Brand Guidelines reference materials ({@see Asset::$builder_staged}) and standard intake-staged
     * uploads ({@see Asset::$intake_state} = staged) that have not yet been filed into a library category.
     *
     * POST /assets/{asset}/finalize-from-builder
     */
    public function finalizeFromBuilder(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, true)) {
            return $response;
        }

        $isBuilderStaged = (bool) $asset->builder_staged;
        $isIntakeStaged = (string) ($asset->intake_state ?? 'normal') === 'staged';
        if (! $isBuilderStaged && ! $isIntakeStaged) {
            return response()->json(['message' => 'This asset is not awaiting categorization (not staged).'], 422);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'run_ai_pipeline' => 'sometimes|boolean',
        ]);

        $categoryId = (int) $validated['category_id'];
        $category = Category::where('id', $categoryId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $runAi = $request->boolean('run_ai_pipeline', false);

        Gate::forUser($user)->authorize('publish', $asset);

        try {
            DB::transaction(function () use ($asset, $category, $user) {
                $metadata = $asset->metadata ?? [];
                $metadata['category_id'] = $category->id;
                $asset->metadata = $metadata;
                $asset->builder_staged = false;
                $asset->intake_state = 'normal';
                $asset->save();

                $this->publicationService->publish($asset, $user);
            });

            $asset->refresh();
            if ($runAi) {
                app(StagedFiledAssetAiService::class)->queueAfterStagedCategorization($asset, true);
            }

            return response()->json([
                'message' => 'Asset published and categorized successfully',
                'asset_id' => $asset->id,
                'category_id' => $category->id,
                'run_ai_pipeline' => $runAi,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[AssetController::finalizeFromBuilder]', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to finalize asset'], 500);
        }
    }

    /**
     * Assign or change library category; optionally queue vision tagging / video insights (same chain as staged release).
     *
     * POST /assets/{asset}/assign-category
     */
    public function assignCategory(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, true)) {
            return $response;
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'run_ai_pipeline' => 'sometimes|boolean',
        ]);

        $categoryId = (int) $validated['category_id'];
        $category = Category::where('id', $categoryId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $runAi = $request->boolean('run_ai_pipeline', false);

        try {
            Gate::forUser($user)->authorize('update', $asset);

            DB::transaction(function () use ($asset, $category) {
                $metadata = $asset->metadata ?? [];
                $metadata['category_id'] = $category->id;
                $asset->metadata = $metadata;
                if ((string) ($asset->intake_state ?? 'normal') === 'staged') {
                    $asset->intake_state = 'normal';
                }
                $asset->save();
            });

            if ($runAi) {
                $asset->refresh();
                app(StagedFiledAssetAiService::class)->queueAfterStagedCategorization($asset, true);
            }

            return response()->json([
                'message' => 'Category updated',
                'asset_id' => $asset->id,
                'category_id' => $category->id,
                'run_ai_pipeline' => $runAi,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            Log::error('[AssetController::assignCategory]', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to update category'], 500);
        }
    }

    /**
     * Unpublish an asset.
     *
     * POST /assets/{asset}/unpublish
     */
    public function unpublish(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        try {
            $this->publicationService->unpublish($asset, $user);

            return response()->json([
                'message' => 'Asset unpublished successfully',
                'asset_id' => $asset->id,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            Log::error('[AssetController::unpublish]', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to unpublish asset'], 500);
        }
    }

    /**
     * Archive an asset.
     *
     * POST /assets/{asset}/archive
     */
    public function archive(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        try {
            $this->archiveService->archive($asset, $user);

            return response()->json([
                'message' => 'Asset archived successfully',
                'asset_id' => $asset->id,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[AssetController::archive]', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to archive asset'], 500);
        }
    }

    /**
     * Restore an archived asset.
     *
     * POST /assets/{asset}/restore
     */
    public function restore(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        try {
            $this->archiveService->restore($asset, $user);

            return response()->json([
                'message' => 'Asset restored successfully',
                'asset_id' => $asset->id,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('[AssetController::restore]', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to restore asset'], 500);
        }
    }

    /**
     * Update the asset's original filename (display name).
     *
     * PATCH /assets/{asset}/filename
     *
     * Requires view access and metadata.edit_post_upload permission.
     */
    public function updateFilename(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }
        if (! $user->hasPermissionForTenant($tenant, 'metadata.edit_post_upload')) {
            return response()->json(['message' => 'You do not have permission to edit this asset.'], 403);
        }

        $validated = $request->validate([
            'original_filename' => ['required', 'string', 'max:512'],
        ]);

        $asset->original_filename = $validated['original_filename'];
        $asset->save();

        return response()->json([
            'message' => 'Filename updated',
            'original_filename' => $asset->original_filename,
        ], 200);
    }

    /**
     * Initiate a replace-file upload session.
     *
     * Phase J.3.1: File replacement for rejected contributor assets.
     * Also used for "Upload New Version" in the Versions tab.
     *
     * POST /assets/{asset}/replace-file
     *
     * Returns upload_session_id, upload_type, upload_url, chunk_size for the client
     * to upload the file, then finalize via POST /uploads/finalize.
     */
    public function initiateReplaceFile(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        $brand = app('brand');
        $user = auth()->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            $data = $response->getData(true);
            if (is_array($data) && isset($data['message'])) {
                $data['error'] = $data['message'];
            }

            return response()->json(is_array($data) ? $data : [], $response->getStatusCode());
        }

        $targetBrand = $asset->brand_id ? $asset->brand : ($brand ?? $tenant->defaultBrand);
        if (! $targetBrand) {
            return response()->json(['error' => 'No brand context available for this asset.'], 403);
        }

        if (! $user->canForContext('asset.upload', $tenant, $targetBrand)) {
            return response()->json(['error' => 'You do not have permission to replace files.'], 403);
        }

        $validated = $request->validate([
            'file_name' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1',
            'mime_type' => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->uploadInitiationService->initiateReplace(
                $tenant,
                $targetBrand,
                $asset,
                $validated['file_name'],
                $validated['file_size'],
                $validated['mime_type'] ?? null,
                null
            );

            return response()->json([
                'upload_session_id' => $result['upload_session_id'],
                'upload_type' => $result['upload_type'],
                'upload_url' => $result['upload_url'],
                'chunk_size' => $result['chunk_size'],
                'expires_at' => $result['expires_at'],
            ], 201);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            $message = $e->limitType === 'storage'
                ? 'Storage limit exceeded for your plan.'
                : $e->getMessage();

            return response()->json(['error' => $message], 403);
        } catch (\Exception $e) {
            Log::error('[AssetController::initiateReplaceFile]', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete an asset.
     *
     * DELETE /assets/{asset}
     */
    public function destroy(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        $user = auth()->user();

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $asset, false)) {
            return $response;
        }

        // Verify asset is not already deleted
        if ($asset->trashed()) {
            return response()->json([
                'message' => 'Asset is already deleted',
            ], 409);
        }

        try {
            // Soft delete the asset (pass user context)
            $this->deletionService->softDelete($asset, $user?->id);

            return response()->json([
                'message' => 'Asset deleted successfully',
                'asset_id' => $asset->id,
                'grace_period_days' => config('assets.deletion_grace_period_days', 30),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete asset: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted asset (undo delete before grace period expires).
     *
     * POST /assets/{asset}/restore-from-trash
     */
    public function restoreFromTrash(Request $request, string $asset): JsonResponse
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $assetModel = Asset::withTrashed()->findOrFail($asset);

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $assetModel, false)) {
            return $response;
        }

        if (! $assetModel->trashed()) {
            return response()->json(['message' => 'Asset is not deleted'], 409);
        }

        $this->authorize('delete', $assetModel); // Same permission as delete

        try {
            $this->deletionService->restoreFromTrash($assetModel, $user->id);

            return response()->json([
                'message' => 'Asset restored successfully',
                'asset_id' => $assetModel->id,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to restore asset: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Phase B2: Permanently delete a soft-deleted asset (force delete from trash).
     * DELETE /assets/{asset}/force-delete
     */
    public function forceDelete(Request $request, string $asset): JsonResponse
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $assetModel = Asset::withTrashed()->findOrFail($asset);

        if ($response = AssetSessionWorkspace::jsonMismatchResponse($request, $assetModel, false)) {
            return $response;
        }

        if (! $assetModel->trashed()) {
            return response()->json(['message' => 'Asset is not in trash'], 409);
        }

        $this->authorize('forceDelete', $assetModel);

        try {
            $this->deletionService->forceDelete($assetModel, $user->id);

            return response()->json([
                'message' => 'Asset permanently deleted',
                'asset_id' => $assetModel->id,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to permanently delete: '.$e->getMessage(),
            ], 500);
        }
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
     * When the grid search query matches a video moment label, surface it so the client can seek on open.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{timestamp: ?string, seconds: ?float, label: string, frame_index: ?int}|null
     */
    private function firstMatchedVideoMoment(array $metadata, string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $moments = $metadata['ai_video_insights']['moments'] ?? null;
        if (! is_array($moments)) {
            return null;
        }

        $interval = max(1, (int) ($metadata['ai_video_frame_interval_seconds'] ?? config('assets.video_ai.frame_interval_seconds', 3)));

        foreach ($moments as $m) {
            if (! is_array($m)) {
                continue;
            }
            $label = $m['label'] ?? '';
            if (! is_string($label) || $label === '') {
                continue;
            }
            if (! Str::contains($label, $query, true)) {
                continue;
            }

            $seconds = null;
            if (isset($m['seconds']) && is_numeric($m['seconds'])) {
                $seconds = (float) $m['seconds'];
            } elseif (isset($m['frame_index']) && is_numeric($m['frame_index'])) {
                $seconds = max(0.0, ((int) $m['frame_index'] - 1) * $interval);
            }

            return [
                'timestamp' => isset($m['timestamp']) && is_string($m['timestamp']) ? $m['timestamp'] : null,
                'seconds' => $seconds,
                'label' => $label,
                'frame_index' => isset($m['frame_index']) ? (int) $m['frame_index'] : null,
            ];
        }

        return null;
    }

    /**
     * Get video preview URL via AssetDeliveryService (empty if not available).
     */
    private function videoPreviewUrl(Asset $asset): ?string
    {
        $url = $asset->deliveryUrl(AssetVariant::VIDEO_PREVIEW, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Get video poster URL via AssetDeliveryService (empty if not available).
     */
    private function videoPosterUrl(Asset $asset): ?string
    {
        $url = $asset->deliveryUrl(AssetVariant::VIDEO_POSTER, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Phase 3: Signed playback URL for an audio asset, used by AudioCardVisual
     * and AudioLightboxPlayer for actual playback.
     *
     * Prefers the AUDIO_WEB derivative when present:
     *   - Browser-friendly 128 kbps MP3 produced by AudioPlaybackOptimizationService
     *   - Always present for WAV / FLAC sources, large M4A / AAC, and any source
     *     above `assets.audio.web_playback_min_source_bytes`.
     *   - Original is still available via the download flow; this URL is for
     *     streaming playback only.
     *
     * Falls back to ORIGINAL when no derivative has been written (the original
     * is itself MP3 / AAC / M4A / OGG and small enough for direct streaming).
     * Returns null for non-audio assets.
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

        $webPath = $asset->metadata['audio']['web_playback_path'] ?? null;
        if (is_string($webPath) && $webPath !== '') {
            $webUrl = $asset->deliveryUrl(AssetVariant::AUDIO_WEB, DeliveryContext::AUTHENTICATED);
            if (is_string($webUrl) && $webUrl !== '') {
                return $webUrl;
            }
        }

        $url = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Phase 3: Signed URL for the FFmpeg-rendered waveform PNG strip.
     * Empty when the waveform job hasn't run yet — frontend renders the
     * synthetic waveform until this becomes non-null.
     */
    private function audioWaveformUrl(Asset $asset): ?string
    {
        if (empty($asset->metadata['audio']['waveform_path'] ?? null)) {
            return null;
        }
        $url = $asset->deliveryUrl(AssetVariant::AUDIO_WAVEFORM, DeliveryContext::AUTHENTICATED);

        return $url !== '' ? $url : null;
    }

    /**
     * Prepend Brand Guidelines Google Fonts (no DAM file) when viewing the Fonts category on page 1.
     *
     * @param  list<array<string, mixed>>  $mappedAssets
     * @return list<array<string, mixed>>
     */
    private function prependGoogleFontVirtualAssetsIfNeeded(
        Request $request,
        array $mappedAssets,
        Brand $brand,
        ?Category $category,
        LengthAwarePaginator $paginator,
        bool $isStagedView,
        bool $isReferenceMaterialsView,
        bool $isTrashView,
        bool $isLoadMore
    ): array {
        if ($isStagedView || $isReferenceMaterialsView || $isTrashView || $isLoadMore) {
            return $mappedAssets;
        }
        if ($paginator->currentPage() !== 1) {
            return $mappedAssets;
        }
        if (! $category || $category->slug !== 'fonts') {
            return $mappedAssets;
        }

        // Same visibility as the grid: do not Gate::view here — hidden Fonts can appear for users who
        // see the category via shouldIncludeHiddenFontsCategory (font assets / filter) without manage categories.

        $virtual = app(GoogleFontLibraryEntriesService::class)->virtualAssetsForFontsCategory($brand, $category);
        $campaignVirtual = app(\App\Services\BrandDNA\CampaignGoogleFontLibraryEntriesService::class)
            ->virtualAssetsForFontsCategory($brand, $category);

        $prefix = [];
        if ($virtual !== []) {
            $prefix = array_merge($prefix, $virtual);
        }
        if ($campaignVirtual !== []) {
            $prefix = array_merge($prefix, $campaignVirtual);
        }

        return $prefix === [] ? $mappedAssets : array_merge($prefix, $mappedAssets);
    }

    /**
     * Staged intake + reference materials (References): brand admin/manager, tenant owner/admin/agency_admin, or agency-tenant members.
     */
    protected function userCanViewAssetSystemFolders(User $user, \App\Models\Tenant $tenant, Brand $brand): bool
    {
        $tenantRole = strtolower((string) $user->getRoleForTenant($tenant));
        if (in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true)) {
            return true;
        }

        $brandRole = strtolower((string) $user->getRoleForBrand($brand));
        if (in_array($brandRole, ['admin', 'brand_manager'], true)) {
            return true;
        }

        foreach ($user->tenants as $t) {
            if ($t->is_agency) {
                return true;
            }
        }

        return false;
    }

    /**
     * Browser {@see FontFace} / CSS {@code @font-face} GETs {@code /assets/{id}/view} without {@code Accept: application/json}.
     * Without this branch the HTML Inertia shell is returned and the font loader shows OTS decode errors.
     */
    private function requestLooksLikeInlineFontFetch(Request $request): bool
    {
        if (strtolower((string) $request->query('font_inline', '')) === '1') {
            return true;
        }

        return strtolower((string) $request->header('Sec-Fetch-Dest', '')) === 'font';
    }

    private function assetMimeLooksLikeWebFont(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_starts_with($mime, 'font/')) {
            return true;
        }
        if (in_array($mime, [
            'application/font-woff',
            'application/font-woff2',
            'application/vnd.ms-opentype',
            'application/x-font-ttf',
            'application/x-font-otf',
        ], true)) {
            return true;
        }

        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true)) {
            return true;
        }

        return $mime === 'application/octet-stream'
            && in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true);
    }

    /**
     * Same allow-list idea as {@see \App\Http\Controllers\Editor\EditorAssetBridgeController::editorMayStreamOriginalFile}
     * for binary font bytes (not HTML).
     */
    private function mayStreamInlineFontPreview(Asset $asset): bool
    {
        if (! $this->assetMimeLooksLikeWebFont($asset)) {
            return false;
        }
        if (in_array($asset->type, [AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED], true)) {
            return true;
        }

        return $asset->type === AssetType::REFERENCE;
    }

    private function guessWebFontMimeType(Asset $asset): string
    {
        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));

        return match ($ext) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            default => 'application/octet-stream',
        };
    }

    private function inlineWebFontBinaryResponse(Asset $asset): \Illuminate\Http\Response
    {
        $tenant = app('tenant');
        if (! $tenant || $asset->tenant_id !== $tenant->id) {
            abort(403);
        }

        try {
            $bytes = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (\Throwable $e) {
            Log::notice('[AssetController] Inline font preview bytes unavailable', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            abort(404, 'Font file not available.');
        }
        if ($bytes === '') {
            abort(404, 'Font file is empty.');
        }

        $mime = trim((string) ($asset->mime_type ?? ''));
        if ($mime === '' || strtolower($mime) === 'application/octet-stream') {
            $mime = $this->guessWebFontMimeType($asset);
        }

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
