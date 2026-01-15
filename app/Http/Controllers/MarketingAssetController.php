<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Category;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MarketingAssetController extends Controller
{
    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService
    ) {
    }

    /**
     * Display a listing of marketing assets.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (!$tenant || !$brand) {
            return Inertia::render('MarketingAssets/Index', [
                'categories' => [],
                'selected_category' => null,
                'assets' => [], // Top-level prop must always be present for frontend
            ]);
        }

        // Get only marketing categories for the brand
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::MARKETING);

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

        // Get marketing system category templates
        $systemTemplates = $this->systemCategoryService->getTemplatesByAssetType(AssetType::MARKETING)
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
                'sort_order' => $matchingTemplate ? $matchingTemplate->sort_order : 999, // Use template sort_order or high default
                'access_rules' => $accessRules,
            ]);
        }

        // Add system templates that don't have matching brand categories
        foreach ($systemTemplates as $template) {
            $exists = $categories->contains(function ($category) use ($template) {
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

        // Query marketing assets - show all visible assets regardless of processing state
        // This matches the behavior of the regular Assets page
        // Assets are visible immediately after upload, processing happens in background
        // Note: assets must be top-level prop for Inertia to pass to frontend component
        $assetsQuery = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::MARKETING)
            ->where('status', AssetStatus::VISIBLE) // Only visible assets
            ->whereNull('deleted_at'); // Exclude soft-deleted assets
        
        // HARD TERMINAL STATE: Check for stuck assets and repair them
        // This prevents infinite processing states by automatically failing
        // assets that have been processing longer than the timeout threshold
        $timeoutGuard = app(\App\Services\ThumbnailTimeoutGuard::class);

        // Filter by category if provided (check metadata for category_id)
        if ($categoryId) {
            // Filter assets where metadata->category_id matches the category ID
            // Use direct JSON path comparison for exact integer match
            // Cast categoryId to integer to ensure type matching with JSON integer values
            $assetsQuery->whereNotNull('metadata')
                ->where('metadata->category_id', (int) $categoryId);
        }

        $assets = $assetsQuery->get();
        
        // HARD TERMINAL STATE: Check for stuck assets and repair them
        // This prevents infinite processing states by automatically failing
        // assets that have been processing longer than the timeout threshold
        foreach ($assets as $asset) {
            if ($asset->thumbnail_status === \App\Enums\ThumbnailStatus::PROCESSING) {
                $timeoutGuard->checkAndRepair($asset);
                // Reload asset to get updated status if it was repaired
                $asset->refresh();
            }
        }
        
        // Enhanced logging for debugging missing assets
        if ($assets->count() === 0) {
            $totalMarketingAssets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::MARKETING)
                ->whereNull('deleted_at')
                ->count();
            
            $visibleMarketingAssets = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::MARKETING)
                ->where('status', AssetStatus::VISIBLE)
                ->whereNull('deleted_at')
                ->count();
            
            $mostRecentMarketingAsset = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::MARKETING)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();
            
            \Illuminate\Support\Facades\Log::info('[MARKETING_ASSET_QUERY_AUDIT] MarketingAssetController::index() query results (empty)', [
                'query_tenant_id' => $tenant->id,
                'query_brand_id' => $brand->id,
                'category_filter' => $categoryId ?? 'none',
                'total_marketing_assets' => $totalMarketingAssets,
                'visible_marketing_assets' => $visibleMarketingAssets,
                'most_recent_asset' => $mostRecentMarketingAsset ? [
                    'id' => $mostRecentMarketingAsset->id,
                    'status' => $mostRecentMarketingAsset->status?->value ?? 'null',
                    'type' => $mostRecentMarketingAsset->type?->value ?? 'null',
                    'thumbnail_status' => $mostRecentMarketingAsset->thumbnail_status?->value ?? 'null',
                    'category_id' => $mostRecentMarketingAsset->metadata['category_id'] ?? 'null',
                    'created_at' => $mostRecentMarketingAsset->created_at?->toIso8601String(),
                ] : 'none',
                'note' => 'No marketing assets found - check status, type, brand_id, tenant_id, and category filter',
            ]);
        }
        
        $assets = $assets
            ->map(function ($asset) use ($tenant, $brand) {
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
                        \Illuminate\Support\Facades\Log::warning('Marketing asset marked as completed but thumbnail path missing', [
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
                ];
            })
            ->values();

        return Inertia::render('MarketingAssets/Index', [
            'categories' => $allCategories,
            'selected_category' => $categoryId ? (int)$categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'assets' => $assets, // Top-level prop for frontend AssetGrid component
        ]);
    }
}
