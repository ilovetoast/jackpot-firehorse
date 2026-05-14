<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Filters\Contracts\FacetCountProvider;
use App\Services\Filters\Contracts\QuickFilterPersonalizationProvider;
use App\Services\Filters\Facet\AssetFacetCountService;
use App\Services\Filters\Facet\AssetMetadataFacetCountProvider;
use App\Services\Filters\Facet\CachedFacetCountProvider;
use App\Services\Filters\Facet\FilterFacetAggregationService;
use App\Services\Filters\Facet\FolderQuickFilterFacetService;
use App\Services\Filters\Facet\NullFacetCountProvider;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use App\Services\Filters\Personalization\NullQuickFilterPersonalizationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 2 architecture-seam coverage. These tests guarantee the future-phase
 * extension points (count provider, personalization provider, stub services)
 * are stable, swappable, and have safe Phase 2 defaults.
 */
class FolderQuickFilterArchitectureSeamsTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private function makeField(string $key, string $type, array $overrides = []): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId(array_merge([
            'key' => $key,
            'system_label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => $type,
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return MetadataField::query()->findOrFail($id);
    }

    private function tenantBrandCategory(string $slug): array
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Seam Co '.$slug,
            'slug' => 'seam-co-'.$slug,
            'manual_plan_override' => 'starter',
        ], ['email' => 'seam-'.$slug.'@example.com', 'first_name' => 'S', 'last_name' => 'M']);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Folder '.$slug,
            'slug' => $slug,
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $category];
    }

    public function test_default_facet_count_provider_is_the_phase5_cached_real_provider(): void
    {
        /** @var FacetCountProvider $provider */
        $provider = app(FacetCountProvider::class);
        // Phase 5 swap: the bound provider is the cached decorator wrapping
        // the real AssetMetadataFacetCountProvider. Tests that need the
        // null implementation must rebind explicitly.
        $this->assertInstanceOf(CachedFacetCountProvider::class, $provider);

        $field = $this->makeField('seam_default_count', 'select');
        // No metadata_options seeded for this field → distinct count is 0
        // (not null) under the real provider.
        $this->assertSame(0, $provider->estimateDistinctValueCount($field));
    }

    public function test_default_personalization_provider_returns_empty_lists(): void
    {
        /** @var QuickFilterPersonalizationProvider $provider */
        $provider = app(QuickFilterPersonalizationProvider::class);
        $this->assertInstanceOf(NullQuickFilterPersonalizationProvider::class, $provider);

        [$tenant] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Persona Co',
            'slug' => 'persona-co',
            'manual_plan_override' => 'starter',
        ], ['email' => 'persona@example.com', 'first_name' => 'P', 'last_name' => 'P']);

        $user = User::query()->where('email', 'persona@example.com')->firstOrFail();

        $this->assertSame([], $provider->getPinnedFilterIds($user, $tenant));
        $this->assertSame([], $provider->getRecentlyUsedFilterIds($user, $tenant));
        $this->assertSame([], $provider->getRoleDefaultFilterIds($user, $tenant));
        $this->assertSame([], $provider->getFavoriteFilterIds($user, $tenant));
    }

    public function test_max_distinct_values_for_quick_filter_reads_config(): void
    {
        /** @var FolderQuickFilterAssignmentService $service */
        $service = app(FolderQuickFilterAssignmentService::class);

        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => 250]);
        $this->assertSame(250, $service->maxDistinctValuesForQuickFilter());

        // Bad config falls back to a sane default rather than crashing.
        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => 'not-a-number']);
        $this->assertSame(100, $service->maxDistinctValuesForQuickFilter());

        // Negative values get floored at 0 so callers can rely on >= 0.
        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => -10]);
        $this->assertSame(0, $service->maxDistinctValuesForQuickFilter());
    }

    public function test_estimated_distinct_value_count_returns_null_with_legacy_null_provider(): void
    {
        // Caller-controlled rebind: with the Null provider, distinct counts
        // are unknown (null). This test now needs to opt into the null
        // implementation since the production binding is the real one.
        $this->app->singleton(FacetCountProvider::class, NullFacetCountProvider::class);
        $this->app->forgetInstance(FolderQuickFilterAssignmentService::class);

        /** @var FolderQuickFilterAssignmentService $service */
        $service = app(FolderQuickFilterAssignmentService::class);
        $field = $this->makeField('seam_est_count', 'select');

        $this->assertNull($service->estimatedDistinctValueCount($field));
    }

    public function test_is_facet_efficient_degrades_when_count_exceeds_cap(): void
    {
        $eligibility = app(FolderQuickFilterEligibilityService::class);
        $field = $this->makeField('seam_efficient', 'select');

        // Provider that pretends this filter has 25,000 distinct values — the
        // canonical "subject explosion" Phase 5 must defend against.
        $hugeCardinalityProvider = new class implements FacetCountProvider {
            public function estimateDistinctValueCount(
                MetadataField $filter,
                ?Tenant $tenant = null,
                ?Brand $brand = null,
                ?Category $folder = null,
            ): ?int {
                return 25_000;
            }

            public function countOptionsForFolder(
                MetadataField $filter,
                Tenant $tenant,
                ?Brand $brand,
                Category $folder,
                ?array $activeFilters = null,
            ): ?array {
                return null;
            }
        };

        $service = new FolderQuickFilterAssignmentService($eligibility, $hugeCardinalityProvider);

        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => 100]);

        $this->assertFalse($service->isFacetEfficient($field));
        $this->assertFalse($service->passesQualityGuards($field));
    }

    public function test_is_facet_efficient_passes_when_count_under_cap(): void
    {
        $eligibility = app(FolderQuickFilterEligibilityService::class);
        $field = $this->makeField('seam_under_cap', 'select');

        $smallCardinalityProvider = new class implements FacetCountProvider {
            public function estimateDistinctValueCount(
                MetadataField $filter,
                ?Tenant $tenant = null,
                ?Brand $brand = null,
                ?Category $folder = null,
            ): ?int {
                return 12;
            }

            public function countOptionsForFolder(
                MetadataField $filter,
                Tenant $tenant,
                ?Brand $brand,
                Category $folder,
                ?array $activeFilters = null,
            ): ?array {
                return null;
            }
        };

        $service = new FolderQuickFilterAssignmentService($eligibility, $smallCardinalityProvider);
        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => 100]);

        $this->assertTrue($service->isFacetEfficient($field));
        $this->assertTrue($service->passesQualityGuards($field));
    }

    public function test_is_facet_efficient_grants_benefit_of_the_doubt_when_count_unknown(): void
    {
        // Phase 2 default behavior: until Phase 5 ships, unknown distinct
        // counts must NOT block legitimate quick filters from rendering.
        $service = app(FolderQuickFilterAssignmentService::class);
        $field = $this->makeField('seam_unknown', 'select');

        $this->assertTrue($service->isFacetEfficient($field));
    }

    public function test_quality_guards_block_ineligible_filter_regardless_of_count(): void
    {
        $eligibility = app(FolderQuickFilterEligibilityService::class);
        $field = $this->makeField('seam_text_quality', 'text');

        $service = new FolderQuickFilterAssignmentService(
            $eligibility,
            new NullFacetCountProvider()
        );

        $this->assertFalse($service->passesQualityGuards($field));
        $this->assertFalse($service->isFacetEfficient($field));
    }

    public function test_get_personalized_filter_ids_returns_empty_shape_with_null_provider(): void
    {
        [$tenant, , $category] = $this->tenantBrandCategory('persona-shape');
        $user = User::query()->firstOrFail();

        /** @var FolderQuickFilterAssignmentService $service */
        $service = app(FolderQuickFilterAssignmentService::class);

        $result = $service->getPersonalizedFilterIds($user, $tenant, $category);

        $this->assertSame(
            ['pinned', 'recent', 'role_defaults', 'favorites'],
            array_keys($result)
        );
        foreach ($result as $key => $value) {
            $this->assertSame([], $value, "Expected empty list for {$key}");
        }
    }

    public function test_personalization_provider_can_be_swapped(): void
    {
        // Phase 6 swap: replace the Null provider with a fake that returns
        // canned data. Existing call sites must immediately see the new shape
        // without any code change in the assignment service.
        $this->app->singleton(QuickFilterPersonalizationProvider::class, function () {
            return new class implements QuickFilterPersonalizationProvider {
                public function getPinnedFilterIds(User $user, Tenant $tenant, ?Category $folder = null): array
                {
                    return [101, 202];
                }

                public function getRecentlyUsedFilterIds(
                    User $user,
                    Tenant $tenant,
                    ?Category $folder = null,
                    int $limit = 10,
                ): array {
                    return [303];
                }

                public function getRoleDefaultFilterIds(User $user, Tenant $tenant, ?Category $folder = null): array
                {
                    return [];
                }

                public function getFavoriteFilterIds(User $user, Tenant $tenant, ?Category $folder = null): array
                {
                    return [404];
                }
            };
        });

        // Force a fresh assignment service so it picks up the new binding.
        $this->app->forgetInstance(FolderQuickFilterAssignmentService::class);

        [$tenant, , $category] = $this->tenantBrandCategory('persona-swap');
        $user = User::query()->firstOrFail();

        /** @var FolderQuickFilterAssignmentService $service */
        $service = app(FolderQuickFilterAssignmentService::class);
        $result = $service->getPersonalizedFilterIds($user, $tenant, $category);

        $this->assertSame([101, 202], $result['pinned']);
        $this->assertSame([303], $result['recent']);
        $this->assertSame([], $result['role_defaults']);
        $this->assertSame([404], $result['favorites']);
    }

    public function test_asset_facet_count_service_passes_through_to_provider(): void
    {
        $passthroughProvider = new class implements FacetCountProvider {
            public int $hits = 0;

            public function estimateDistinctValueCount(
                MetadataField $filter,
                ?Tenant $tenant = null,
                ?Brand $brand = null,
                ?Category $folder = null,
            ): ?int {
                $this->hits++;

                return 7;
            }

            public function countOptionsForFolder(
                MetadataField $filter,
                Tenant $tenant,
                ?Brand $brand,
                Category $folder,
                ?array $activeFilters = null,
            ): ?array {
                return ['alpha' => 3];
            }
        };

        $service = new AssetFacetCountService($passthroughProvider);
        $field = $this->makeField('seam_passthrough', 'select');

        $this->assertSame(7, $service->estimateDistinctValueCount($field));
        $this->assertSame(1, $passthroughProvider->hits);
    }

    public function test_folder_quick_filter_facet_service_lists_with_null_counts(): void
    {
        // This test guarantees the listing shape under the legacy null
        // provider, where counts are unknown. The real provider returns
        // 0 for an unseeded field; that case is covered in
        // AssetMetadataFacetCountProviderTest.
        $this->app->singleton(FacetCountProvider::class, NullFacetCountProvider::class);
        $this->app->forgetInstance(AssetFacetCountService::class);
        $this->app->forgetInstance(FolderQuickFilterFacetService::class);
        $this->app->forgetInstance(FolderQuickFilterAssignmentService::class);

        [, , $category] = $this->tenantBrandCategory('facet-list');
        $field = $this->makeField('seam_facet_list', 'select');

        /** @var FolderQuickFilterAssignmentService $assignment */
        $assignment = app(FolderQuickFilterAssignmentService::class);
        $assignment->enableQuickFilter($category, $field, ['order' => 0]);

        /** @var FolderQuickFilterFacetService $facet */
        $facet = app(FolderQuickFilterFacetService::class);
        $list = $facet->listForFolder($category->fresh());

        $this->assertCount(1, $list);
        $this->assertSame($field->id, $list[0]['metadata_field_id']);
        $this->assertSame(0, $list[0]['order']);
        $this->assertNull($list[0]['estimated_distinct_count']);
        $this->assertTrue($list[0]['is_facet_efficient']);
    }

    public function test_filter_facet_aggregation_service_throws_until_phase5_implements_it(): void
    {
        /** @var FilterFacetAggregationService $service */
        $service = app(FilterFacetAggregationService::class);

        [$tenant, , $category] = $this->tenantBrandCategory('aggregation-stub');

        $this->expectException(RuntimeException::class);
        $service->aggregateForFolder($tenant, $category, []);
    }
}
