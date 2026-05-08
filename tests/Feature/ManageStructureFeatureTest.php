<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class ManageStructureFeatureTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Brand, 2: User}
     */
    private function tenantBrandAdminUser(): array
    {
        return $this->createActivatedTenantBrandAdmin(
            ['name' => 'Struct Co', 'slug' => 'struct-co'],
            ['email' => 'struct-admin@example.com', 'first_name' => 'S', 'last_name' => 'A']
        );
    }

    public function test_manage_structure_inertia_includes_categories_and_permissions(): void
    {
        [$tenant, $brand, $user] = $this->tenantBrandAdminUser();

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->get('/app/manage/categories')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Categories')
                ->has('brand')
                ->has('categories')
                ->has('canManageBrandCategories')
                ->has('canManageVisibility'));
    }

    public function test_category_reorder_api_persists_sort_order(): void
    {
        [$tenant, $brand, $user] = $this->tenantBrandAdminUser();

        $a = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Alpha',
            'slug' => 'alpha-reorder',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);
        $b = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Beta',
            'slug' => 'beta-reorder',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 2,
        ]);

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->putJson("/app/api/brands/{$brand->id}/categories/reorder", [
                'asset_type' => 'asset',
                'categories' => [
                    ['id' => $b->id, 'sort_order' => 1],
                    ['id' => $a->id, 'sort_order' => 2],
                ],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
    }

    public function test_category_visibility_patch_persists(): void
    {
        [$tenant, $brand, $user] = $this->tenantBrandAdminUser();

        $cat = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Visible Cat',
            'slug' => 'visible-cat',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->patchJson("/app/api/brands/{$brand->id}/categories/{$cat->id}/visibility", [
                'is_hidden' => true,
            ])
            ->assertOk();

        $this->assertTrue($cat->fresh()->is_hidden);
    }

    public function test_custom_category_create_and_delete(): void
    {
        [$tenant, $brand, $user] = $this->tenantBrandAdminUser();

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson("/app/brands/{$brand->id}/categories", [
                'name' => 'Custom From Test',
                'asset_type' => 'asset',
                'icon' => 'folder',
                'is_private' => false,
            ])
            ->assertOk();

        $cat = Category::where('brand_id', $brand->id)->where('slug', 'custom-from-test')->first();
        $this->assertNotNull($cat);

        $this->actingAsTenantBrand($user, $tenant, $brand)
            ->delete(route('brands.categories.destroy', ['brand' => $brand->id, 'category' => $cat->id]))
            ->assertRedirect();

        $this->assertSoftDeleted('categories', ['id' => $cat->id]);
    }
}
