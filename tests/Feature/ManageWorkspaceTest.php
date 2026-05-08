<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class ManageWorkspaceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private function actingWithTenantBrand(): self
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            ['name' => 'Manage Co', 'slug' => 'manage-co'],
            ['email' => 'manage-user@example.com', 'first_name' => 'M', 'last_name' => 'U']
        );

        return $this->actingAsTenantBrand($user, $tenant, $brand);
    }

    public function test_manage_categories_returns_200_with_unified_props(): void
    {
        $this->actingWithTenantBrand()
            ->get('/app/manage/categories')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Categories')
                ->has('categories')
                ->has('canManageBrandCategories')
                ->has('registry')
                ->has('customFieldsLimit'));
    }

    public function test_manage_structure_redirects_to_categories(): void
    {
        $this->actingWithTenantBrand()
            ->get('/app/manage/structure')
            ->assertRedirect(route('manage.categories'));
    }

    public function test_legacy_tenant_metadata_registry_redirects_to_manage_categories_preserving_query(): void
    {
        $response = $this->actingWithTenantBrand()
            ->get('/app/tenant/metadata/registry?category=logos&filter=low_coverage');
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/app/manage/categories', $location);
        $this->assertStringContainsString('category=logos', $location);
        $this->assertStringContainsString('filter=low_coverage', $location);
    }

    public function test_legacy_metadata_registry_maps_category_id_to_slug_in_redirect(): void
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            ['name' => 'Cat Co', 'slug' => 'cat-co'],
            ['email' => 'cat-user@example.com', 'first_name' => 'C', 'last_name' => 'U']
        );

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Test Folder',
            'slug' => 'test-folder-slug',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        $response = $this->actingAsTenantBrand($user, $tenant, $brand)
            ->get('/app/tenant/metadata/registry?category_id='.$category->id);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('category=test-folder-slug', $location);
    }

    public function test_manage_fields_returns_200_with_overview_props(): void
    {
        $this->actingWithTenantBrand()
            ->get('/app/manage/fields')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Fields')
                ->has('brand')
                ->has('categories')
                ->has('custom_fields')
                ->has('system_fields'));
    }

    public function test_manage_tags_returns_200_with_workspace_props(): void
    {
        $this->actingWithTenantBrand()
            ->get('/app/manage/tags')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Tags')
                ->where('tag_filter', null)
                ->where('assets_missing_tags_count', null)
                ->has('can_view_assets')
                ->has('can_purge_tags')
                ->has('brand'));
    }

    public function test_manage_tags_missing_filter_deep_link_sets_tag_filter_and_count(): void
    {
        $this->actingWithTenantBrand()
            ->get('/app/manage/tags?filter=missing')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Tags')
                ->where('tag_filter', 'missing')
                ->has('assets_missing_tags_count')
                ->has('can_view_assets')
                ->has('can_purge_tags'));
    }

    public function test_manage_values_returns_200_with_workspace_props(): void
    {
        $this->actingWithTenantBrand()
            ->get('/app/manage/values')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Values')
                ->has('brand')
                ->has('can_purge_metadata_values'));
    }

    public function test_manage_named_routes_resolve_under_app_prefix(): void
    {
        $this->assertSame(url('/app/manage/categories'), route('manage.categories'));
        $this->assertSame(url('/app/manage/structure'), route('manage.structure'));
        $this->assertSame(url('/app/manage/fields'), route('manage.fields'));
        $this->assertSame(url('/app/manage/tags'), route('manage.tags'));
        $tagsMissing = route('manage.tags', ['filter' => 'missing']);
        $this->assertStringStartsWith(url('/app/manage/tags'), $tagsMissing);
        $this->assertStringContainsString('filter=missing', $tagsMissing);
        $categoriesLow = route('manage.categories', ['filter' => 'low_coverage']);
        $this->assertStringStartsWith(url('/app/manage/categories'), $categoriesLow);
        $this->assertStringContainsString('filter=low_coverage', $categoriesLow);
        $this->assertSame(url('/app/manage/values'), route('manage.values'));
    }

    public function test_main_navigation_component_includes_manage_label_and_href(): void
    {
        $navSource = file_get_contents(resource_path('js/Components/AppNav.jsx'));

        $this->assertStringContainsString('Manage', $navSource);
        $this->assertStringContainsString('/app/manage/categories', $navSource);
    }
}
