<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tenant;
use App\Services\TenantMetadataFieldService;
use App\Services\TenantMetadataRegistryService;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return Inertia::render('Tenant/MetadataRegistry/Index', [
            'registry' => $registry,
            'categories' => $categories,
            'canManageVisibility' => $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage'),
            'canManageFields' => $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage'),
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

        // Set visibility override
        $this->visibilityService->setFieldVisibility($tenant, $field, $validated);

        // Handle is_primary as category-scoped override (stored in metadata_field_visibility table)
        // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
        // A field may be primary in Photography but secondary in Logos.
        if (isset($validated['is_primary']) && isset($validated['category_id'])) {
            $categoryId = $validated['category_id'];
            $brand = app('brand');
            
            // Verify category belongs to tenant and brand
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->first();
            
            if (!$category) {
                return response()->json(['error' => 'Category not found or does not belong to tenant/brand'], 404);
            }
            
            // Get or create category-level visibility override
            $existing = \DB::table('metadata_field_visibility')
                ->where('metadata_field_id', $field)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('category_id', $categoryId)
                ->first();
            
            if ($existing) {
                // Update existing category override
                \DB::table('metadata_field_visibility')
                    ->where('id', $existing->id)
                    ->update([
                        'is_primary' => $validated['is_primary'],
                        'updated_at' => now(),
                    ]);
            } else {
                // Create new category override for is_primary
                // Inherit other visibility flags from tenant-level override if exists
                $tenantOverride = \DB::table('metadata_field_visibility')
                    ->where('metadata_field_id', $field)
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('brand_id')
                    ->whereNull('category_id')
                    ->first();
                
                \DB::table('metadata_field_visibility')->insert([
                    'metadata_field_id' => $field,
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand->id,
                    'category_id' => $categoryId,
                    'is_hidden' => $tenantOverride ? (bool) $tenantOverride->is_hidden : false,
                    'is_upload_hidden' => $tenantOverride ? (bool) $tenantOverride->is_upload_hidden : false,
                    'is_filter_hidden' => $tenantOverride ? (bool) $tenantOverride->is_filter_hidden : false,
                    'is_primary' => $validated['is_primary'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
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

        $suppressedCategoryIds = $this->visibilityService->getSuppressedCategories($tenant, $field);
        
        // Get category-specific overrides (including is_primary)
        // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
        $brand = app('brand');
        $categoryOverrides = $this->visibilityService->getCategoryOverrides($tenant, $field, $brand?->id);

        return response()->json([
            'suppressed_category_ids' => $suppressedCategoryIds,
            'category_overrides' => $categoryOverrides, // Keyed by category_id, includes is_primary
        ]);
    }
}
