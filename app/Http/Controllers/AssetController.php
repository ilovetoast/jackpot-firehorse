<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use App\Services\AiMetadataConfidenceService;
use App\Services\ApprovalAgingService;
use App\Services\AssetArchiveService;
use App\Services\AssetDeletionService;
use App\Services\AssetPublicationService;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use App\Support\Roles\PermissionMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        protected AssetPublicationService $publicationService,
        protected AssetArchiveService $archiveService,
        protected MetadataFilterService $metadataFilterService,
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected AiMetadataConfidenceService $confidenceService
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

        // Phase L.5.1: Permission checks for lifecycle filters
        // - asset.publish: Required for "Pending Approval" filter (approvers can see pending assets)
        // - metadata.bypass_approval: Required for "Unpublished" filter (full viewing privileges)
        // - asset.archive: Required for "Archived" filter (users who can archive assets)
        // Define early so they can be used in category count queries
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

        // IMPORTANT: Always get ALL categories (including hidden) to check for existence
        // We'll filter hidden categories later when building the response, but we need
        // to know if a category exists (even if hidden) to avoid adding templates
        $allCategoriesIncludingHidden = $query->get();
        
        // Filter out hidden categories for users without 'manage categories' permission
        // This is for the final response, but we keep allCategoriesIncludingHidden for template checking
        if (! $user || ! $user->can('manage categories')) {
            $categories = $allCategoriesIncludingHidden->filter(fn($cat) => !$cat->is_hidden)->values();
        } else {
            $categories = $allCategoriesIncludingHidden;
        }

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
        // IMPORTANT: Check against allCategoriesIncludingHidden, not $categories
        // This ensures we don't add a template if the category exists but is hidden
        foreach ($systemTemplates as $template) {
            $exists = $allCategoriesIncludingHidden->contains(function ($category) use ($template) {
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
            // Phase L.5.1: Count logic matches grid visibility (approvers see unpublished, others don't)
            $countQuery = Asset::where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::ASSET);
            
            // Apply same visibility rules as main query
            // CRITICAL: Unpublished assets should NEVER be counted unless filter is explicitly active
            // Even users with permissions should not see unpublished assets in counts by default
            if ($canSeeUnpublished) {
                // Users with permissions can see HIDDEN assets (pending approval) that are published
                // But still exclude unpublished assets unless filter is active
                $countQuery->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN])
                    ->whereNotNull('published_at'); // Always exclude unpublished in default counts
            } else {
                // Regular users only see VISIBLE, published assets
                $countQuery->where('status', AssetStatus::VISIBLE)
                    ->whereNotNull('published_at'); // Phase L.2: Exclude unpublished assets
            }
            
            $counts = $countQuery
                ->whereNull('archived_at') // Phase L.3: Exclude archived assets
                ->where(function ($query) {
                    // Phase M: Exclude expired assets
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->where(function ($query) {
                    // Phase AF-1: Exclude pending and rejected assets
                    $query->where('approval_status', 'not_required')
                          ->orWhere('approval_status', 'approved')
                          ->orWhereNull('approval_status'); // Handle legacy assets
                })
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
        // Phase L.5.1: Count logic matches grid visibility (approvers see unpublished, others don't)
        $totalCountQuery = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET);
        
        // Apply same visibility rules as main query
        // CRITICAL: Unpublished assets should NEVER be counted unless filter is explicitly active
        // Even users with permissions should not see unpublished assets in counts by default
        if ($canSeeUnpublished) {
            // Users with permissions can see HIDDEN assets (pending approval) that are published
            // But still exclude unpublished assets unless filter is active
            $totalCountQuery->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN])
                ->whereNotNull('published_at'); // Always exclude unpublished in default counts
        } else {
            // Regular users only see VISIBLE, published assets
            $totalCountQuery->where('status', AssetStatus::VISIBLE)
                ->whereNotNull('published_at'); // Phase L.2: Exclude unpublished assets
        }
        
        $totalAssetCount = $totalCountQuery
            ->whereNull('archived_at') // Phase L.3: Exclude archived assets
            ->where(function ($query) {
                // Phase M: Exclude expired assets
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) {
                // Phase AF-1: Exclude pending and rejected assets
                $query->where('approval_status', 'not_required')
                      ->orWhere('approval_status', 'approved')
                      ->orWhereNull('approval_status'); // Handle legacy assets
            })
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
        // Step 2: Asset visibility must not depend on metadata approval or existence
        // Assets remain visible regardless of metadata approval state, rejection, or presence

        // Phase L.5.1: $canSeeUnpublished is already defined above (after $user is available)

        $assetsQuery = Asset::query()
        ->where('tenant_id', $tenant->id)
        ->where('brand_id', $brand->id)
        ->where('type', AssetType::ASSET);

        // Phase L.5.1: Lifecycle filter - Check early to determine visibility rules
        // SECURITY: Apply lifecycle filters based on specific permissions
        // - pending_approval: Requires asset.publish (approvers)
        // - pending_publication: Phase J - Contributors can see their own, approvers see all
        // - unpublished: Requires metadata.bypass_approval (full viewing privileges)
        // - archived: Requires asset.archive (users who can archive assets)
        // - expired: Requires asset.archive (users who can archive assets, similar lifecycle management)
        // This prevents unauthorized access to filtered assets via URL manipulation
        $lifecycleFilter = $request->get('lifecycle');
        if ($lifecycleFilter === 'pending_approval' && !$canPublish) {
            // User doesn't have asset.publish permission - ignore filter and log security event
            \Log::warning('[AssetController] Unauthorized pending_approval filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_filter' => $lifecycleFilter,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            $lifecycleFilter = null; // Reset to prevent filter application
        } elseif ($lifecycleFilter === 'pending_publication') {
            // Phase J: Pending Publication filter - Contributors and approvers can access
            // No additional permission check needed - visibility rules handle access control
            // (Contributors see only their own, approvers see all)
        } elseif ($lifecycleFilter === 'unpublished' && !$canBypassApproval) {
            // User doesn't have metadata.bypass_approval permission - ignore filter and log security event
            \Log::warning('[AssetController] Unauthorized unpublished filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_filter' => $lifecycleFilter,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            $lifecycleFilter = null; // Reset to prevent filter application
        } elseif ($lifecycleFilter === 'archived' && !$canArchive) {
            // User doesn't have asset.archive permission - ignore filter and log security event
            \Log::warning('[AssetController] Unauthorized archived filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_filter' => $lifecycleFilter,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            $lifecycleFilter = null; // Reset to prevent filter application
        } elseif ($lifecycleFilter === 'expired' && !$canArchive) {
            // Phase M: User doesn't have asset.archive permission - ignore filter and log security event
            // Use same permission as archived since it's similar lifecycle management
            \Log::warning('[AssetController] Unauthorized expired filter access attempt', [
                'user_id' => $user?->id,
                'lifecycle_filter' => $lifecycleFilter,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
            $lifecycleFilter = null; // Reset to prevent filter application
        }

        // Phase L.5.1: Visibility filter
        // Users with asset.publish can see HIDDEN assets (pending approval)
        // Other users only see VISIBLE assets
        // When lifecycle filter is active, adjust status filter accordingly
        if ($lifecycleFilter === 'pending_approval' && $canPublish) {
            // Pending approval: Only HIDDEN status, unpublished
            // Requires asset.publish permission (approvers)
            $assetsQuery->where('status', AssetStatus::HIDDEN)
                ->whereNull('published_at');
            
            \Log::info('[AssetController] Applied pending_approval lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
            ]);
        } elseif ($lifecycleFilter === 'pending_publication') {
            // Phase J: Pending Publication - Show assets with approval_status = pending or rejected
            // Visibility rules:
            // - Contributors: Only their own pending/rejected assets
            // - Admin/Owner/Brand Manager: All pending/rejected assets
            $userRole = $user ? $user->getRoleForTenant($tenant) : null;
            $isTenantOwnerOrAdmin = in_array(strtolower($userRole ?? ''), ['owner', 'admin']);
            
            // Check if user is a brand manager
            $isBrandManager = false;
            if ($user && $brand) {
                $membership = $user->activeBrandMembership($brand);
                $isBrandManager = $membership && ($membership['role'] ?? null) === 'brand_manager';
            }
            
            // Check if user is a contributor
            $isContributor = false;
            if ($user && $brand) {
                $membership = $user->activeBrandMembership($brand);
                $isContributor = $membership && ($membership['role'] ?? null) === 'contributor';
            }
            
            // Approvers (Admin/Owner/Brand Manager) see all pending/rejected assets
            // Contributors see only their own pending/rejected assets
            if ($isContributor && !$isTenantOwnerOrAdmin && !$isBrandManager) {
                // Contributor: Only their own assets
                $assetsQuery->where('user_id', $user->id);
            }
            // Admin/Owner/Brand Manager: No user_id filter (see all)
            
            // Filter by approval_status = pending or rejected
            $assetsQuery->where(function ($query) {
                $query->where('approval_status', \App\Enums\ApprovalStatus::PENDING)
                      ->orWhere('approval_status', \App\Enums\ApprovalStatus::REJECTED);
            });
            
            \Log::info('[AssetController] Applied pending_publication lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'is_contributor' => $isContributor,
                'is_approver' => $isTenantOwnerOrAdmin || $isBrandManager,
            ]);
        } elseif ($lifecycleFilter === 'unpublished' && $canBypassApproval) {
            // Unpublished filter: Show all unpublished assets (both VISIBLE and HIDDEN status)
            // Requires metadata.bypass_approval permission (full viewing privileges)
            $assetsQuery->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN])
                ->whereNull('published_at');
            
            \Log::info('[AssetController] Applied unpublished lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'can_bypass_approval' => $canBypassApproval,
            ]);
        } elseif ($lifecycleFilter === 'archived' && $canArchive) {
            // Archived filter: Show only archived assets
            // Requires asset.archive permission (users who can archive assets)
            $assetsQuery->whereNotNull('archived_at');
            
            \Log::info('[AssetController] Applied archived lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'can_archive' => $canArchive,
            ]);
        } elseif ($lifecycleFilter === 'expired' && $canArchive) {
            // Phase M: Expired filter: Show only expired assets
            // Requires asset.archive permission (users who can archive assets, similar lifecycle management)
            $assetsQuery->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now());
            
            \Log::info('[AssetController] Applied expired lifecycle filter', [
                'user_id' => $user?->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'can_archive' => $canArchive,
            ]);
        } else {
            // Default visibility rules (no lifecycle filter active)
            // CRITICAL: Unpublished assets should NEVER show unless filter is explicitly active
            // Even users with permissions should not see unpublished assets by default
            if ($canSeeUnpublished) {
                // Users with permissions can see HIDDEN assets (pending approval) that are published
                // But still exclude unpublished assets unless filter is active
                $assetsQuery->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN])
                    ->whereNotNull('published_at'); // Always exclude unpublished in default view
            } else {
                // Regular users only see VISIBLE, published assets
                $assetsQuery->where('status', AssetStatus::VISIBLE)
                    ->whereNotNull('published_at'); // Phase L.2: Exclude unpublished assets
            }
        }

        // Phase L.3: Exclude archived assets by default (unless archived filter is active)
        // Archived assets are hidden from the grid unless explicitly filtered
        if ($lifecycleFilter !== 'archived') {
            $assetsQuery->whereNull('archived_at');
        }

        // Phase M: Exclude expired assets by default
        // Expired assets are hidden from the grid unless explicitly filtered
        // Archived assets are excluded first, then expired assets
        // Expiration is derived state: expired = expires_at != null && expires_at < now()
        // Note: Expired filter is handled above in lifecycle filter section
        if ($lifecycleFilter !== 'expired') {
            $assetsQuery->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            });
        }

        // Phase AF-1: Exclude pending and rejected assets from main grid
        // Phase J: Pending/rejected assets are visible only in "Pending Publication" lifecycle filter
        // Note: This applies to default view only - lifecycle filter handles pending/rejected separately
        if ($lifecycleFilter !== 'pending_publication') {
            $assetsQuery->where(function ($query) {
                $query->where('approval_status', 'not_required')
                      ->orWhere('approval_status', 'approved')
                      ->orWhereNull('approval_status'); // Handle legacy assets without approval_status
            });
        }

        // Exclude soft-deleted assets only
        $assetsQuery->whereNull('deleted_at');

        // Default sort: recently added (created_at desc)
        // This ensures newest assets appear first in the grid
        $sortBy = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');
        
        if ($sortBy === 'created_at') {
            $assetsQuery->orderBy('created_at', $sortOrder);
        } else {
            // Fallback to created_at desc if invalid sort
            $assetsQuery->orderBy('created_at', 'desc');
        }

        // Filter by category if provided (check metadata for category_id)
        if ($categoryId) {
            // Filter assets where metadata->category_id matches the category ID
            // Use direct JSON path comparison for exact integer match
            // Cast categoryId to integer to ensure type matching with JSON integer values
            $assetsQuery->whereNotNull('metadata')
                ->where('metadata->category_id', (int) $categoryId);
        }

        // Filter by uploaded_by (user filter)
        $uploadedBy = $request->get('uploaded_by');
        if ($uploadedBy) {
            $assetsQuery->where('user_id', (int) $uploadedBy);
        }

        // Filter by file type (extension)
        $fileType = $request->get('file_type');
        if ($fileType && $fileType !== 'all') {
            // Normalize extension (remove leading dot, lowercase)
            $extension = strtolower(trim($fileType, '.'));
            
            // Filter by file extension from original_filename
            $assetsQuery->where(function ($query) use ($extension) {
                // Match extension in original_filename (case-insensitive)
                $query->whereRaw('LOWER(SUBSTRING_INDEX(original_filename, ".", -1)) = ?', [$extension])
                      // Also match mime_type for common formats
                      ->orWhere(function ($q) use ($extension) {
                          $mimeMap = [
                              'jpg' => ['image/jpeg', 'image/jpg'],
                              'jpeg' => ['image/jpeg', 'image/jpg'],
                              'png' => ['image/png'],
                              'gif' => ['image/gif'],
                              'webp' => ['image/webp'],
                              'svg' => ['image/svg+xml'],
                              'pdf' => ['application/pdf'],
                              'tiff' => ['image/tiff', 'image/tif'],
                              'tif' => ['image/tiff', 'image/tif'],
                              'psd' => ['image/vnd.adobe.photoshop'],
                              'mp4' => ['video/mp4'],
                              'mov' => ['video/quicktime'],
                              'avi' => ['video/x-msvideo'],
                              'webm' => ['video/webm'],
                          ];
                          
                          if (isset($mimeMap[$extension])) {
                              $q->whereIn('mime_type', $mimeMap[$extension]);
                          }
                      });
            });
        }

        // Phase 2 – Step 8: Apply metadata filters
        // Filters can come as JSON string (from URL query param) or array (from Inertia)
        $filtersParam = $request->get('filters', []);
        $filters = [];
        
        // Parse filters if it's a JSON string
        if (is_string($filtersParam)) {
            $decoded = json_decode($filtersParam, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $filters = $decoded;
            } else {
                \Log::warning('[AssetController] DEBUG - Failed to parse filters JSON', [
                    'filtersParam' => $filtersParam,
                    'json_error' => json_last_error_msg(),
                ]);
            }
        } elseif (is_array($filtersParam)) {
            $filters = $filtersParam;
        }
        
        \Log::info('[AssetController] DEBUG - Filters parsing', [
            'filtersParam_type' => gettype($filtersParam),
            'filtersParam' => $filtersParam,
            'parsed_filters' => $filters,
            'filters_count' => count($filters),
        ]);
        
        if (!empty($filters) && is_array($filters)) {
            // Resolve metadata schema for filtering
            // Note: asset_type in category is organizational (asset/deliverable/ai_generated),
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
        
        // SECURITY: Filter assets through AssetPolicy to ensure user can view each asset
        // This is critical for enterprise-level permission enforcement
        // Even if query returns assets, user must have explicit permission to view them
        if ($user) {
            $assets = $assets->filter(function ($asset) use ($user) {
                return \Illuminate\Support\Facades\Gate::forUser($user)->allows('view', $asset);
            })->values();
        } else {
            // No user - filter out all assets (shouldn't happen in authenticated routes, but be defensive)
            $assets = collect();
        }
        
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
        
        // Get available file types from assets (before mapping to arrays)
        $availableFileTypes = $this->getAvailableFileTypes($assets);
        
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
                            'name' => trim($user->name) ?: null, // Convert empty string to null for proper fallback
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'avatar_url' => $user->avatar_url,
                        ];
                    }
                }

                // Phase L.4: Get lifecycle relationship data (published_by, archived_by)
                $publishedBy = null;
                if ($asset->published_by_id) {
                    $user = \App\Models\User::find($asset->published_by_id);
                    if ($user) {
                        $publishedBy = [
                            'id' => $user->id,
                            'name' => $user->name,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'email' => $user->email,
                            'avatar_url' => $user->avatar_url,
                        ];
                    }
                }

                $archivedBy = null;
                if ($asset->archived_by_id) {
                    $user = \App\Models\User::find($asset->archived_by_id);
                    if ($user) {
                        $archivedBy = [
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
                
                // Get metadata - refresh only if we suspect it might be stale
                // (e.g., if thumbnail_status is completed but metadata seems incomplete)
                // For most cases, the asset from the query is fresh enough
                $metadata = $asset->metadata ?? [];
                
                // Clear old skip reasons for formats that are now supported (TIFF/AVIF via Imagick)
                // This ensures UI shows correct status when viewing assets
                if (isset($metadata['thumbnail_skip_reason'])) {
                    $skipReason = $metadata['thumbnail_skip_reason'];
                    $mimeType = strtolower($asset->mime_type ?? '');
                    $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
                    
                    $isNowSupported = false;
                    if ($skipReason === 'unsupported_format:tiff' && 
                        ($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif') &&
                        extension_loaded('imagick')) {
                        $isNowSupported = true;
                    } elseif ($skipReason === 'unsupported_format:avif' && 
                              ($mimeType === 'image/avif' || $extension === 'avif') &&
                              extension_loaded('imagick')) {
                        $isNowSupported = true;
                    } elseif (($skipReason === 'unsupported_format:psd' || $skipReason === 'unsupported_file_type') && 
                              ($mimeType === 'image/vnd.adobe.photoshop' || $extension === 'psd' || $extension === 'psb') &&
                              extension_loaded('imagick')) {
                        // PSD files are now supported via Imagick
                        $isNowSupported = true;
                    }
                    
                    if ($isNowSupported) {
                        // Clear skip reason and reset status
                        unset($metadata['thumbnail_skip_reason']);
                        
                        // Only dispatch job if not already processing or completed
                        $currentStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                            ? $asset->thumbnail_status->value 
                            : ($asset->thumbnail_status ?? 'pending');
                        
                        $shouldDispatch = $currentStatus !== 'processing' && $currentStatus !== 'completed';
                        
                        $asset->update([
                            'thumbnail_status' => \App\Enums\ThumbnailStatus::PENDING,
                            'thumbnail_error' => null,
                            'metadata' => $metadata,
                        ]);
                        
                        // Automatically dispatch thumbnail generation job for newly supported formats
                        // Only if not already processing/completed to avoid duplicate jobs
                        if ($shouldDispatch) {
                            try {
                                \App\Jobs\GenerateThumbnailsJob::dispatch($asset->id);
                                \Illuminate\Support\Facades\Log::info('[AssetController] Dispatched thumbnail generation after clearing skip reason', [
                                    'asset_id' => $asset->id,
                                    'old_skip_reason' => $skipReason,
                                    'format' => $mimeType . '/' . $extension,
                                ]);
                            } catch (\Exception $e) {
                                \Illuminate\Support\Facades\Log::warning('[AssetController] Failed to dispatch thumbnail generation after clearing skip reason', [
                                    'asset_id' => $asset->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                        
                        // Refresh asset to get updated status and metadata
                        $asset->refresh();
                        $metadata = $asset->metadata ?? [];
                        
                        \Illuminate\Support\Facades\Log::info('[AssetController] Cleared old skip reason on asset load', [
                            'asset_id' => $asset->id,
                            'old_skip_reason' => $skipReason,
                            'format' => $mimeType . '/' . $extension,
                            'dispatched_job' => $shouldDispatch,
                        ]);
                    }
                }
                
                // Only refresh if we're looking for dimensions and they're missing
                // This avoids expensive refresh() calls for every asset in the grid
                $needsRefresh = false;
                if ($asset->mime_type && str_starts_with($asset->mime_type, 'image/')) {
                    // For images, check if dimensions should exist but are missing
                    $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                        ? $asset->thumbnail_status->value 
                        : ($asset->thumbnail_status ?? 'pending');
                    
                    // If thumbnails are completed, dimensions should be available
                    if ($thumbnailStatus === 'completed' && !isset($metadata['image_width']) && !isset($metadata['image_height'])) {
                        $needsRefresh = true;
                    }
                }
                
                if ($needsRefresh) {
                    $asset->refresh();
                    $metadata = $asset->metadata ?? [];
                }
                
                // Phase G.4: Merge asset_metadata table rows into metadata.fields structure
                // This ensures automated fields (orientation, resolution_class, dominant_colors, etc.) appear in the UI
                $assetMetadataRows = \DB::table('asset_metadata')
                    ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
                    ->where('asset_metadata.asset_id', $asset->id)
                    ->whereNotNull('asset_metadata.approved_at') // Only approved values
                    ->select('metadata_fields.key', 'asset_metadata.value_json')
                    ->get();
                
                // Initialize fields structure if it doesn't exist
                if (!isset($metadata['fields'])) {
                    $metadata['fields'] = [];
                }
                
                // Merge asset_metadata rows into fields (automated fields will be added here)
                // Note: dominant_colors is included here as it's stored in asset_metadata table
                foreach ($assetMetadataRows as $row) {
                    $value = json_decode($row->value_json, true);
                    // Use the most recent value if multiple exist (last one wins)
                    $metadata['fields'][$row->key] = $value;
                }
                
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
                
                // Final thumbnail URL provided when thumbnail_status === COMPLETED
                // OR when thumbnails actually exist in metadata (handles status sync issues)
                // Use 'medium' size for better quality in asset grid
                $thumbnailsExistInMetadata = !empty($metadata['thumbnails']) && (isset($metadata['thumbnails']['thumb']) || isset($metadata['thumbnails']['medium']));
                $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                
                if ($thumbnailStatus === 'completed' || $thumbnailsExistInMetadata) {
                    // Prefer medium size for better quality, fallback to thumb if medium not available
                    $thumbnailStyle = 'medium';
                    $thumbnailPath = $asset->thumbnailPathForStyle('medium');
                    
                    // Fallback to 'thumb' if medium doesn't exist
                    if (!$thumbnailPath && !isset($metadata['thumbnails']['medium'])) {
                        $thumbnailStyle = 'thumb';
                        $thumbnailPath = $asset->thumbnailPathForStyle('thumb');
                    }
                    
                    if ($thumbnailPath || $thumbnailsExistInMetadata) {
                        $finalThumbnailUrl = route('assets.thumbnail.final', [
                            'asset' => $asset->id,
                            'style' => $thumbnailStyle,
                        ]);
                        
                        // Add version query param if available (ensures browser refetches when version changes)
                        if ($thumbnailVersion) {
                            $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                        }
                        
                        // Auto-fix status if thumbnails exist but status is wrong
                        if ($thumbnailStatus !== 'completed' && $thumbnailsExistInMetadata) {
                            \Illuminate\Support\Facades\Log::info('[AssetController] Auto-fixing thumbnail status - thumbnails exist but status was failed', [
                                'asset_id' => $asset->id,
                                'old_status' => $thumbnailStatus,
                                'thumbnail_sizes' => array_keys($metadata['thumbnails'] ?? []),
                            ]);
                        }
                    } else {
                        // Thumbnail status says completed but no thumbnails found - log for debugging
                        \Illuminate\Support\Facades\Log::warning('[AssetController] Thumbnail status mismatch', [
                            'asset_id' => $asset->id,
                            'thumbnail_status' => $thumbnailStatus,
                            'metadata_thumbnails' => isset($metadata['thumbnails']) ? array_keys($metadata['thumbnails'] ?? []) : 'not set',
                        ]);
                    }
                }

                // Extract source dimensions if available
                // Dimensions are stored in metadata during thumbnail generation (image_width, image_height)
                // These are the actual pixel dimensions of the original source image
                $sourceDimensions = null;
                
                // Check if dimensions are stored in metadata (from thumbnail generation)
                // CRITICAL: Check both direct metadata and ensure asset is fresh from database
                if (isset($metadata['image_width']) && isset($metadata['image_height'])) {
                    $sourceDimensions = [
                        'width' => (int) $metadata['image_width'],
                        'height' => (int) $metadata['image_height'],
                    ];
                    
                    // Debug: Log when dimensions are found (temporary - can be removed later)
                    if (config('app.debug', false)) {
                        Log::debug('[AssetController] Dimensions found in metadata', [
                            'asset_id' => $asset->id,
                            'source_dimensions' => $sourceDimensions,
                        ]);
                    }
                } else {
                    // Debug: Log if dimensions are missing (temporary - can be removed later)
                    if (config('app.debug', false) && $asset->mime_type && str_starts_with($asset->mime_type, 'image/')) {
                        Log::debug('[AssetController] Dimensions missing in metadata', [
                            'asset_id' => $asset->id,
                            'metadata_keys' => array_keys($metadata ?? []),
                            'has_image_width' => isset($metadata['image_width']),
                            'has_image_height' => isset($metadata['image_height']),
                            'mime_type' => $asset->mime_type,
                            'metadata_sample' => array_slice($metadata ?? [], 0, 10, true), // First 10 keys for debugging
                        ]);
                    }
                }

                // Phase AF-1: Get approval info
                $approvedByUser = null;
                if ($asset->approved_by_user_id) {
                    $approvedByUser = \App\Models\User::find($asset->approved_by_user_id);
                }

                // Phase AF-4: Get aging metrics for pending assets
                $agingMetrics = null;
                if ($asset->approval_status === \App\Enums\ApprovalStatus::PENDING) {
                    $agingService = app(ApprovalAgingService::class);
                    $agingMetrics = $agingService->getAgingMetrics($asset);
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
                    'metadata' => $metadata, // Full metadata object (includes category_id and fields, with asset_metadata merged)
                    'category' => $categoryName ? [
                        'id' => $categoryId,
                        'name' => $categoryName,
                        'slug' => $category ? $category->slug : null, // Include slug for CSS class determination
                    ] : null,
                    'uploaded_by' => $uploadedBy, // User who uploaded the asset
                    // Phase L.4: Lifecycle fields (read-only display)
                    'published_at' => $asset->published_at?->toIso8601String(),
                    'published_by' => $publishedBy, // User who published the asset
                    'archived_at' => $asset->archived_at?->toIso8601String(),
                    'archived_by' => $archivedBy, // User who archived the asset
                    // Phase AF-1: Approval workflow fields
                    'approval_status' => $asset->approval_status instanceof \App\Enums\ApprovalStatus ? $asset->approval_status->value : ($asset->approval_status ?? 'not_required'),
                    'approval_required' => $asset->approval_status === \App\Enums\ApprovalStatus::PENDING,
                    'approved_at' => $asset->approved_at?->toIso8601String(),
                    'approved_by' => $approvedByUser ? [
                        'id' => $approvedByUser->id,
                        'name' => $approvedByUser->name,
                        'email' => $approvedByUser->email,
                    ] : null,
                    'rejected_at' => $asset->rejected_at?->toIso8601String(),
                    'rejection_reason' => $asset->rejection_reason,
                    'approval_capable' => $this->isApprovalCapable($user, $brand), // Current user can approve
                    // Phase AF-6: Approval summary (AI-generated)
                    'approval_summary' => $asset->approval_summary,
                    'approval_summary_generated_at' => $asset->approval_summary_generated_at?->toISOString(),
                    // Phase AF-4: Aging metrics (only for pending assets)
                    'pending_since' => $agingMetrics['pending_since'] ?? null,
                    'pending_days' => $agingMetrics['pending_days'] ?? null,
                    'last_action_at' => $agingMetrics['last_action_at'] ?? null,
                    'aging_label' => $agingMetrics['aging_label'] ?? null,
                    'source_dimensions' => $sourceDimensions, // Source image dimensions (width x height) if available
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
                    // TODO: Future enhancement - Add pending_metadata_count to asset grid items
                    // This would enable grid-level badges/indicators for assets with pending metadata
                    // Currently, pending metadata is only visible in the asset drawer
                    // Grid and filters remain unchanged - pending metadata does NOT affect filtering or search
                ];
            })
            ->values();

        // Phase 2 – Step 8: Get filterable schema for frontend
        // Note: asset_type in category is organizational (asset/marketing/ai_generated),
        // but MetadataSchemaResolver expects file type (image/video/document)
        // Default to 'image' for schema resolution when category context doesn't provide file type
        // TODO: Could infer from actual assets in category or add file_type to categories
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
            // This enables system-level metadata fields (orientation, color_space, resolution_class, dimensions)
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
        
        // available_values is required by Phase H filter visibility rules
        // Do not remove without updating Phase H contract
        // Compute distinct metadata values for the current asset grid result set
        $availableValues = [];
        
        if (!empty($filterableSchema) && $assets->count() > 0) {
            // Get asset IDs from the current grid result set
            $assetIds = $assets->pluck('id')->toArray();
            
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
                            }
                        }
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

        // DEBUG: Log filterable_schema and available_values for debugging
        $photoTypeInSchema = collect($filterableSchema)->first(function ($field) {
            return ($field['field_key'] ?? $field['key'] ?? '') === 'photo_type';
        });
        \Log::info('[AssetController] DEBUG - filterable_schema', [
            'count' => count($filterableSchema),
            'photo_type' => $photoTypeInSchema ? [
                'field_key' => $photoTypeInSchema['field_key'] ?? $photoTypeInSchema['key'] ?? 'unknown',
                'is_primary' => $photoTypeInSchema['is_primary'] ?? 'NOT_SET',
                'is_primary_type' => isset($photoTypeInSchema['is_primary']) ? gettype($photoTypeInSchema['is_primary']) : 'NOT_SET',
            ] : 'NOT_FOUND',
            'category_id' => $categoryId,
        ]);
        \Log::info('[AssetController] DEBUG - available_values', [
            'keys' => array_keys($availableValues),
            'photo_type' => $availableValues['photo_type'] ?? 'NOT_SET',
            'photo_type_count' => isset($availableValues['photo_type']) ? count($availableValues['photo_type']) : 0,
        ]);
        
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
            'available_values' => $availableValues, // available_values is required by Phase H filter visibility rules
            'uploaded_by_users' => \App\Models\User::whereIn('id', function ($query) use ($tenant, $brand) {
                $query->select('user_id')
                      ->from('assets')
                      ->where('tenant_id', $tenant->id)
                      ->where('brand_id', $brand->id)
                      ->where('type', AssetType::ASSET)
                      ->whereNull('deleted_at')
                      ->whereNotNull('user_id')
                      ->distinct();
            })
            ->select('id', 'first_name', 'last_name', 'email', 'avatar_url')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('email')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ];
            }),
            'available_file_types' => $availableFileTypes,
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
                ->get(['id', 'title', 'original_filename', 'thumbnail_status', 'thumbnail_error', 'status', 'created_at', 'user_id'])
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
                        'user_id' => $asset->user_id, // Include user_id to detect other users' uploads
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
                    // ALSO handle cases where thumbnails exist but status is failed
                    $finalThumbnailUrl = null;
                    $verifiedStatus = $thumbnailStatus;
                    $thumbnailsExistInMetadata = !empty($metadata['thumbnails']) && isset($metadata['thumbnails']['thumb']);
                    
                    if ($thumbnailStatus === 'completed' || $thumbnailsExistInMetadata) {
                        // Verify thumbnail file actually exists before returning COMPLETED
                        // OR if thumbnails exist in metadata, trust that they're valid
                        $thumbnailPath = $asset->thumbnailPathForStyle('thumb');
                        
                        if ($thumbnailsExistInMetadata) {
                            // Thumbnails exist in metadata - provide URL without S3 verification
                            // This handles cases where status is failed but thumbnails actually exist
                            $finalThumbnailUrl = route('assets.thumbnail.final', [
                                'asset' => $asset->id,
                                'style' => 'thumb',
                            ]);
                            
                            if ($thumbnailVersion) {
                                $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                            }
                            
                            // Auto-fix status if it was wrong
                            if ($thumbnailStatus !== 'completed') {
                                Log::info('[batchThumbnailStatus] Auto-fixing thumbnail status - thumbnails exist in metadata', [
                                    'asset_id' => $asset->id,
                                    'old_status' => $thumbnailStatus,
                                    'thumbnail_sizes' => array_keys($metadata['thumbnails'] ?? []),
                                ]);
                                $verifiedStatus = 'completed';
                            }
                        } elseif ($thumbnailPath && $asset->storageBucket) {
                            try {
                                // Create S3 client for verification
                                $s3Client = $this->createS3ClientForVerification();
                                $result = $s3Client->headObject([
                                    'Bucket' => $asset->storageBucket->name,
                                    'Key' => $thumbnailPath,
                                ]);
                                
                                // Verify file size > minimum threshold (only catch broken/corrupted files)
                                // Small valid thumbnails (e.g., 710 bytes for compressed WebP) are acceptable
                                $contentLength = $result['ContentLength'] ?? 0;
                                $minValidSize = 50; // Only catch broken/corrupted files - allow small valid thumbnails
                                
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
    /**
     * Check if current user is approval_capable for the brand.
     * 
     * Phase AF-1: Approval authority derived from PermissionMap.
     */
    protected function isApprovalCapable(?User $user, ?Brand $brand): bool
    {
        if (!$user || !$brand) {
            return false;
        }

        $brandRole = $user->getRoleForBrand($brand);
        return $brandRole && PermissionMap::canApproveAssets($brandRole);
    }

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
        
        // Final thumbnail URL provided when thumbnail_status === COMPLETED
        // OR when thumbnails actually exist in metadata (handles status sync issues)
        // Use 'medium' size for better quality
        $thumbnailsExistInMetadata = !empty($metadata['thumbnails']) && (isset($metadata['thumbnails']['thumb']) || isset($metadata['thumbnails']['medium']));
        $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
        
        if ($thumbnailStatus === 'completed' || $thumbnailsExistInMetadata) {
            // Prefer medium size for better quality, fallback to thumb if medium not available
            $thumbnailStyle = 'medium';
            $thumbnailPath = $asset->thumbnailPathForStyle('medium');
            
            // Fallback to 'thumb' if medium doesn't exist
            if (!$thumbnailPath && !isset($metadata['thumbnails']['medium'])) {
                $thumbnailStyle = 'thumb';
                $thumbnailPath = $asset->thumbnailPathForStyle('thumb');
            }
            
            $finalThumbnailUrl = route('assets.thumbnail.final', [
                'asset' => $asset->id,
                'style' => $thumbnailStyle,
            ]);
            
            // Add version query param if available
            if ($thumbnailVersion) {
                $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
            }
            
            // Auto-fix status if thumbnails exist but status is wrong  
            if ($thumbnailStatus !== 'completed' && $thumbnailsExistInMetadata) {
                Log::info('[AssetController::show] Auto-fixing thumbnail status - thumbnails exist but status was failed', [
                    'asset_id' => $asset->id,
                    'old_status' => $thumbnailStatus,
                    'thumbnail_sizes' => array_keys($metadata['thumbnails'] ?? []),
                ]);
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
     * Get signed view URL for an asset (for viewing/streaming, not downloading).
     * This endpoint returns a signed URL without tracking a download metric.
     *
     * GET /assets/{asset}/view
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function view(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        try {
            // Generate signed S3 URL for viewing (not downloading)
            if (!$asset->storage_root_path) {
                return response()->json([
                    'message' => 'Asset storage path not found',
                ], 404);
            }

            $s3Client = $this->createS3ClientForVerification();
            $bucket = $asset->storageBucket;
            
            // Generate presigned URL WITHOUT Content-Disposition header (for viewing/streaming)
            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket->name,
                'Key' => $asset->storage_root_path,
                // No ResponseContentDisposition - allows inline viewing/streaming
            ]);
            
            $presignedRequest = $s3Client->createPresignedRequest(
                $command,
                now()->addMinutes(15) // URL valid for 15 minutes
            );
            
            $signedUrl = (string) $presignedRequest->getUri();

            // NOTE: We do NOT track a download metric here - this is for viewing only
            // View tracking should be handled separately via the metrics API

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to generate view URL', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate view URL',
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
     * Regenerate AI metadata for an asset.
     *
     * POST /assets/{asset}/ai-metadata/regenerate
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function regenerateAiMetadata(Asset $asset): JsonResponse
    {
        $user = auth()->user();
        $tenant = app('tenant');

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'error' => 'Asset not found',
            ], 404);
        }

        // Permission check
        if (!$user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            // Check if user is owner/admin (bypass permission)
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            if (!$isTenantOwnerOrAdmin) {
                return response()->json([
                    'error' => 'You do not have permission to regenerate AI metadata.',
                ], 403);
            }
        }

        // Check plan limits
        $usageService = app(\App\Services\AiUsageService::class);
        try {
            $usageService->checkUsage($tenant, 'tagging', 1);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json([
                'error' => 'Plan limit exceeded',
                'message' => $e->getMessage(),
            ], 403);
        }

        // **CRITICAL:** Manual regenerate does NOT clear dismissals
        // The _ai_suggestions_dismissed array must persist across regenerations
        // This prevents users from seeing previously dismissed suggestions again

        // Phase J.2.2: Check AI tagging policy before manual regeneration
        $policyService = app(\App\Services\AiTagPolicyService::class);
        $policyCheck = $policyService->shouldProceedWithAiTagging($asset);
        
        if (!$policyCheck['should_proceed']) {
            return response()->json([
                'error' => 'AI tagging is disabled for this tenant',
                'reason' => $policyCheck['reason'] ?? 'policy_denied',
            ], 403);
        }

        // Clear previous status/error flags to allow fresh regeneration
        $metadata = $asset->metadata ?? [];
        unset($metadata['_ai_metadata_generated_at'], $metadata['_ai_metadata_status']);
        unset($metadata['_ai_metadata_skipped'], $metadata['_ai_metadata_skip_reason'], $metadata['_ai_metadata_skipped_at']);
        unset($metadata['_ai_metadata_failed'], $metadata['_ai_metadata_error'], $metadata['_ai_metadata_failed_at']);
        $asset->update(['metadata' => $metadata]);

        // Dispatch job with manual rerun flag (will update _ai_metadata_generated_at timestamp)
        \App\Jobs\AiMetadataGenerationJob::dispatch($asset->id, isManualRerun: true);

        // Log activity
        \App\Services\ActivityRecorder::logAsset($asset, \App\Enums\EventType::ASSET_AI_METADATA_REGENERATED, [
            'triggered_by' => $user->id,
            'triggered_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'AI metadata regeneration queued',
        ]);
    }

    /**
     * Regenerate system metadata for an asset.
     *
     * POST /assets/{asset}/system-metadata/regenerate
     *
     * System metadata = orientation, color_space, resolution_class (automatically computed)
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function regenerateSystemMetadata(Asset $asset): JsonResponse
    {
        $user = auth()->user();
        $tenant = app('tenant');

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'error' => 'Asset not found',
            ], 404);
        }

        // Permission check - same as AI metadata
        if (!$user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            if (!$isTenantOwnerOrAdmin) {
                return response()->json([
                    'error' => 'You do not have permission to regenerate system metadata.',
                ], 403);
            }
        }

        // Clear completion flags to allow regeneration
        $metadata = $asset->metadata ?? [];
        unset($metadata['computed_metadata_completed'], $metadata['computed_metadata_completed_at']);
        $asset->update(['metadata' => $metadata]);

        // Dispatch both jobs that handle system metadata
        \App\Jobs\ComputedMetadataJob::dispatch($asset->id);
        \App\Jobs\PopulateAutomaticMetadataJob::dispatch($asset->id);

        // Log activity
        \App\Services\ActivityRecorder::logAsset($asset, \App\Enums\EventType::ASSET_SYSTEM_METADATA_REGENERATED, [
            'triggered_by' => $user->id,
            'triggered_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'System metadata regeneration queued',
        ]);
    }

    /**
     * Regenerate AI tagging for an asset.
     *
     * POST /assets/{asset}/ai-tagging/regenerate
     *
     * AI tagging = general/freeform tags (AITaggingJob)
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function regenerateAiTagging(Asset $asset): JsonResponse
    {
        $user = auth()->user();
        $tenant = app('tenant');

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'error' => 'Asset not found',
            ], 404);
        }

        // Permission check
        if (!$user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
            
            if (!$isTenantOwnerOrAdmin) {
                return response()->json([
                    'error' => 'You do not have permission to regenerate AI tagging.',
                ], 403);
            }
        }

        // Check plan limits
        $usageService = app(\App\Services\AiUsageService::class);
        try {
            $usageService->checkUsage($tenant, 'tagging', 1);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json([
                'error' => 'Plan limit exceeded',
                'message' => $e->getMessage(),
            ], 403);
        }

        // Clear completion flags and status to allow regeneration
        $metadata = $asset->metadata ?? [];
        unset($metadata['ai_tagging_completed'], $metadata['ai_tagging_completed_at']);
        unset($metadata['_ai_tagging_status']);
        unset($metadata['_ai_tagging_skipped'], $metadata['_ai_tagging_skip_reason'], $metadata['_ai_tagging_skipped_at']);
        unset($metadata['_ai_tagging_failed'], $metadata['_ai_tagging_error'], $metadata['_ai_tagging_failed_at']);
        $asset->update(['metadata' => $metadata]);

        // Dispatch AI tagging job
        \App\Jobs\AITaggingJob::dispatch($asset->id);

        // Log activity
        \App\Services\ActivityRecorder::logAsset($asset, \App\Enums\EventType::ASSET_AI_TAGGING_REGENERATED, [
            'triggered_by' => $user->id,
            'triggered_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'AI tagging regeneration queued',
        ]);
    }

    /**
     * Delete an asset.
     *
     * DELETE /assets/{asset}
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    /**
     * Publish an asset.
     *
     * Phase L.6.1 — Asset Approval Actions
     * Publishes an asset using AssetPublicationService with proper authorization.
     *
     * @param Asset $asset The asset to publish
     * @return JsonResponse
     */
    public function publish(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Verify user is authenticated
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            // Publish the asset (service handles authorization via policy)
            $this->publicationService->publish($asset, $user);

            // Refresh asset to get updated state
            $asset->refresh();

            return response()->json([
                'message' => 'Asset published successfully',
                'asset_id' => $asset->id,
                'published_at' => $asset->published_at?->toIso8601String(),
                'published_by_id' => $asset->published_by_id,
                'status' => $asset->status->value,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Provide more helpful error message
            $tenant = app('tenant');
            $hasPermission = $user->hasPermissionForTenant($tenant, 'asset.publish');
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantAdminOrOwner = in_array($tenantRole, ['admin', 'owner']);
            // Phase MI-1: Check active brand membership
            $isAssignedToBrand = $asset->brand_id ? ($user->activeBrandMembership($asset->brand) !== null) : true;
            
            $message = 'You do not have permission to publish this asset.';
            if (!$hasPermission && !$isTenantAdminOrOwner) {
                $message .= ' Your role does not have the "asset.publish" permission.';
            } elseif (!$isAssignedToBrand && !$isTenantAdminOrOwner) {
                $message .= ' You are not assigned to the brand that owns this asset.';
            }
            
            return response()->json([
                'message' => $message,
            ], 403);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to publish asset', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to publish asset: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unpublish an asset.
     *
     * Phase L.6.1 — Asset Approval Actions
     * Unpublishes an asset using AssetPublicationService with proper authorization.
     *
     * @param Asset $asset The asset to unpublish
     * @return JsonResponse
     */
    public function unpublish(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Verify user is authenticated
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            // Unpublish the asset (service handles authorization via policy)
            $this->publicationService->unpublish($asset, $user);

            // Refresh asset to get updated state
            $asset->refresh();

            return response()->json([
                'message' => 'Asset unpublished successfully',
                'asset_id' => $asset->id,
                'published_at' => null,
                'published_by_id' => null,
                'status' => $asset->status->value,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'You do not have permission to unpublish this asset',
            ], 403);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to unpublish asset', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to unpublish asset: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive an asset.
     *
     * Phase L.3 — Asset Archive & Restore
     * Archives an asset using AssetArchiveService with proper authorization.
     *
     * @param Asset $asset The asset to archive
     * @return JsonResponse
     */
    public function archive(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Verify user is authenticated
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            // Archive the asset (service handles authorization via policy)
            $this->archiveService->archive($asset, $user);

            // Refresh asset to get updated state
            $asset->refresh();

            return response()->json([
                'message' => 'Asset archived successfully',
                'asset_id' => $asset->id,
                'archived_at' => $asset->archived_at?->toIso8601String(),
                'archived_by_id' => $asset->archived_by_id,
                'status' => $asset->status->value,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'You do not have permission to archive this asset',
            ], 403);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to archive asset', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to archive asset: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore an archived asset.
     *
     * Phase L.3 — Asset Archive & Restore
     * Restores an asset using AssetArchiveService with proper authorization.
     *
     * @param Asset $asset The asset to restore
     * @return JsonResponse
     */
    public function restore(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = auth()->user();

        // Verify asset belongs to tenant
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Asset not found',
            ], 404);
        }

        // Verify user is authenticated
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            // Restore the asset (service handles authorization via policy)
            $this->archiveService->restore($asset, $user);

            // Refresh asset to get updated state
            $asset->refresh();

            return response()->json([
                'message' => 'Asset restored successfully',
                'asset_id' => $asset->id,
                'archived_at' => null,
                'archived_by_id' => null,
                'status' => $asset->status->value,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'You do not have permission to restore this asset',
            ], 403);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to restore asset', [
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to restore asset: ' . $e->getMessage(),
            ], 500);
        }
    }

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

    /**
     * Initiate file replacement for a rejected asset.
     * 
     * Phase J.3.1: File-only replacement for rejected contributor assets
     * 
     * POST /assets/{asset}/replace-file
     * 
     * Creates an upload session in 'replace' mode for replacing the file
     * of an existing rejected asset without modifying metadata.
     * 
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function initiateReplaceFile(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        // Verify user belongs to tenant
        if (!$user || !$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'error' => 'Unauthorized. Please check your account permissions.',
            ], 403);
        }

        // Verify asset belongs to tenant + brand
        if ($asset->tenant_id !== $tenant->id) {
            return response()->json([
                'error' => 'Asset does not belong to this tenant.',
            ], 403);
        }

        // Verify asset is rejected
        if ($asset->approval_status !== \App\Enums\ApprovalStatus::REJECTED) {
            return response()->json([
                'error' => 'Asset is not rejected. Only rejected assets can have their files replaced.',
                'current_status' => $asset->approval_status->value,
            ], 422);
        }

        // Phase MI-1: Verify active brand membership
        $brand = $asset->brand;
        if (!$brand) {
            return response()->json([
                'error' => 'Asset does not have an associated brand.',
            ], 422);
        }

        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return response()->json([
                'error' => 'You do not have active membership for this brand.',
            ], 403);
        }

        // Check permissions: User must be contributor AND uploader
        $brandRole = $membership['role'];
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        $isContributor = $brandRole === 'contributor' && !$isTenantOwnerOrAdmin;
        $isUploader = $asset->user_id === $user->id;

        if (!$isContributor || !$isUploader) {
            return response()->json([
                'error' => 'Only the contributor who uploaded this asset can replace its file.',
                'required' => 'Must be contributor and original uploader',
            ], 403);
        }

        // Verify brand requires contributor approval
        if (!$brand->requiresContributorApproval()) {
            return response()->json([
                'error' => 'Brand does not require contributor approval. File replacement is only available for assets requiring approval.',
            ], 422);
        }

        // Validate request
        $validated = $request->validate([
            'file_name' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1',
            'mime_type' => 'nullable|string|max:255',
            'client_reference' => 'nullable|uuid',
        ]);

        try {
            // Use UploadInitiationService to create upload session in replace mode
            $uploadService = app(\App\Services\UploadInitiationService::class);
            $result = $uploadService->initiateReplace(
                $tenant,
                $brand,
                $asset,
                $validated['file_name'],
                $validated['file_size'],
                $validated['mime_type'] ?? null,
                $validated['client_reference'] ?? null
            );

            Log::info('[AssetController] Replace file upload session initiated', [
                'asset_id' => $asset->id,
                'upload_session_id' => $result['upload_session_id'],
                'user_id' => $user->id,
            ]);

            return response()->json([
                'upload_session_id' => $result['upload_session_id'],
                'client_reference' => $result['client_reference'],
                'upload_session_status' => $result['upload_session_status'],
                'upload_type' => $result['upload_type'],
                'upload_url' => $result['upload_url'],
                'multipart_upload_id' => $result['multipart_upload_id'],
                'chunk_size' => $result['chunk_size'],
                'expires_at' => $result['expires_at'],
            ], 201);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 403);
        } catch (\Exception $e) {
            Log::error('[AssetController] Failed to initiate replace file upload', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to initiate file replacement: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available file types from the loaded assets.
     * 
     * Extracts unique file extensions from assets and returns them as an array.
     * Uses the same extension extraction logic as the asset mapping to ensure consistency.
     * 
     * @param \Illuminate\Support\Collection $assets
     * @return array Array of file extension strings (e.g., ['jpg', 'png', 'pdf'])
     */
    protected function getAvailableFileTypes($assets): array
    {
        $fileTypes = [];
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
            'video/webm' => 'webm',
            'image/vnd.adobe.photoshop' => 'psd',
            'application/vnd.adobe.illustrator' => 'ai',
        ];

        foreach ($assets as $asset) {
            $fileExtension = null;
            
            // Handle both object and array access (assets may be mapped to arrays)
            $originalFilename = is_array($asset) ? ($asset['original_filename'] ?? null) : ($asset->original_filename ?? null);
            $mimeType = is_array($asset) ? ($asset['mime_type'] ?? null) : ($asset->mime_type ?? null);
            
            // Try to get extension from original_filename (same logic as asset mapping)
            if ($originalFilename && $originalFilename !== 'unknown') {
                $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
                if ($ext && !empty(trim($ext))) {
                    $fileExtension = strtolower(trim($ext, '.'));
                }
            }
            
            // Fallback to mime_type if extension not found (same logic as asset mapping)
            if (empty($fileExtension) && $mimeType) {
                $mimeTypeLower = strtolower(trim($mimeType));
                $fileExtension = $mimeToExt[$mimeTypeLower] ?? null;
                
                // If not in map, try extracting from mime type subtype
                if (empty($fileExtension) && strpos($mimeTypeLower, '/') !== false) {
                    $mimeParts = explode('/', $mimeTypeLower);
                    $subtype = $mimeParts[1] ?? null;
                    if ($subtype) {
                        // Remove "+xml" suffix if present (e.g., "svg+xml" -> "svg")
                        $subtype = str_replace('+xml', '', $subtype);
                        $subtype = str_replace('+zip', '', $subtype);
                        // Normalize common subtypes (same as asset mapping)
                        if ($subtype === 'jpeg') {
                            $subtype = 'jpg';
                        } elseif ($subtype === 'tiff') {
                            $subtype = 'tif';
                        }
                        $fileExtension = $subtype;
                    }
                }
            }
            
            // Add to list if we found a valid extension
            if ($fileExtension && !in_array($fileExtension, $fileTypes, true)) {
                $fileTypes[] = $fileExtension;
            }
        }
        
        // Sort for consistent output
        sort($fileTypes);
        
        return $fileTypes;
    }
}
