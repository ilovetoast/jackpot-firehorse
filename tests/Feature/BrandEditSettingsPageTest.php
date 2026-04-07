<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BrandEditSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_edit_page_renders(): void
    {
        $tenant = Tenant::create(['name' => 'Brand Settings Co', 'slug' => 'brand-settings-co']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand Settings Brand',
            'slug' => 'brand-settings-brand',
        ]);

        Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Assets',
            'slug' => 'assets',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $user = User::create([
            'email' => 'brand-settings@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'B',
            'last_name' => 'S',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('brands.edit', ['brand' => $brand->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Brands/Edit'));
    }

    /** Library metadata UI moved to Manage; routes must remain valid. */
    public function test_manage_routes_still_registered(): void
    {
        $this->assertTrue(Route::has('manage.categories'));
        $this->assertTrue(Route::has('manage.structure'));
        $this->assertTrue(Route::has('manage.fields'));
        $this->assertTrue(Route::has('manage.tags'));
    }

    public function test_brand_tags_settings_section_component_removed(): void
    {
        $this->assertFileDoesNotExist(
            resource_path('js/Components/brand/BrandTagsSettingsSection.jsx'),
            'Brand Settings no longer embeds tag management; the component should not exist as an orphan.'
        );
    }
}
