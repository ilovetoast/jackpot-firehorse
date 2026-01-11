<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Category;
use App\Services\AssetDeletionService;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService,
        protected AssetDeletionService $deletionService
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
        // Filter out hidden categories unless user has permission
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET);

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

        // Query completed assets for this brand and asset type
        // Note: assets must be top-level prop for Inertia to pass to frontend component
        $assetsQuery = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::COMPLETED)
            ->whereNull('deleted_at'); // Exclude soft-deleted assets

        // Filter by category if provided (check metadata for category_id)
        if ($categoryId) {
            // Filter assets where metadata contains category_id
            // Note: Handles null metadata gracefully - assets without category in metadata will be excluded
            $assetsQuery->whereNotNull('metadata')
                ->whereJsonContains('metadata->category_id', $categoryId);
        }

        $assets = $assetsQuery->get()
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

                // Generate thumbnail URL using secure backend endpoint
                // Pattern: /app/assets/{asset_id}/thumbnail/{style}
                // The endpoint handles missing thumbnails gracefully by returning placeholders
                // For grid view, we provide 'thumb' style (smaller, faster loading)
                // The frontend can request 'medium' or 'large' for detail views if needed
                $thumbnailUrl = route('assets.thumbnail', [
                    'asset' => $asset->id,
                    'style' => 'thumb', // Use thumb style for grid cards (320px)
                ]);

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
                    // Thumbnail URL using secure backend endpoint (returns placeholder if not yet generated)
                    'thumbnail_url' => $thumbnailUrl,
                    'preview_url' => null, // Reserved for future full-size preview endpoint
                    'url' => null, // Reserved for future download endpoint
                ];
            })
            ->values();

        return Inertia::render('Assets/Index', [
            'categories' => $allCategories,
            'categories_by_type' => [
                'all' => $allCategories,
            ],
            'selected_category' => $categoryId ? (int)$categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'assets' => $assets, // Top-level prop for frontend AssetGrid component
        ]);
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

        // Verify asset is completed
        if ($asset->status !== AssetStatus::COMPLETED) {
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
