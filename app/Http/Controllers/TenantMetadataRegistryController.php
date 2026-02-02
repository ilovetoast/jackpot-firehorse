<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MetadataVisibilityProfile;
use App\Models\Tenant;
use App\Services\TenantMetadataFieldService;
use App\Services\TenantMetadataRegistryService;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

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
    ) {
    }

    /**
     * Display the Tenant Metadata Registry.
     *
     * GET /tenant/metadata/registry
     */
    public function index(): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            abort(404, 'Tenant not found');
        }

        // Check permission
        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (!$canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        // Get registry data
        $registry = $this->registryService->getRegistry($tenant);

        // Brands list for By Category brand selector (one brand at a time to avoid duplicate category names)
        $brands = $tenant->brands()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->values();

        // Active brand id for initial By Category brand dropdown selection (session/context brand)
        $activeBrand = app()->bound('brand') ? app('brand') : null;
        $active_brand_id = $activeBrand ? $activeBrand->id : null;

        // Get all active categories for suppression UI
        // Include both system and non-system categories (use active() scope)
        $categories = $tenant->brands()
            ->with(['categories' => function ($query) {
                $query->active() // Filter out soft-deleted and templates
                    ->orderBy('name');
            }])
            ->get()
            ->pluck('categories')
            ->flatten()
            ->filter(fn ($category) => $category->isActive()) // Additional check for system categories
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'brand_id' => $category->brand_id,
                'brand_name' => $category->brand->name ?? null,
                'asset_type' => $category->asset_type?->value ?? 'asset', // Include asset_type for grouping
            ])
            ->values();

        // Get plan limits for custom metadata fields
        $planService = app(\App\Services\PlanService::class);
        $limits = $planService->getPlanLimits($tenant);
        $maxCustomFields = $limits['max_custom_metadata_fields'] ?? 0;
        
        // Count current custom fields
        $currentCustomFieldsCount = \Illuminate\Support\Facades\DB::table('metadata_fields')
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->count();
        
        $canCreateCustomField = $maxCustomFields === 0 || $currentCustomFieldsCount < $maxCustomFields;

        // Owners and admins should have full access to manage fields
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        
        return Inertia::render('Tenant/MetadataRegistry/Index', [
            'registry' => $registry,
            'brands' => $brands,
            'active_brand_id' => $active_brand_id,
            'categories' => $categories,
            'canManageVisibility' => $isTenantOwnerOrAdmin || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage'),
            'canManageFields' => $isTenantOwnerOrAdmin || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage'),
            'customFieldsLimit' => [
                'max' => $maxCustomFields,
                'current' => $currentCustomFieldsCount,
                'can_create' => $canCreateCustomField,
            ],
        ]);
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (!$canView) {
            abort(403, 'You do not have permission to view the metadata registry.');
        }

        $registry = $this->registryService->getRegistry($tenant);

        return response()->json($registry);
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Validate request
        $validated = $request->validate([
            'show_on_upload' => 'nullable|boolean',
            'show_on_edit' => 'nullable|boolean',
            'show_in_filters' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
            'category_id' => 'nullable|integer|exists:categories,id', // Category-scoped primary placement
        ]);

        // Verify field exists and belongs to tenant (if tenant field)
        $fieldRecord = \DB::table('metadata_fields')
            ->where('id', $field)
            ->first();

        if (!$fieldRecord) {
            return response()->json(['error' => 'Field not found'], 404);
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

            if (!$category) {
                \Log::error('[TenantMetadataRegistryController] Category not found', [
                    'category_id' => $categoryId,
                    'tenant_id' => $tenant->id,
                ]);
                return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
            }

            // Use brand from context, or resolve from category so save works when context is missing (e.g. fetch from Metadata Registry)
            if (!$brand) {
                $brand = $category->brand;
                if (!$brand) {
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
            $isUploadHidden = isset($validated['show_on_upload']) ? !filter_var($validated['show_on_upload'], FILTER_VALIDATE_BOOLEAN) : null;
            $isEditHidden = isset($validated['show_on_edit']) ? !filter_var($validated['show_on_edit'], FILTER_VALIDATE_BOOLEAN) : null;
            $isFilterHidden = isset($validated['show_in_filters']) ? !filter_var($validated['show_in_filters'], FILTER_VALIDATE_BOOLEAN) : null;
            $isPrimary = isset($validated['is_primary']) ? filter_var($validated['is_primary'], FILTER_VALIDATE_BOOLEAN) : null;
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
                if ($isUploadHidden !== null) $updateData['is_upload_hidden'] = $isUploadHidden;
                // C9.2: Only update is_edit_hidden if column exists (defensive check for migration)
                if ($isEditHidden !== null && \Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
                    $updateData['is_edit_hidden'] = $isEditHidden;
                } elseif ($isEditHidden !== null) {
                    // Fallback: If column doesn't exist yet, log warning but don't fail
                    \Log::warning('[TenantMetadataRegistryController] is_edit_hidden column does not exist yet. Migration may not have run.', [
                        'field_id' => $field,
                        'category_id' => $categoryId,
                    ]);
                }
                if ($isFilterHidden !== null) $updateData['is_filter_hidden'] = $isFilterHidden;
                if ($isPrimary !== null) $updateData['is_primary'] = $isPrimary;
                
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
                // C9.2: Select columns explicitly to handle case where is_edit_hidden might not exist yet
                $selectColumns = ['id', 'is_hidden', 'is_upload_hidden', 'is_filter_hidden', 'is_primary'];
                if (Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
                    $selectColumns[] = 'is_edit_hidden';
                }
                
                $tenantOverride = \DB::table('metadata_field_visibility')
                    ->where('metadata_field_id', $field)
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('brand_id')
                    ->whereNull('category_id')
                    ->select($selectColumns)
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
                
                // C9.2: Only include is_edit_hidden if column exists (defensive check for migration)
                if (\Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
                    $insertData['is_edit_hidden'] = $isEditHidden !== null ? $isEditHidden : ($tenantOverride ? (bool) ($tenantOverride->is_edit_hidden ?? false) : false);
                } elseif ($isEditHidden !== null) {
                    // Fallback: If column doesn't exist yet, log warning but don't fail
                    \Log::warning('[TenantMetadataRegistryController] is_edit_hidden column does not exist yet. Migration may not have run.', [
                        'field_id' => $field,
                        'category_id' => $categoryId,
                    ]);
                }
                
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
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
     * Suppress a field for a category.
     *
     * POST /api/tenant/metadata/fields/{field}/categories/{category}/suppress
     */
    public function suppressForCategory(int $field, int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Verify category belongs to tenant
        $categoryModel = Category::where('id', $category)
            ->whereHas('brand', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->id);
            })
            ->first();

        if (!$categoryModel) {
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        // Verify category belongs to tenant
        $categoryModel = Category::where('id', $category)
            ->whereHas('brand', function ($query) use ($tenant) {
                $query->where('tenant_id', $tenant->id);
            })
            ->first();

        if (!$categoryModel) {
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (!$canView) {
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
     */
    public function copyCategoryFrom(int $targetCategory, int $sourceCategory): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $sourceModel = Category::where('id', $sourceCategory)
            ->where('tenant_id', $tenant->id)
            ->first();
        $targetModel = Category::where('id', $targetCategory)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$sourceModel || !$targetModel) {
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $categoryModel = Category::where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$categoryModel) {
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
     */
    public function getApplyToOtherBrandsTargets(int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $categoryModel = Category::where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$categoryModel) {
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
     */
    public function applyToOtherBrands(int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $categoryModel = Category::where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        try {
            $results = $this->visibilityService->applyCategoryVisibilityToOtherBrands($tenant, $categoryModel);
            $count = count($results);
            return response()->json([
                'success' => true,
                'message' => $count > 0
                    ? "Settings applied to {$count} categor" . ($count === 1 ? 'y' : 'ies') . " in other brands."
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $profileModel = MetadataVisibilityProfile::where('id', $profile)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$profileModel) {
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
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

        if (!$category) {
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

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            abort(403, 'You do not have permission to manage metadata visibility.');
        }

        $profileModel = MetadataVisibilityProfile::where('id', $profile)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$profileModel) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
        ]);

        $category = Category::where('id', $validated['category_id'])
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$category) {
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
