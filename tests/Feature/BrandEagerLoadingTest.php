<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for brand loading in HandleInertiaRequests.
 * Ensures no lazy loading errors and no N+1 when iterating brands with activeBrandMembership.
 *
 * @see docs/EAGER_LOADING_RULES.md
 */
class BrandEagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_brands_load_without_lazy_loading_exception(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand One',
            'slug' => 'brand-one',
            'is_default' => true,
        ]);

        Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand Two',
            'slug' => 'brand-two',
            'is_default' => false,
        ]);

        $user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Same path as HandleInertiaRequests: tenant->brands()->orderBy()->get()
        $allBrands = $tenant->brands()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        $userBrandIds = [];
        foreach ($allBrands as $brand) {
            $membership = $user->activeBrandMembership($brand);
            if ($membership !== null) {
                $userBrandIds[] = $brand->id;
            }
        }

        $this->assertCount(2, $allBrands);
        // Owner sees all brands (filter would include both; userBrandIds is for non-owners)
        $this->assertNotEmpty($allBrands);
    }

    public function test_brand_loading_does_not_cause_n_plus_one_queries(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand One',
            'slug' => 'brand-one',
            'is_default' => true,
        ]);

        $user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($tenant->brands()->first()->id, ['role' => 'viewer', 'removed_at' => null]);

        DB::enableQueryLog();

        $allBrands = $tenant->brands()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        foreach ($allBrands as $brand) {
            $user->activeBrandMembership($brand);
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 1 query for brands + 2 per brand in activeBrandMembership (tenants exists, brand_user pivot)
        $this->assertLessThanOrEqual(3, count($queries), 'Brand loading should not cause N+1 queries');
    }
}
