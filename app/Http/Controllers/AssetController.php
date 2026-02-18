<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Category;
use App\Models\User;
use App\Services\AiMetadataConfidenceService;
use App\Services\AssetArchiveService;
use App\Services\AssetDeletionService;
use App\Services\AssetPublicationService;
use App\Services\Lifecycle\LifecycleResolver;
use App\Services\Metadata\MetadataValueNormalizer;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class AssetController extends Controller
{
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
        protected AssetSortService $assetSortService
    ) {
    }

    /**
     * Display a listing of assets.
     * Returns JsonResponse for load_more (page 2+) so the client can append without Inertia; otherwise Inertia Response.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (!$tenant || !$brand) {
            // Handle case where tenant or brand is not resolved (e.g., no active tenant/brand)
            if ($request->get('format') === 'json') {
                return response()->json(['assets' => [], 'categories' => [], 'categories_by_type' => ['all' => []]]);
            }
            return Inertia::render('Assets/Index', [
                'categories' => [],
                'categories_by_type' => ['all' => []],
                'selected_category' => null,
                'assets' => [],
                'sort' => AssetSortService::DEFAULT_SORT,
                'sort_direction' => AssetSortService::DEFAULT_DIRECTION,
                'q' => '',
            ]);
        }

        // Get only BASIC categories for the brand
        // Use the active() scope to filter out soft-deleted, templates, and deleted system categories
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->active()
            ->ordered();

        // If user does not have 'manage categories' permission, filter out hidden categories
        if (! $user || ! $user->can('manage categories')) {
            $query->visible();
        }

        $categories = $query->get();

        // Filter out private categories that the user doesn't have access to
        // Use CategoryPolicy to check access for each category
        $categories = $categories->filter(function ($category) use ($user) {
            // Use the policy to check if user can view this category
            return $user ? Gate::forUser($user)->allows('view', $category) : false;
        });

        // Get only BASIC system category templates
        $systemTemplates = $this->systemCategoryService->getTemplatesByAssetType(AssetType::ASSET)
            ->filter(fn ($template) => ! $template->is_hidden || ($user && $user->can('manage categories')));

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
            
            // Check if system category template still exists (for deleted system categories)
            // Use the model's isActive() method for consistency
            $templateExists = $category->is_system ? $category->systemTemplateExists() : true;
            $deletionAvailable = $category->is_system ? !$templateExists : false;
            
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
        if (!empty($viewableCategoryIds)) {
            $countQuery = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::ASSET)
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
            $totalAssetCount = (clone $countQuery)->count();
            if (!empty($categoryIds)) {
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
        // Add counts to categories
        $allCategories = $allCategories->map(function ($category) use ($assetCounts) {
            $category['asset_count'] = isset($category['id']) && isset($assetCounts[$category['id']])
                ? $assetCounts[$category['id']]
                : 0;
            return $category;
        });

        // Check if plan is not free (to show "All" button)
        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $showAllButton = $currentPlan !== 'free';

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

        // Security: only assets in categories the user can view (private/locked folder visibility)
        $viewableCategoryIds = $categories->pluck('id')->filter()->values()->toArray();

        // Query assets for this brand and asset type
        // AssetStatus and published_at filtering is handled by LifecycleResolver (single source of truth).
        // CRITICAL: Do NOT add status filter here - unpublished/archived filters need HIDDEN assets.
        $assetsQuery = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->whereNull('deleted_at');

        // Restrict to viewable categories only (search and grid must not bypass locked/private folders)
        if (empty($viewableCategoryIds)) {
            $assetsQuery->whereRaw('0 = 1');
        } else {
            $assetsQuery->whereNotNull('metadata')
                ->whereIn(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED)'), array_map('intval', $viewableCategoryIds));
        }

        // Filter by category if provided (check metadata for category_id)
        if ($categoryId) {
            $assetsQuery->where('metadata->category_id', (int) $categoryId);
        }

        // Phase L.5.1: Apply lifecycle filtering via LifecycleResolver
        // This is the SINGLE SOURCE OF TRUTH for lifecycle logic
        // CRITICAL: Lifecycle resolver MUST run to ensure consistent asset scope
        // Used by both AssetController and DeliverableController for consistency
        $lifecycleFilter = $request->get('lifecycle');
        $this->lifecycleResolver->apply(
            $assetsQuery,
            $lifecycleFilter,
            $user,
            $tenant,
            $brand
        );

        // Phase M: Base query for "has values" check (tenant, brand, category, lifecycle only; search applied below)
        // Must NOT include request metadata filters so empty filters are hidden by value presence, not by current selection
        $baseQueryForFilterVisibility = (clone $assetsQuery);

        // Apply metadata filters from request (JSON 'filters' or readable flat params)
        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?? [];
        }
        $fileType = 'image';
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
            $reserved = ['category', 'sort', 'sort_direction', 'lifecycle', 'uploaded_by', 'file_type', 'asset', 'edit_metadata', 'page', 'filters', 'q'];
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
        $paginator = $assetsQuery->with(['user', 'publishedBy', 'archivedBy'])->paginate($perPage);
        $assetModels = $paginator->getCollection();

        // Build next_page_url from current request query so category, sort, filters, q, etc. are always preserved
        $nextPageUrl = null;
        if ($paginator->hasMorePages()) {
            $query = array_merge($request->query(), ['page' => $paginator->currentPage() + 1]);
            $nextPageUrl = $request->url() . '?' . http_build_query($query);
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
                    'brand_id_mismatch_count' => $assetModels->filter(fn($a) => $a->brand_id != $brand->id)->count(),
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

        try {
            $mappedAssets = $assetModels->map(function ($asset) use ($tenant, $brand, $starredFromTable, $incidentSeverityByAsset) {
                try {
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
                $categorySlug = null;
                if ($asset->metadata && isset($asset->metadata['category_id'])) {
                    $categoryId = $asset->metadata['category_id'];
                    $category = Category::where('id', $categoryId)
                        ->where('tenant_id', $tenant->id)
                        ->where('brand_id', $brand->id)
                        ->first();
                    if ($category) {
                        $categoryName = $category->name;
                        $categorySlug = $category->slug ?? null;
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
                
                // Final thumbnail URL: provide when status is completed OR thumbnails exist in metadata
                // (Resilient to status sync issues - see THUMBNAIL_STATUS_SYNC_ISSUE.md)
                $thumbnailsExistInMetadata = ! empty($metadata['thumbnails']) && isset($metadata['thumbnails']['thumb']);
                if ($thumbnailStatus === 'completed' || $thumbnailsExistInMetadata) {
                    $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                    $thumbnailStyle = $asset->thumbnailPathForStyle('medium') ? 'medium' : 'thumb';
                    $pathExists = $asset->thumbnailPathForStyle($thumbnailStyle) ?? $asset->thumbnailPathForStyle('thumb');
                    if ($pathExists) {
                        $finalThumbnailUrl = route('assets.thumbnail.final', [
                            'asset' => $asset->id,
                            'style' => $thumbnailStyle,
                        ]);
                        if ($thumbnailVersion) {
                            $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                        }
                        if ($thumbnailStatus !== 'completed' && $thumbnailsExistInMetadata) {
                            Log::info('[AssetController] Providing final_thumbnail_url despite status mismatch', [
                                'asset_id' => $asset->id,
                                'thumbnail_status' => $thumbnailStatus,
                            ]);
                        }
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
                    'starred' => $this->assetIsStarred($metadata['starred'] ?? $starredFromTable[$asset->id] ?? null), // prefer assets.metadata; fallback asset_metadata for display
                    'category' => $categoryName ? [
                        'id' => $categoryId,
                        'name' => $categoryName,
                        'slug' => $categorySlug,
                    ] : null,
                    'user_id' => $asset->user_id, // For delete-own permission check
                    'uploaded_by' => $uploadedBy, // User who uploaded the asset
                    // Thumbnail URLs - distinct paths prevent cache confusion
                    'preview_thumbnail_url' => $previewThumbnailUrl, // Preview thumbnail (temporary, low-quality)
                    'final_thumbnail_url' => $finalThumbnailUrl, // Final thumbnail (permanent, full-quality, only when completed)
                    'thumbnail_version' => $thumbnailVersion, // Version timestamp for cache busting
                    // Legacy thumbnail_url for backward compatibility (points to final if available, otherwise null)
                    'thumbnail_url' => $finalThumbnailUrl ?? null,
                    'thumbnail_status' => $thumbnailStatus, // Thumbnail generation status (pending, processing, completed, failed, skipped)
                    'thumbnail_error' => $asset->thumbnail_error, // Error message if thumbnail generation failed or skipped
                    'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null, // Skip reason for skipped assets
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
                    'preview_url' => null, // Reserved for future full-size preview endpoint
                    'url' => null, // Reserved for future download endpoint
                    // Phase V-1: Video quick preview on hover (grid and drawer)
                    'video_preview_url' => $asset->video_preview_url,
                    // Pipeline status for visible progression (uploading → complete)
                    'analysis_status' => $asset->analysis_status ?? 'uploading',
                    // Asset health badge (healthy|warning|critical) for support visibility
                    'health_status' => $asset->computeHealthStatus($incidentSeverityByAsset[$asset->id] ?? null),
                ];
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
                        'metadata' => $asset->metadata ?? [],
                        'starred' => false,
                        'category' => null,
                        'user_id' => $asset->user_id ?? null,
                        'uploaded_by' => null,
                        'preview_thumbnail_url' => null,
                        'final_thumbnail_url' => null,
                        'thumbnail_url' => null,
                        'thumbnail_status' => 'pending',
                        'thumbnail_error' => null,
                        'published_at' => null,
                        'is_published' => false,
                        'published_by' => null,
                        'archived_at' => null,
                        'archived_by' => null,
                        'video_preview_url' => null,
                        'analysis_status' => 'uploading',
                        'health_status' => 'healthy',
                    ];
                }
            })
            ->values()
            ->all();

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
            // Use 'image' as default file type for metadata schema resolution
            // Category's asset_type is organizational, not a file type
            $fileType = 'image'; // Default file type for metadata schema resolution
            
            $schema = $this->metadataSchemaResolver->resolve(
                $tenant->id,
                $brand->id,
                $categoryId,
                $fileType
            );
            
            // Phase C2/C4: Pass category and tenant models for suppression check (via MetadataVisibilityResolver)
            $filterableSchema = $this->metadataFilterService->getFilterableFields($schema, $category, $tenant);
        } elseif (!$categoryId) {
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
                
                // Safeguard: Always include dominant_hue_group so it's never excluded
                $hueGroupField = \DB::table('metadata_fields')->where('key', 'dominant_hue_group')->first();
                if ($hueGroupField && !in_array($hueGroupField->id, $automaticFieldIds, true)) {
                    $automaticFieldIds[] = (int) $hueGroupField->id;
                }
                
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
                                    if ($normalizedItem !== null && !in_array($normalizedItem, $availableValues[$fieldKey], true)) {
                                        $availableValues[$fieldKey][] = $normalizedItem;
                                    }
                                }
                            } else {
                                // Single value in multiselect field - normalize and add
                                $normalized = MetadataValueNormalizer::normalizeScalar($value);
                                if ($normalized !== null && !in_array($normalized, $availableValues[$fieldKey], true)) {
                                    $availableValues[$fieldKey][] = $normalized;
                                }
                            }
                        } else {
                            // Scalar fields (like dominant_hue_group): normalize to scalar
                            $normalized = MetadataValueNormalizer::normalizeScalar($value);
                            if ($normalized !== null) {
                                if (!in_array($normalized, $availableValues[$fieldKey], true)) {
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
                    if (!empty($hueGroupValues)) {
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
                    return !empty($meta) && isset($meta['fields']);
                });
                
                foreach ($assetsWithMetadata as $item) {
                    $fields = ($item['metadata'] ?? [])['fields'] ?? [];
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
                    if (!empty($collectionIds)) {
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
                    if (!empty($tagValues)) {
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
                        'tooltip' => $label . "\nTypical ΔE threshold: " . $threshold,
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

        return Inertia::render('Assets/Index', [
            'tenant' => $tenant ? ['id' => $tenant->id] : null, // For Tags filter autocomplete
            'categories' => $allCategories,
            'categories_by_type' => [
                'all' => $allCategories,
            ],
            'selected_category' => $categoryId ? (int)$categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'total_asset_count' => $totalAssetCount, // Total count for "All" button
            'assets' => $mappedAssets,
            'next_page_url' => $nextPageUrl,
            'filterable_schema' => $filterableSchema, // Phase 2 – Step 8: Filterable metadata fields
            'available_values' => $availableValues, // available_values is required by Phase H filter visibility rules
            'filters' => $filters, // Server-parsed filters for active filter UI sync with URL
            'sort' => $sort,
            'sort_direction' => $sortDirection,
            'q' => $request->input('q', ''),
        ]);
    }

    /**
     * GET /app/assets/processing
     * 
     * Returns all assets currently processing (backend-driven truth).
     * This is the authoritative source for processing indicators.
     * 
     * CRITICAL RULES:
     * - Only returns assets with terminal states excluded (pending, processing, or null)
     * - Includes TTL check to detect stale jobs (>10 minutes)
     * - Terminal states (completed, failed, skipped) are never returned
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function activeProcessingJobs(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        
        if (!$tenant || !$brand || !$user) {
            return response()->json([
                'active_jobs' => [],
                'stale_count' => 0,
            ]);
        }
        
        $now = now();
        $staleThreshold = 10; // 10 minutes
        $staleCount = 0;
        
        try {
            // CRITICAL: Only include assets that are actively processing
            // Terminal states (failed, skipped, completed) are automatically excluded
            $processingAssets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where(function ($query) {
                    // Only include actively processing states: pending, processing, or null (legacy)
                    $query->where('thumbnail_status', \App\Enums\ThumbnailStatus::PENDING->value)
                          ->orWhere('thumbnail_status', \App\Enums\ThumbnailStatus::PROCESSING->value)
                          ->orWhereNull('thumbnail_status'); // Legacy assets (null = pending)
                })
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->limit(100) // Reasonable limit
                ->get(['id', 'title', 'original_filename', 'thumbnail_status', 'thumbnail_error', 'status', 'created_at'])
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
                        'title' => $asset->title ?? $asset->original_filename ?? 'Untitled Asset',
                        'thumbnail_status' => $asset->thumbnail_status?->value ?? 'pending',
                        'thumbnail_error' => $asset->thumbnail_error,
                        'status' => $asset->status?->value ?? 'pending',
                        'created_at' => $asset->created_at->toIso8601String(),
                        'age_minutes' => $ageMinutes,
                        'is_stale' => $isStale,
                    ];
                })
                ->filter(function ($asset) {
                    // Filter out stale jobs from active list (but count them)
                    return !$asset['is_stale'];
                })
                ->values()
                ->toArray();
            
            Log::info('[AssetProcessingTray] Active processing jobs fetched', [
                'active_count' => count($processingAssets),
                'stale_count' => $staleCount,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
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
            
            return response()->json([
                'active_jobs' => [],
                'stale_count' => 0,
                'error' => 'Failed to fetch processing jobs',
            ], 500);
        }
    }

    /**
     * GET /app/assets/thumbnail-status/batch
     * 
     * Batch endpoint for checking thumbnail status of multiple assets.
     * Used by smart polling to efficiently check which assets have final thumbnails ready.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function batchThumbnailStatus(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();
        
        if (!$tenant || !$brand || !$user) {
            return response()->json([
                'assets' => [],
            ]);
        }
        
        // Get asset IDs from request (comma-separated or array)
        $assetIds = $request->input('asset_ids', []);
        
        if (is_string($assetIds)) {
            $assetIds = explode(',', $assetIds);
        }
        
        if (!is_array($assetIds) || empty($assetIds)) {
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
        
        try {
            $assets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereIn('id', $assetIds)
                ->whereNull('deleted_at')
                ->with('storageBucket')
                ->get(['id', 'thumbnail_status', 'thumbnail_error', 'metadata', 'storage_bucket_id'])
                ->map(function ($asset) {
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
                                    // File exists and is valid - return final URL
                                    $finalThumbnailUrl = route('assets.thumbnail.final', [
                                        'asset' => $asset->id,
                                        'style' => 'thumb',
                                    ]);
                                    
                                    if ($thumbnailVersion) {
                                        $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
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
                                'has_path' => !!$thumbnailPath,
                                'has_bucket' => !!$asset->storageBucket,
                            ]);
                        }
                    }
                    
                    // Get skip reason from metadata if status is skipped
                    $skipReason = null;
                    if ($verifiedStatus === 'skipped') {
                        $metadata = $asset->metadata ?? [];
                        $skipReason = $metadata['thumbnail_skip_reason'] ?? 'unsupported_file_type';
                    }
                    
                    // Preview thumbnail URL - returned even when status is pending or processing
                    $previewThumbnailUrl = null;
                    $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
                    if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                        // Preview exists - construct URL to preview endpoint
                        $previewThumbnailUrl = route('assets.thumbnail.preview', [
                            'asset' => $asset->id,
                            'style' => 'preview',
                        ]);
                    }
                    
                    return [
                        'asset_id' => $asset->id,
                        'thumbnail_status' => $verifiedStatus, // Use verified status, not raw status
                        'thumbnail_version' => $thumbnailVersion,
                        'preview_thumbnail_url' => $previewThumbnailUrl, // Preview thumbnail (available even when pending/processing)
                        'final_thumbnail_url' => $finalThumbnailUrl, // Only set if file exists and is valid
                        'thumbnail_error' => $asset->thumbnail_error,
                        'thumbnail_skip_reason' => $skipReason, // Skip reason for skipped assets
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
            ], 500);
        }
    }

    /**
     * Create S3 client instance for file verification.
     *
     * @return \Aws\S3\S3Client
     */
    protected function createS3ClientForVerification(): \Aws\S3\S3Client
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
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
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function processingStatus(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        
        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }
        
        if ($asset->brand_id !== $brand->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Refresh asset to ensure we have the latest thumbnail_status from database
        $asset->refresh();

        // Get thumbnail status from Asset model
        // thumbnail_status is the source of truth for thumbnail generation state
        // Values: 'pending', 'processing', 'completed', 'failed' (ThumbnailStatus enum)
        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
            ? $asset->thumbnail_status->value 
            : ($asset->thumbnail_status ?? 'pending');

        // Generate distinct thumbnail URLs for preview and final
        // Step 6: Preview thumbnail URL only if preview exists in metadata
        $metadata = $asset->metadata ?? [];
        $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
        $previewThumbnailUrl = null;
        if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
            $previewThumbnailUrl = route('assets.thumbnail.preview', [
                'asset' => $asset->id,
                'style' => 'preview', // Preview endpoint serves 'preview' style
            ]);
        }
        
        $finalThumbnailUrl = null;
        $thumbnailVersion = null;
        
        // Final thumbnail URL only provided when thumbnail_status === COMPLETED
        if ($thumbnailStatus === 'completed') {
            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            
            $finalThumbnailUrl = route('assets.thumbnail.final', [
                'asset' => $asset->id,
                'style' => 'thumb',
            ]);
            
            // Add version query param if available
            if ($thumbnailVersion) {
                $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
            }
        }

        return response()->json([
            'thumbnail_status' => $thumbnailStatus,
            'preview_thumbnail_url' => $previewThumbnailUrl,
            'final_thumbnail_url' => $finalThumbnailUrl,
            'thumbnail_version' => $thumbnailVersion,
            'thumbnail_url' => $finalThumbnailUrl ?? null, // Legacy compatibility
            'thumbnails_generated_at' => $thumbnailVersion, // Legacy compatibility
            'thumbnail_skip_reason' => $metadata['thumbnail_skip_reason'] ?? null, // Skip reason for skipped assets
        ], 200);
    }

    /**
     * Get activity events for an asset.
     *
     * GET /assets/{asset}/activity
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function activity(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        
        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

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
    public function view(Request $request, Asset $asset): JsonResponse|Response
    {
        Gate::authorize('view', $asset);

        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return $this->previewUrl($asset);
        }

        $tenant = app('tenant');
        $collectionOnly = app()->bound('collection_only') && app('collection_only');
        $collection = $collectionOnly && app()->bound('collection') ? app('collection') : null;

        // Build thumbnail URLs from metadata (same logic as index) — Asset has no final/preview URL accessors
        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus
            ? $asset->thumbnail_status->value
            : ($asset->thumbnail_status ?? 'pending');
        $previewThumbnailUrl = null;
        $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
        if (! empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
            $previewThumbnailUrl = route('assets.thumbnail.preview', [
                'asset' => $asset->id,
                'style' => 'preview',
            ]);
        }
        $finalThumbnailUrl = null;
        if ($thumbnailStatus === 'completed') {
            $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
            $finalThumbnailUrl = route('assets.thumbnail.final', [
                'asset' => $asset->id,
                'style' => 'thumb',
            ]);
            if ($thumbnailVersion) {
                $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
            }
        }
        $thumbnailUrl = $finalThumbnailUrl ?? $previewThumbnailUrl;

        $asset->load(['collections' => fn ($q) => $q->select('collections.id', 'collections.name')]);
        $payload = [
            'id' => $asset->id,
            'title' => $asset->title,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'thumbnail_url' => $thumbnailUrl,
            'download_url' => route('assets.download', ['asset' => $asset->id]),
            'collection_only' => $collectionOnly,
            'collection' => $collection ? ['id' => $collection->id, 'name' => $collection->name] : null,
        ];

        return Inertia::render('Assets/View', ['asset' => $payload]);
    }

    /**
     * Download an asset. Redirects to a signed storage URL.
     * GET /assets/{asset}/download
     */
    public function download(Asset $asset): RedirectResponse
    {
        Gate::authorize('view', $asset);

        $tenant = app('tenant');
        if ($asset->tenant_id !== $tenant->id) {
            abort(404, 'Asset not found.');
        }

        if (! $asset->storage_root_path) {
            abort(404, 'File not available.');
        }

        $bucketService = app(\App\Services\TenantBucketService::class);
        $bucket = $bucketService->resolveActiveBucketOrFail($asset->tenant);
        $signedUrl = $bucketService->getPresignedGetUrl($bucket, $asset->storage_root_path, 15);

        return redirect($signedUrl);
    }

    /**
     * Get signed preview URL for an asset.
     *
     * GET /assets/{asset}/preview-url
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function previewUrl(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        
        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Verify asset processing is completed (check processing state, not status)
        $completionService = app(\App\Services\AssetCompletionService::class);
        if (!$completionService->isComplete($asset)) {
            return response()->json([
                'message' => 'Asset preview not available - asset is still processing',
            ], 422);
        }

        $previewUrl = null;

        if ($asset->storage_root_path) {
            $bucketService = app(\App\Services\TenantBucketService::class);
            $bucket = $bucketService->resolveActiveBucketOrFail($asset->tenant);
            $previewUrl = $bucketService->getPresignedGetUrl($bucket, $asset->storage_root_path, 15);
        }

        return response()->json([
            'url' => $previewUrl,
            'expires_at' => $previewUrl ? now()->addMinutes(15)->toIso8601String() : null,
        ], 200);
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
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function regenerateSystemMetadata(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'success' => false,
                'error' => 'Asset not found',
            ], 404);
        }

        // Check permission - same as AI metadata regeneration
        if (!$user || !$user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            // Also allow owners/admins
            $tenantRole = $user?->getRoleForTenant($tenant);
            $isOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            if (!$isOwnerOrAdmin) {
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
            \App\Jobs\PopulateAutomaticMetadataJob::dispatchSync($asset->id);

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
                'error' => 'Failed to regenerate system metadata: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Publish an asset.
     *
     * POST /assets/{asset}/publish
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function publish(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
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
     * Unpublish an asset.
     *
     * POST /assets/{asset}/unpublish
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function unpublish(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
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
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function archive(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
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
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function restore(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
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
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
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
     * Delete an asset.
     *
     * DELETE /assets/{asset}
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function destroy(Asset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        $tenant = app('tenant');
        $user = auth()->user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
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
                'message' => 'Failed to delete asset: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted asset (undo delete before grace period expires).
     *
     * POST /assets/{asset}/restore-from-trash
     */
    public function restoreFromTrash(string $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $assetModel = Asset::withTrashed()->findOrFail($asset);

        if ($assetModel->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        if (!$assetModel->trashed()) {
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
                'message' => 'Failed to restore asset: ' . $e->getMessage(),
            ], 500);
        }
    }
}
