<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Category;
use App\Services\AiMetadataConfidenceService;
use App\Services\Lifecycle\LifecycleResolver;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\Request;
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
        protected LifecycleResolver $lifecycleResolver
    ) {
    }

    /**
     * Display a listing of deliverables.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (!$tenant || !$brand) {
            return Inertia::render('Deliverables/Index', [
                'categories' => [],
                'selected_category' => null,
                'assets' => [], // Top-level prop must always be present for frontend
            ]);
        }

        // Get only deliverable categories for the brand
        // IMPORTANT: Always get ALL categories (including hidden) to check for existence
        // We'll filter hidden categories later when building the response, but we need
        // to know if a category exists (even if hidden) to avoid adding templates
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::DELIVERABLE);

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
        // Use CategoryPolicy to check access for each category
        $categories = $categories->filter(function ($category) use ($user) {
            // Use the policy to check if user can view this category
            return $user ? Gate::forUser($user)->allows('view', $category) : false;
        });

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
                'sort_order' => $matchingTemplate ? $matchingTemplate->sort_order : 999, // Use template sort_order or high default
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
        // Note: assets must be top-level prop for Inertia to pass to frontend component
        $assetsQuery = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::DELIVERABLE)
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
                    'category' => $categoryName ? [
                        'id' => $categoryId,
                        'name' => $categoryName,
                    ] : null,
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
                ];
            })
            ->values();

        // Phase L.5.1: Enable filters in "All Categories" view
        // Resolve schema even when categoryId is null to allow system-level filters
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

        // Attach color swatch data to dominant_color_bucket filter options (filter_type = 'color')
        $colorBucketService = app(\App\Services\ColorBucketService::class);
        foreach ($filterableSchema as &$field) {
            $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
            if ($fieldKey === 'dominant_color_bucket') {
                $bucketValues = $availableValues['dominant_color_bucket'] ?? [];
                $field['options'] = array_values(array_map(function ($bucketValue) use ($colorBucketService) {
                    return [
                        'value' => $bucketValue,
                        'label' => $bucketValue,
                        'swatch' => $colorBucketService->bucketToHex((string) $bucketValue),
                    ];
                }, $bucketValues));
                break;
            }
        }
        unset($field);

        return Inertia::render('Deliverables/Index', [
            'categories' => $allCategories,
            'selected_category' => $categoryId ? (int)$categoryId : null, // Category ID for frontend state
            'selected_category_slug' => $categorySlug, // Category slug for URL state
            'show_all_button' => $showAllButton,
            'assets' => $assets, // Top-level prop for frontend AssetGrid component
            'filterable_schema' => $filterableSchema, // Phase 2 – Step 8: Filterable metadata fields
            'available_values' => $availableValues, // available_values is required by Phase H filter visibility rules
        ]);
    }
}
