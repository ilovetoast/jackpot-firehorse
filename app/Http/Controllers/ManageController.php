<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryVisibilityLimitService;
use App\Services\MetadataAnalyticsService;
use App\Services\PlanService;
use App\Services\TenantMetadataRegistryService;
use App\Support\Metadata\CategoryTypeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand library management workspace (categories + fields, tags, values).
 */
class ManageController extends Controller
{
    /**
     * Active categories for the brand (same payload as registry / structure UI).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function categoriesPayloadForBrand(Brand $brand): Collection
    {
        $categories = Category::query()
            ->where('brand_id', $brand->id)
            ->with(['brand', 'accessRules'])
            ->active()
            ->ordered()
            ->orderBy('name')
            ->get();

        $systemTemplateExistsByCategoryId = Category::templateExistsLookupForCategories($categories);

        return $categories
            ->filter(function (Category $category) use ($systemTemplateExistsByCategoryId) {
                if (! $category->id || $category->deleted_at) {
                    return false;
                }
                if ($category->is_system) {
                    return $systemTemplateExistsByCategoryId[$category->id] ?? false;
                }

                return true;
            })
            ->map(function (Category $category) {
                $accessRules = [];
                if ($category->is_private && ! $category->is_system) {
                    $accessRules = $category->accessRules->map(function ($rule) {
                        if ($rule->access_type === 'role') {
                            return ['type' => 'role', 'role' => $rule->role];
                        }
                        if ($rule->access_type === 'user') {
                            return ['type' => 'user', 'user_id' => $rule->user_id];
                        }

                        return null;
                    })->filter()->values()->toArray();
                }

                $slug = $category->slug ?? Str::slug($category->name);

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $slug,
                    'type_field' => CategoryTypeResolver::resolve($slug),
                    'brand_id' => $category->brand_id,
                    'brand_name' => $category->brand?->name ?? null,
                    'asset_type' => $category->asset_type?->value ?? 'asset',
                    'is_system' => $category->is_system,
                    'is_hidden' => $category->is_hidden,
                    'is_private' => $category->is_private,
                    'access_rules' => $accessRules,
                    'sort_order' => $category->sort_order,
                    'system_version' => $category->system_version,
                    'upgrade_available' => $category->upgrade_available ?? false,
                    'deletion_available' => $category->deletion_available ?? false,
                    'ebi_enabled' => $category->isEbiEnabled(),
                    'ai_use_library_references' => $category->is_system
                        ? false
                        : (bool) data_get($category->settings, 'ai_use_library_references', false),
                ];
            })
            ->values();
    }

    /**
     * Manage → Categories: folders (order, visibility, catalog) and metadata fields per folder (unified hub).
     */
    public function categories(Request $request, TenantMetadataRegistryService $registryService): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view categories.');
        }

        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $brand || $brand->tenant_id !== $tenant->id) {
            abort(404, 'No active brand selected.');
        }

        $categories = $this->categoriesPayloadForBrand($brand);
        $registry = $registryService->getRegistry($tenant);

        $visibilityLimit = app(CategoryVisibilityLimitService::class);
        $visibleByAssetType = $visibilityLimit->limitsPayloadForBrand($brand);
        $currentCount = $brand->categories()->custom()->count();

        $planService = app(PlanService::class);
        $limits = $planService->getPlanLimits($tenant);
        $maxCustomFields = $limits['max_custom_metadata_fields'] ?? 0;

        $currentCustomFieldsCount = DB::table('metadata_fields')
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->count();

        $canCreateCustomField = $maxCustomFields === 0 || $currentCustomFieldsCount < $maxCustomFields;

        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);

        $fieldFilter = $request->query('filter');
        $fieldFilterNormalized = $fieldFilter === 'low_coverage' ? 'low_coverage' : null;

        $lowCoverageFieldKeys = [];
        if ($fieldFilterNormalized === 'low_coverage') {
            $coveragePayload = app(MetadataAnalyticsService::class)->getAnalytics(
                $tenant->id,
                $brand->id,
                null,
                null,
                null,
                false,
                ['coverage']
            );
            foreach ($coveragePayload['coverage']['lowest_coverage_fields'] ?? [] as $row) {
                $k = $row['field_key'] ?? null;
                if (is_string($k) && $k !== '') {
                    $lowCoverageFieldKeys[] = $k;
                }
            }
            $lowCoverageFieldKeys = array_values(array_unique($lowCoverageFieldKeys));
        }

        return Inertia::render('Manage/Categories', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'categories' => $categories,
            'category_limits' => [
                'current' => $currentCount,
                'max' => null,
                'can_create' => true,
                'visible_by_asset_type' => $visibleByAssetType,
            ],
            'canManageBrandCategories' => $user->hasPermissionForTenant($tenant, 'brand_categories.manage'),
            'registry' => $registry,
            'initial_category_slug' => $request->query('category'),
            'field_filter' => $fieldFilterNormalized,
            'low_coverage_field_keys' => $lowCoverageFieldKeys,
            'canManageVisibility' => $isTenantOwnerOrAdmin || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage'),
            'canManageFields' => $isTenantOwnerOrAdmin || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage'),
            'customFieldsLimit' => [
                'max' => $maxCustomFields,
                'current' => $currentCustomFieldsCount,
                'can_create' => $canCreateCustomField,
            ],
            'metadata_field_families' => config('metadata_field_families', []),
        ]);
    }

    /**
     * @deprecated Use manage.categories. Redirect preserved for bookmarks.
     */
    public function structure(Request $request): RedirectResponse
    {
        return redirect()->route('manage.categories', $request->query());
    }

    /**
     * @deprecated Field definitions and folder visibility live on manage.categories. Redirect for bookmarks.
     */
    public function fields(Request $request): RedirectResponse
    {
        return redirect()->route('manage.categories', $request->query());
    }

    public function tags(Request $request): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view tags.');
        }

        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $brand || $brand->tenant_id !== $tenant->id) {
            abort(404, 'No active brand selected.');
        }

        $tagFilter = $request->query('filter') === 'missing' ? 'missing' : null;

        $assetsMissingTagsCount = null;
        if ($tagFilter === 'missing') {
            $assetsMissingTagsCount = DB::table('assets')
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->whereNull('deleted_at')
                ->where('status', AssetStatus::VISIBLE->value)
                ->where('type', AssetType::ASSET->value)
                ->where(function ($q) {
                    $q->whereNull('intake_state')->orWhere('intake_state', 'normal');
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('asset_tags')
                        ->whereColumn('asset_tags.asset_id', 'assets.id');
                })
                ->count();
        }

        return Inertia::render('Manage/Tags', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'tag_filter' => $tagFilter,
            'assets_missing_tags_count' => $assetsMissingTagsCount,
            'can_view_assets' => $user->hasPermissionForTenant($tenant, 'asset.view'),
            'can_purge_tags' => $user->hasPermissionForTenant($tenant, 'assets.tags.delete'),
        ]);
    }

    public function values(): Response
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            abort(404, 'Tenant not found');
        }

        $canView = $user->hasPermissionForTenant($tenant, 'metadata.registry.view')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');

        if (! $canView) {
            abort(403, 'You do not have permission to view values.');
        }

        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $brand || $brand->tenant_id !== $tenant->id) {
            abort(404, 'No active brand selected.');
        }

        $canPurgeMetadataValues = $user->hasPermissionForTenant($tenant, 'metadata.bulk_edit')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')
            || $user->hasPermissionForTenant($tenant, 'metadata.fields.values.manage');

        return Inertia::render('Manage/Values', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'can_purge_metadata_values' => $canPurgeMetadataValues,
        ]);
    }
}
