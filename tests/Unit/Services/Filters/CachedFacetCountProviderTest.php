<?php

namespace Tests\Unit\Services\Filters;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\FacetCountProvider;
use App\Services\Filters\Facet\CachedFacetCountProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

/**
 * Phase 5 — caching decorator tests.
 *
 * The decorator is the production-bound provider. These tests pin behaviour
 * we depend on:
 *  - Repeat calls within the TTL hit the cache (inner provider invoked once).
 *  - Toggling the current dimension's own filter does NOT bust the cache
 *    (the current dimension is excluded from the hash by design).
 *  - Toggling a sibling dimension DOES bust the cache (the hash differs).
 *  - Different folders / tenants / fields do not collide on cache keys.
 *  - Disabling cache via config bypasses the decorator entirely.
 */
class CachedFacetCountProviderTest extends TestCase
{
    private function makeRepo(): Repository
    {
        return new Repository(new ArrayStore());
    }

    private function fakeTenant(int $id = 1): Tenant
    {
        $t = new Tenant();
        $t->id = $id;
        $t->exists = true;

        return $t;
    }

    private function fakeBrand(int $id = 7): Brand
    {
        $b = new Brand();
        $b->id = $id;
        $b->exists = true;

        return $b;
    }

    private function fakeFolder(int $id = 9): Category
    {
        $c = new Category();
        $c->id = $id;
        $c->exists = true;

        return $c;
    }

    private function fakeField(int $id = 11, string $key = 'environment'): MetadataField
    {
        $f = new MetadataField();
        $f->id = $id;
        $f->key = $key;
        $f->exists = true;

        return $f;
    }

    private function countingInner(array $returns): FacetCountProvider
    {
        return new class($returns) implements FacetCountProvider {
            public int $estimateCalls = 0;
            public int $countOptionsCalls = 0;
            public function __construct(public array $returns) {}

            public function estimateDistinctValueCount(
                MetadataField $filter,
                ?Tenant $tenant = null,
                ?Brand $brand = null,
                ?Category $folder = null,
            ): ?int {
                $this->estimateCalls++;

                return $this->returns['estimate'] ?? 0;
            }

            public function countOptionsForFolder(
                MetadataField $filter,
                Tenant $tenant,
                ?Brand $brand,
                Category $folder,
                ?array $activeFilters = null,
            ): ?array {
                $this->countOptionsCalls++;

                return $this->returns['count_options'] ?? [];
            }
        };
    }

    public function test_repeat_calls_with_identical_context_hit_the_cache(): void
    {
        $inner = $this->countingInner(['count_options' => ['a' => 1, 'b' => 2]]);
        $cache = $this->makeRepo();
        $decorator = new CachedFacetCountProvider($inner, $cache);

        $args = [
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            ['subject_type' => ['operator' => 'equals', 'value' => ['people']]],
        ];

        $first = $decorator->countOptionsForFolder(...$args);
        $second = $decorator->countOptionsForFolder(...$args);

        $this->assertSame($first, $second);
        $this->assertSame(1, $inner->countOptionsCalls, 'Inner provider should be called once.');
    }

    public function test_toggling_current_dimensions_own_filter_does_not_bust_cache(): void
    {
        $inner = $this->countingInner(['count_options' => ['nature' => 5, 'studio' => 3]]);
        $cache = $this->makeRepo();
        $decorator = new CachedFacetCountProvider($inner, $cache);
        $field = $this->fakeField(11, 'environment');

        $decorator->countOptionsForFolder(
            $field,
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            ['environment' => ['operator' => 'equals', 'value' => ['nature']]],
        );
        $decorator->countOptionsForFolder(
            $field,
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            ['environment' => ['operator' => 'equals', 'value' => ['studio']]],
        );
        $decorator->countOptionsForFolder(
            $field,
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            null,
        );

        $this->assertSame(
            1,
            $inner->countOptionsCalls,
            'Cache key must ignore the current dimension; same context should hit once.'
        );
    }

    public function test_toggling_a_sibling_dimensions_filter_busts_the_cache(): void
    {
        $inner = $this->countingInner(['count_options' => ['nature' => 5]]);
        $cache = $this->makeRepo();
        $decorator = new CachedFacetCountProvider($inner, $cache);

        $decorator->countOptionsForFolder(
            $this->fakeField(11, 'environment'),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            ['subject_type' => ['operator' => 'equals', 'value' => ['people']]],
        );
        $decorator->countOptionsForFolder(
            $this->fakeField(11, 'environment'),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            ['subject_type' => ['operator' => 'equals', 'value' => ['product']]],
        );

        $this->assertSame(2, $inner->countOptionsCalls);
    }

    public function test_different_folders_do_not_collide_on_cache_key(): void
    {
        $inner = $this->countingInner(['count_options' => ['x' => 1]]);
        $cache = $this->makeRepo();
        $decorator = new CachedFacetCountProvider($inner, $cache);

        $decorator->countOptionsForFolder(
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(101),
            null,
        );
        $decorator->countOptionsForFolder(
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(102),
            null,
        );

        $this->assertSame(2, $inner->countOptionsCalls);
    }

    public function test_different_tenants_do_not_collide_on_cache_key(): void
    {
        $inner = $this->countingInner(['count_options' => ['x' => 1]]);
        $cache = $this->makeRepo();
        $decorator = new CachedFacetCountProvider($inner, $cache);

        $decorator->countOptionsForFolder(
            $this->fakeField(),
            $this->fakeTenant(1),
            $this->fakeBrand(),
            $this->fakeFolder(),
            null,
        );
        $decorator->countOptionsForFolder(
            $this->fakeField(),
            $this->fakeTenant(2),
            $this->fakeBrand(),
            $this->fakeFolder(),
            null,
        );

        $this->assertSame(2, $inner->countOptionsCalls);
    }

    public function test_disabling_cache_via_config_bypasses_decorator(): void
    {
        config(['categories.folder_quick_filters.facet_cache_enabled' => false]);
        $inner = $this->countingInner(['count_options' => ['a' => 1]]);
        $decorator = new CachedFacetCountProvider($inner, $this->makeRepo());

        $decorator->countOptionsForFolder(
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            null,
        );
        $decorator->countOptionsForFolder(
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
            null,
        );

        $this->assertSame(2, $inner->countOptionsCalls);
    }

    public function test_estimate_caches_independently_of_count_options(): void
    {
        $inner = $this->countingInner(['estimate' => 12, 'count_options' => ['a' => 1]]);
        $decorator = new CachedFacetCountProvider($inner, $this->makeRepo());

        $decorator->estimateDistinctValueCount(
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
        );
        $decorator->estimateDistinctValueCount(
            $this->fakeField(),
            $this->fakeTenant(),
            $this->fakeBrand(),
            $this->fakeFolder(),
        );

        $this->assertSame(1, $inner->estimateCalls);
        $this->assertSame(0, $inner->countOptionsCalls);
    }
}
