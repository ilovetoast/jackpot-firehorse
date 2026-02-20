<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Category;
use App\Services\AiMetadataConfidenceService;
use App\Services\Lifecycle\LifecycleResolver;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DeliverableController extends Controller
{
    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService,
        protected MetadataFilterService $metadataFilterService,
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected AiMetadataConfidenceService $confidenceService,
        protected LifecycleResolver $lifecycleResolver,
        protected AssetSearchService $assetSearchService,
        protected AssetSortService $assetSortService
    ) {
    }

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

        if (!$tenant || !$brand) {
            return Inertia::render('Deliverables/Index', [
                'categories' => [],
                'total_asset_count' => 0,
                'selected_category' => null,
                'assets' => [],
                'next_page_url' => null,
                'sort' => AssetSortService::DEFAULT_SORT,
                'sort_direction' => AssetSortService::DEFAULT_DIRECTION,
                'compliance_filter' => '',
                'show_compliance_filter' => false,
                'q' => '',
            ]);
        }

        // Get only deliverable categories for the brand
        // IMPORTANT: Always get ALL categories (including hidden) to check for existence
        // We'll filter hidden categories later when building the response, but we need
        // to know if a category exists (even if hidden) to avoid adding templates
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::DELIVERABLE)
            ->ordered();

        // Don't filter hidden categories here - we need them to check template existence
        // Hidden categories will be filtered in the response building below
        $allCategoriesIncludingHidden = $query->get();
        
        // Filter out hidden categories for users without 'manage categories' permission
        // This is for the final response, but we keep allCategoriesIncludingHidden for template checking
        if (! $user || ! $user->can('manage categories')) {
            $categories = $allCategoriesIncludingHidden->filter(fn($cat) => !$cat->is_hidden)->values();
        } else {
            $categories = $allCategoriesIncludingHidden;
        }

        // Filter out private categories that the user doesn't have access to
        $categories = $categories->filter(function ($category) use ($user) {
            return $user ? Gate::forUser($user)->allows('view', $category) : false;
        });

        // Security: only assets in categories the user can view (locked/private folder visibility)
        $viewableCategoryIds = $categories->pluck('id')->filter()->values()->toArray();

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
            if ($category->is_private && !$category->is_system) {
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
            $countQuery = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::DELIVERABLE)
                ->whereNull('deleted_at')
                ->whereNotNull('metadata')
                ->whereIn(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED)'), array_map('intval', $viewableCategoryIds));
            $this->lifecycleResolver->apply(
                $countQuery,
                $request->get('lifecycle'),
                $user,
                $tenant,
                $brand
            );
            $totalDeliverableCount = (clone $countQuery)->count();
            if (! empty($categoryIds)) {
                $counts = (clone $countQuery)
                    ->selectRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) as category_id, COUNT(*) as count')
                    ->groupBy(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED)'))
                    ->get()
                    ->pluck('count', 'category_id')
                    ->toArray();
                foreach ($counts as $catId => $count) {
                    $assetCounts[$catId] = $count;
                }
            }
        }
        $allCategories = $allCategories->map(function ($category) use ($assetCounts) {
            $category['asset_count'] = isset($category['id']) && isset($assetCounts[$category['id']])
                ? $assetCounts[$category['id']]
                : 0;
            return $category;
        });

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
                ->first();
            
            if ($category) {
                $categoryId = $category->id;
            }
        }

        // Query deliverables - match Assets page behavior with lifecycle filters
        // Use qualified column names to avoid ambiguity when compliance join is added
        $assetsQuery = Asset::where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('assets.type', AssetType::DELIVERABLE)
            ->whereNull('assets.deleted_at');

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
        $lifecycleFilter = $request->get('lifecycle');
        $this->lifecycleResolver->apply(
            $assetsQuery,
            $lifecycleFilter,
            $user,
            $tenant,
            $brand
        );

        // Phase M: Base query for "has values" check (tenant, brand, category, lifecycle only; filters/search applied below)
        $baseQueryForFilterVisibility = (clone $assetsQuery);

        // Compliance filter (Brand DNA alignment)
        $complianceFilter = $request->input('compliance_filter');
        if (in_array($complianceFilter, ['superb', 'strong', 'needs_review', 'failing', 'unscored'], true)) {
            if ($complianceFilter === 'superb') {
                $assetsQuery->withCompliance()->where('brand_compliance_scores.overall_score', '>=', 90);
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
        $paginator = $assetsQuery->paginate($perPage);
        $assetModels = $paginator->getCollection();
        $t1 = microtime(true);

        $nextPageUrl = null;
        if ($paginator->hasMorePages()) {
            $query = array_merge($request->query(), ['page' => $paginator->currentPage() + 1]);
            $nextPageUrl = $request->url() . '?' . http_build_query($query);
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

        // STARRED CANONICAL: Same as AssetController — assets.metadata.starred (boolean) only.
        $mappedAssets = $assetModels
            ->map(function ($asset) use ($tenant, $brand, $incidentSeverityByAsset) {
                // Derive file extension from original_filename, with mime_type fallback
                $fileExtension = null;
                if ($asset->original_filename && $asset->original_filename !== 'unknown') {
                    $ext = pathinfo($asset->original_filename, PATHINFO_EXTENSION);
                    // Normalize extension (lowercase, remove leading dot if any)
                    if ($ext && !empty(trim($ext))) {
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

                // Get category name if category_id exists in metadata
                $categoryName = null;
                $categoryId = null;
                if ($asset->metadata && isset($asset->metadata['category_id'])) {
                    $categoryId = $asset->metadata['category_id'];
                    $category = \App\Models\Category::where('id', $categoryId)
                        ->where('tenant_id', $tenant->id)
                        ->where('brand_id', $brand->id)
                        ->first();
                    if ($category) {
                        $categoryName = $category->name;
                    }
                }

                // Get user who uploaded the asset
                $uploadedBy = null;
                if ($asset->user_id) {
                    $user = \App\Models\User::find($asset->user_id);
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

                // Step 6: Generate distinct thumbnail URLs for preview and final
                // CRITICAL: Preview and final URLs must NEVER be the same to prevent cache confusion
                // Preview: /app/assets/{asset_id}/thumbnail/preview/preview (LQIP, real derivative)
                // Final: /app/assets/{asset_id}/thumbnail/final/{style}?v={version} (permanent, full-quality)
                
                $metadata = $asset->metadata ?? [];
                $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                    ? $asset->thumbnail_status->value 
                    : ($asset->thumbnail_status ?? 'pending');
                
                // Step 6: Preview thumbnail URL only if preview exists in metadata
                // Preview thumbnails are generated early and stored separately
                $previewThumbnailUrl = null;
                $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
                if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                    // Preview exists - construct URL to preview endpoint
                    $previewThumbnailUrl = route('assets.thumbnail.preview', [
                        'asset' => $asset->id,
                        'style' => 'preview', // Preview endpoint serves 'preview' style
                    ]);
                }
                
                $finalThumbnailUrl = null;
                $thumbnailVersion = null;
                
                // Final thumbnail URL only provided when thumbnail_status === COMPLETED
                // AND thumbnail path exists in metadata (defensive check)
                // Includes version query param (thumbnails_generated_at) for cache busting
                if ($thumbnailStatus === 'completed') {
                    // Prefer medium for grid; fallback to thumb if medium missing
                    $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
                    $thumbnailPath = $asset->thumbnailPathForStyle($thumbnailStyle);
                    if ($thumbnailPath) {
                        $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                        $finalThumbnailUrl = route('assets.thumbnail.final', [
                            'asset' => $asset->id,
                            'style' => $thumbnailStyle,
                        ]);
                        if ($thumbnailVersion) {
                            $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                        }
                    } else {
                        // Thumbnail status is completed but path is missing - log for debugging
                        \Illuminate\Support\Facades\Log::warning('Deliverable marked as completed but thumbnail path missing', [
                            'asset_id' => $asset->id,
                            'thumbnail_status' => $thumbnailStatus,
                            'metadata_thumbnails' => isset($metadata['thumbnails']) ? array_keys($metadata['thumbnails'] ?? []) : 'not set',
                        ]);
                    }
                }

                return [
                    'id' => $asset->id,
                    'title' => $title,
                    'original_filename' => $asset->original_filename,
                    'mime_type' => $asset->mime_type,
                    'file_extension' => $fileExtension,
                    'status' => $asset->status instanceof \App\Enums\AssetStatus ? $asset->status->value : (string)$asset->status, // AssetStatus enum value
                    'size_bytes' => $asset->size_bytes,
                    'created_at' => $asset->created_at?->toIso8601String(),
                    'metadata' => $asset->metadata, // Full metadata object (includes category_id and fields)
                    'starred' => $this->assetIsStarred($metadata['starred'] ?? null), // boolean; source: assets.metadata.starred only
                    'category' => $categoryName ? [
                        'id' => $categoryId,
                        'name' => $categoryName,
                    ] : null,
                    'user_id' => $asset->user_id, // For delete-own permission check
                    'uploaded_by' => $uploadedBy, // User who uploaded the asset
                    // Phase L.4: Lifecycle fields (read-only display)
                    'published_at' => $asset->published_at?->toIso8601String(),
                    'is_published' => $asset->published_at !== null, // Canonical boolean for publication state
                    'published_by' => $asset->published_by_id ? [
                        'id' => $asset->publishedBy?->id ?? null,
                        'name' => $asset->publishedBy?->name ?? null,
                        'email' => $asset->publishedBy?->email ?? null,
                    ] : null,
                    'archived_at' => $asset->archived_at?->toIso8601String(),
                    // Thumbnail URLs - distinct paths prevent cache confusion
                    'preview_thumbnail_url' => $previewThumbnailUrl, // Preview thumbnail (available even when pending/processing)
                    'final_thumbnail_url' => $finalThumbnailUrl, // Only set if file exists and is valid
                    'thumbnail_version' => $thumbnailVersion, // Version timestamp for cache busting
                    // Legacy thumbnail_url for backward compatibility (points to final if available, otherwise null)
                    'thumbnail_url' => $finalThumbnailUrl ?? null,
                    'thumbnail_status' => $thumbnailStatus, // Thumbnail generation status (pending, processing, completed, failed, skipped)
                    'thumbnail_error' => $asset->thumbnail_error, // Error message if thumbnail generation failed or skipped
                    'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null, // Skip reason for skipped assets
                    'preview_url' => null, // Reserved for future full-size preview endpoint
                    'url' => null, // Reserved for future download endpoint
                    'analysis_status' => $asset->analysis_status ?? 'uploading',
                    'health_status' => $asset->computeHealthStatus($incidentSeverityByAsset[$asset->id] ?? null),
                ];
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
                'note' => 'Deliverables do not include video_preview_url',
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

        // Phase M: Hide filters with zero values in scoped dataset (before pagination)
        if (! empty($filterableSchema)) {
            $keysWithValues = $this->metadataFilterService->getFieldKeysWithValuesInScope($baseQueryForFilterVisibility, $filterableSchema);
            $filterableSchema = array_values(array_filter($filterableSchema, function ($field) use ($keysWithValues) {
                $key = $field['field_key'] ?? $field['key'] ?? null;
                return $key && in_array($key, $keysWithValues, true);
            }));
        }

        // available_values is required by Phase H filter visibility rules
        // Do not remove without updating Phase H contract
        // Compute distinct metadata values for the current asset grid result set
        $availableValues = [];
        $hueGroupToDisplayHex = []; // cluster key -> display_hex from HueClusterService
        
        // When page > 1, use first page's asset IDs for filter options so dropdowns stay consistent
        $assetIdsForAvailableValues = $assetModels->pluck('id')->toArray();
        if (!empty($filterableSchema) && count($assetIdsForAvailableValues) > 0) {
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
            
            if (!empty($filterableFieldKeys)) {
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
                    ->where(function($query) use ($automaticFieldIds) {
                        // Automatic fields: include if value exists (no approval required)
                        if (!empty($automaticFieldIds)) {
                            $query->whereIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                                  ->orWhere(function($q) use ($automaticFieldIds) {
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
                        if (!isset($availableValues[$fieldKey])) {
                            $availableValues[$fieldKey] = [];
                        }
                        
                        // Handle arrays (multiselect fields) and scalar values
                        if (is_array($value)) {
                            foreach ($value as $item) {
                                if ($item !== null && !in_array($item, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $item;
                                }
                            }
                        } else {
                            if (!in_array($value, $availableValues[$fieldKey], true)) {
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
                    if (!empty($hueGroupValues)) {
                        $availableValues['dominant_hue_group'] = array_values(array_unique(array_merge(
                            $availableValues['dominant_hue_group'] ?? [],
                            $hueGroupValues
                        )));
                    }
                }

                // Source 2: Query metadata JSON column (legacy/fallback)
                // Extract values from metadata->fields structure for assets not in asset_metadata
                $assetsWithMetadata = $assets->filter(function ($asset) {
                    return !empty($asset->metadata) && isset($asset->metadata['fields']);
                });
                
                foreach ($assetsWithMetadata as $asset) {
                    $fields = $asset->metadata['fields'] ?? [];
                    foreach ($fields as $fieldKey => $value) {
                        // Only include if field is filterable
                        if (isset($filterableFieldKeys[$fieldKey]) && $value !== null) {
                            // Initialize array if field doesn't exist yet
                            if (!isset($availableValues[$fieldKey])) {
                                $availableValues[$fieldKey] = [];
                            }
                            
                            // Handle arrays (multiselect fields) and scalar values
                            // Deduplicate values (values from asset_metadata are authoritative)
                            if (is_array($value)) {
                                foreach ($value as $item) {
                                    if ($item !== null && !in_array($item, $availableValues[$fieldKey], true)) {
                                        $availableValues[$fieldKey][] = $item;
                                    }
                                }
                            } else {
                                if (!in_array($value, $availableValues[$fieldKey], true)) {
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
                    if (!empty($collectionIds)) {
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
                    if (!empty($tagValues)) {
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
                    if (!$fieldKey || !$isPrimary || !isset($filterableFieldKeys[$fieldKey])) {
                        continue;
                    }
                    $optionValues = [];
                    $options = $field['options'] ?? [];
                    if (!empty($options)) {
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
                    if (!empty($optionValues)) {
                        $availableValues[$fieldKey] = array_values(array_unique(array_merge(
                            $availableValues[$fieldKey] ?? [],
                            $optionValues
                        )));
                        sort($availableValues[$fieldKey]);
                    }
                }
                
                // Remove empty arrays (filters with no values should not appear)
                $availableValues = array_filter($availableValues, function ($values) {
                    return !empty($values);
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
                        'tooltip' => $label . "\nTypical ΔE threshold: " . $threshold,
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

        return Inertia::render('Deliverables/Index', [
            'categories' => $allCategories,
            'total_asset_count' => $totalDeliverableCount, // Total count for "All" and sidebar parity with Assets
            'selected_category' => $categoryId ? (int)$categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'assets' => $mappedAssets,
            'next_page_url' => $nextPageUrl,
            'filterable_schema' => $filterableSchema, // Phase 2 – Step 8: Filterable metadata fields
            'available_values' => $availableValues, // available_values is required by Phase H filter visibility rules
            'sort' => $sort,
            'sort_direction' => $sortDirection,
            'compliance_filter' => $request->input('compliance_filter', ''),
            'show_compliance_filter' => $showComplianceFilter,
            'q' => $request->input('q', ''),
        ]);
    }
}
