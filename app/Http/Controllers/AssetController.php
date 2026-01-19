<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Category;
use App\Services\AssetDeletionService;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class AssetController extends Controller
{
    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService,
        protected AssetDeletionService $deletionService,
        protected MetadataFilterService $metadataFilterService,
        protected MetadataSchemaResolver $metadataSchemaResolver
    ) {
    }

    /**
     * Display a listing of assets.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (!$tenant || !$brand) {
            // Handle case where tenant or brand is not resolved (e.g., no active tenant/brand)
            return Inertia::render('Assets/Index', [
                'categories' => [],
                'categories_by_type' => ['all' => []],
                'selected_category' => null,
                'assets' => [], // Top-level prop must always be present for frontend
            ]);
        }

        // Get only BASIC categories for the brand
        // Use the active() scope to filter out soft-deleted, templates, and deleted system categories
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->active(); // Filter out soft-deleted, templates, and deleted system categories

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
                'sort_order' => $matchingTemplate ? $matchingTemplate->sort_order : 999, // Use template sort_order or high default
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

        // Get asset counts per category (efficient single query)
        $categoryIds = $allCategories->pluck('id')->filter()->toArray();
        $assetCounts = [];
        if (!empty($categoryIds)) {
            // Count assets per category using JSON path (single query with GROUP BY)
            // Use whereRaw for JSON extraction in WHERE clause
            // Note: Only counts assets that have metadata with a valid category_id
            // Assets without metadata or without category_id are excluded (consistent with grid filtering)
            $counts = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::ASSET)
                ->where('status', AssetStatus::VISIBLE)
                ->whereNull('deleted_at')
                ->whereNotNull('metadata')
                ->whereRaw('JSON_EXTRACT(metadata, "$.category_id") IS NOT NULL')
                ->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) IN (' . implode(',', array_map('intval', $categoryIds)) . ')')
                ->selectRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) as category_id, COUNT(*) as count')
                ->groupBy(DB::raw('CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED)'))
                ->get()
                ->pluck('count', 'category_id')
                ->toArray();
            
            // Map counts by category ID
            foreach ($counts as $catId => $count) {
                $assetCounts[$catId] = $count;
            }
        }
        
        // Get total asset count for "All" button
        $totalAssetCount = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNull('deleted_at')
            ->count();
        
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

        // Query visible assets for this brand and asset type
        // AssetStatus represents VISIBILITY only, not processing progress.
        // Processing state is tracked via thumbnail_status, metadata flags, and activity events.

        $assetsQuery = Asset::query()
        ->where('tenant_id', $tenant->id)
        ->where('brand_id', $brand->id)
        ->where('type', AssetType::ASSET)

        // Visibility-only filter - assets with VISIBLE status are shown in grid
        ->where('status', AssetStatus::VISIBLE)

        // Exclude soft-deleted assets only
        ->whereNull('deleted_at');


        // Filter by category if provided (check metadata for category_id)
        if ($categoryId) {
            // Filter assets where metadata->category_id matches the category ID
            // Use direct JSON path comparison for exact integer match
            // Cast categoryId to integer to ensure type matching with JSON integer values
            $assetsQuery->whereNotNull('metadata')
                ->where('metadata->category_id', (int) $categoryId);
        }

        // Phase 2 – Step 8: Apply metadata filters
        $filters = $request->get('filters', []);
        if (!empty($filters) && is_array($filters)) {
            // Resolve metadata schema for filtering
            // Note: asset_type in category is organizational (asset/marketing/ai_generated),
            // but MetadataSchemaResolver expects file type (image/video/document)
            // For now, default to 'image' as most assets are images
            // TODO: Could infer from actual assets in category or add file_type to categories
            $assetType = 'image'; // Default file type for metadata schema resolution

            $schema = $this->metadataSchemaResolver->resolve(
                $tenant->id,
                $brand->id,
                $categoryId,
                $assetType
            );

            // Apply filters
            $this->metadataFilterService->applyFilters($assetsQuery, $filters, $schema);
        }

        $assets = $assetsQuery->get();
        
        // HARD TERMINAL STATE: Check for stuck assets and repair them
        // This prevents infinite processing states by automatically failing
        // assets that have been processing longer than the timeout threshold
        $timeoutGuard = app(\App\Services\ThumbnailTimeoutGuard::class);
        foreach ($assets as $asset) {
            if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::PROCESSING) {
                $timeoutGuard->checkAndRepair($asset);
                // Reload asset to get updated status if it was repaired
                $asset->refresh();
            }
        }
        
        // AUDIT: Log query results and sample asset brand_ids for comparison
        if ($assets->count() > 0) {
            $sampleAssetBrandIds = $assets->take(5)->pluck('brand_id')->unique()->values()->toArray();
            Log::info('[ASSET_QUERY_AUDIT] AssetController::index() query results', [
                'query_tenant_id' => $tenant->id,
                'query_brand_id' => $brand->id,
                'assets_count' => $assets->count(),
                'sample_asset_brand_ids' => $sampleAssetBrandIds,
                'brand_id_mismatch_count' => $assets->filter(fn($a) => $a->brand_id != $brand->id)->count(),
                'note' => 'If brand_id_mismatch_count > 0, query brand_id does not match stored asset brand_id',
            ]);
        } else {
            // Enhanced logging for empty results - check if assets exist that don't match filters
            $totalAssetsInBrand = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereNull('deleted_at')
                ->count();
            
            $visibleAssetsInBrand = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('status', AssetStatus::VISIBLE)
                ->whereNull('deleted_at')
                ->count();
            
            $assetTypeAssets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::ASSET)
                ->where('status', AssetStatus::VISIBLE)
                ->whereNull('deleted_at')
                ->count();
            
            // Get most recent asset for debugging
            $mostRecentAsset = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();
            
            Log::info('[ASSET_QUERY_AUDIT] AssetController::index() query results (empty)', [
                'query_tenant_id' => $tenant->id,
                'query_brand_id' => $brand->id,
                'category_filter' => $categoryId ?? 'none',
                'total_assets_in_brand' => $totalAssetsInBrand,
                'visible_assets_in_brand' => $visibleAssetsInBrand,
                'asset_type_assets' => $assetTypeAssets,
                'most_recent_asset' => $mostRecentAsset ? [
                    'id' => $mostRecentAsset->id,
                    'status' => $mostRecentAsset->status?->value ?? 'null',
                    'type' => $mostRecentAsset->type?->value ?? 'null',
                    'category_id' => $mostRecentAsset->metadata['category_id'] ?? 'null',
                    'created_at' => $mostRecentAsset->created_at?->toIso8601String(),
                ] : 'none',
                'note' => 'No assets found - check status, type, brand_id, tenant_id, and category filter. If total_assets_in_brand > 0 but visible_assets_in_brand = 0, assets may have wrong status.',
            ]);
        }
        
        $assets = $assets->map(function ($asset) use ($tenant, $brand) {
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
                    $category = Category::where('id', $categoryId)
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
                            'name' => $user->name,
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
                    // Verify thumbnail path exists in metadata before generating URL
                    // This prevents showing broken thumbnail URLs
                    $thumbnailPath = $asset->thumbnailPathForStyle('thumb');
                    
                    if ($thumbnailPath) {
                        $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                        
                        $finalThumbnailUrl = route('assets.thumbnail.final', [
                            'asset' => $asset->id,
                            'style' => 'thumb',
                        ]);
                        
                        // Add version query param if available (ensures browser refetches when version changes)
                        if ($thumbnailVersion) {
                            $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                        }
                    } else {
                        // Thumbnail status is completed but path is missing - log for debugging
                        \Illuminate\Support\Facades\Log::warning('Asset marked as completed but thumbnail path missing', [
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
                    'category' => $categoryName ? [
                        'id' => $categoryId,
                        'name' => $categoryName,
                    ] : null,
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
                    'preview_url' => null, // Reserved for future full-size preview endpoint
                    'url' => null, // Reserved for future download endpoint
                ];
            })
            ->values();

        // Phase 2 – Step 8: Get filterable schema for frontend
        // Note: asset_type in category is organizational (asset/marketing/ai_generated),
        // but MetadataSchemaResolver expects file type (image/video/document)
        // Default to 'image' for schema resolution when category context doesn't provide file type
        // TODO: Could infer from actual assets in category or add file_type to categories
        $filterableSchema = [];
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
            $filterableSchema = $this->metadataFilterService->getFilterableFields($schema);
        }

        // Phase 2 – Step 8: Get saved views
        $savedViews = [];
        if ($user) {
            $viewsQuery = DB::table('saved_views')
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) use ($user) {
                    $q->where('is_global', true)
                        ->orWhere('user_id', $user->id);
                });

            if ($categoryId) {
                $viewsQuery->where(function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId)
                        ->orWhereNull('category_id');
                });
            }

            $savedViews = $viewsQuery->orderBy('name')
                ->get()
                ->map(function ($view) {
                    return [
                        'id' => $view->id,
                        'name' => $view->name,
                        'filters' => json_decode($view->filters, true),
                        'category_id' => $view->category_id,
                        'is_global' => (bool) $view->is_global,
                    ];
                })
                ->toArray();
        }

        return Inertia::render('Assets/Index', [
            'categories' => $allCategories,
            'categories_by_type' => [
                'all' => $allCategories,
            ],
            'selected_category' => $categoryId ? (int)$categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'total_asset_count' => $totalAssetCount, // Total count for "All" button
            'filterable_schema' => $filterableSchema, // Phase 2 – Step 8: Filterable metadata fields
            'saved_views' => $savedViews, // Phase 2 – Step 8: Saved filter views
            'assets' => $assets, // Top-level prop for frontend AssetGrid component
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
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ];

        // Support MinIO for local development
        if (env('AWS_ENDPOINT')) {
            $config['endpoint'] = env('AWS_ENDPOINT');
            $config['use_path_style_endpoint'] = env('AWS_USE_PATH_STYLE_ENDPOINT', true);
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
     * Download an asset.
     *
     * GET /assets/{asset}/download
     *
     * Phase 3.1 Step 4: Single file download endpoint (drawer).
     * 
     * Generates a signed S3 URL for the asset and tracks the download metric.
     * No Download Group created - direct file download.
     * 
     * Responsibilities:
     * - Validate asset access
     * - Ensure asset is not archived (Phase 2.8: future-proof check)
     * - Generate signed S3 URL
     * - Redirect to S3
     * - Emit placeholder hooks for analytics later
     *
     * @param Asset $asset
     * @return \Illuminate\Http\RedirectResponse|JsonResponse
     */
    public function download(Asset $asset): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        $this->authorize('view', $asset);

        // Phase 2.8: Future-proof check for archived assets
        // TODO: When Phase 2.8 is implemented, add check:
        // if ($asset->isArchived()) {
        //     return response()->json([
        //         'message' => 'Archived assets cannot be downloaded',
        //     ], 403);
        // }

        try {
            // Generate signed S3 URL
            // Asset path is stored in storage_root_path field
            if (!$asset->storage_root_path) {
                return response()->json([
                    'message' => 'Asset storage path not found',
                ], 404);
            }

            // Phase 3.1 Step 6 Fix: Force download behavior via ResponseContentDisposition
            // Use S3Client directly to add Content-Disposition header to presigned URL
            $s3Client = $this->createS3ClientForVerification();
            $bucket = $asset->storageBucket;
            
            // Get filename for Content-Disposition
            $filename = $asset->original_filename ?? basename($asset->storage_root_path);
            // Ensure filename is properly encoded for Content-Disposition header
            $filenameEncoded = rawurlencode($filename);
            
            // Generate presigned URL with ResponseContentDisposition to force download
            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket->name,
                'Key' => $asset->storage_root_path,
                'ResponseContentDisposition' => "attachment; filename=\"{$filename}\"; filename*=UTF-8''{$filenameEncoded}",
            ]);
            
            $presignedRequest = $s3Client->createPresignedRequest(
                $command,
                now()->addMinutes(15) // URL valid for 15 minutes
            );
            
            $signedUrl = (string) $presignedRequest->getUri();

            // Track download metric
            $metricsService = app(\App\Services\AssetMetricsService::class);
            $user = auth()->user();
            $metricsService->recordMetric(
                asset: $asset,
                type: \App\Enums\MetricType::DOWNLOAD,
                viewType: null,
                metadata: null,
                user: $user
            );

            // Phase 3.1 Step 5: Emit asset download requested event
            \App\Services\DownloadEventEmitter::emitAssetDownloadRequested($asset);

            // Redirect to signed URL
            return redirect($signedUrl);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to generate download URL', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate download URL',
            ], 500);
        }
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

        try {
            // TODO: Generate signed URL from storage (S3 or local)
            // For now, return null to indicate placeholder
            // In production, this should use:
            // - Storage::disk('s3')->temporaryUrl($asset->storage_root_path, now()->addMinutes(15))
            // - Or similar signed URL generation
            
            $previewUrl = null;
            
            // If asset has storage_root_path and storage bucket, generate signed URL
            if ($asset->storage_root_path && $asset->storageBucket) {
                // Placeholder for signed URL generation
                // $previewUrl = Storage::disk('s3')->temporaryUrl($asset->storage_root_path, now()->addMinutes(15));
            }

            return response()->json([
                'url' => $previewUrl,
                'expires_at' => $previewUrl ? now()->addMinutes(15)->toIso8601String() : null,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate preview URL: ' . $e->getMessage(),
            ], 500);
        }
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
}
