<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SystemCategory;
use App\Models\Tenant;
use App\Services\CategoryService;
use App\Services\SystemCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCatalogAndVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_to_brand_only_adds_auto_provision_templates(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b',
        ]);

        SystemCategory::create([
            'name' => 'Core',
            'slug' => 'core',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'auto_provision' => true,
            'sort_order' => 0,
            'version' => 1,
        ]);

        SystemCategory::create([
            'name' => 'Catalog Only',
            'slug' => 'catalog-only',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'auto_provision' => false,
            'sort_order' => 1,
            'version' => 1,
        ]);

        app(SystemCategoryService::class)->syncToBrand($brand);

        $slugs = Category::where('brand_id', $brand->id)->pluck('slug')->all();
        $this->assertContains('core', $slugs);
        $this->assertNotContains('catalog-only', $slugs);
    }

    public function test_visible_category_cap_blocks_new_visible_custom_category(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b',
        ]);

        for ($i = 0; $i < 20; $i++) {
            Category::create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'asset_type' => AssetType::ASSET,
                'name' => "Cat {$i}",
                'slug' => "cat-{$i}",
                'is_system' => false,
                'is_locked' => false,
                'is_private' => false,
                'is_hidden' => false,
            ]);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/You can have at most 20 visible categories/');

        app(CategoryService::class)->create($tenant, $brand, [
            'name' => 'Overflow',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
        ]);
    }

    public function test_system_category_name_and_icon_cannot_be_changed_by_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'manual_plan_override' => 'starter']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b',
        ]);

        $template = SystemCategory::create([
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'auto_provision' => true,
            'sort_order' => 0,
            'version' => 1,
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'system_category_id' => $template->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Logos',
            'slug' => 'logos',
            'is_system' => true,
            'is_locked' => true,
            'is_private' => false,
            'is_hidden' => false,
            'system_version' => 1,
            'upgrade_available' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Only visibility/');

        app(CategoryService::class)->update($category, [
            'name' => 'Our Logos',
            'icon' => 'star',
        ]);
    }

    public function test_system_template_save_pushes_name_and_icon_to_brand_rows(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't2', 'manual_plan_override' => 'starter']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b2',
        ]);

        $template = SystemCategory::create([
            'name' => 'Old',
            'slug' => 'spellfix',
            'icon' => 'folder',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'auto_provision' => false,
            'sort_order' => 0,
            'version' => 1,
        ]);

        Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'system_category_id' => $template->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Old',
            'slug' => 'spellfix',
            'icon' => 'folder',
            'is_system' => true,
            'is_locked' => true,
            'is_private' => false,
            'is_hidden' => true,
            'system_version' => 1,
            'upgrade_available' => false,
        ]);

        app(SystemCategoryService::class)->updateTemplate($template, [
            'name' => 'Spelling fixed',
            'slug' => 'spellfix',
            'icon' => 'star',
            'asset_type' => AssetType::ASSET,
            'is_hidden' => false,
            'sort_order' => 0,
            'auto_provision' => false,
        ]);

        $row = Category::where('brand_id', $brand->id)->where('slug', 'spellfix')->first();
        $this->assertSame('Spelling fixed', $row->name);
        $this->assertSame('star', $row->icon);
    }

    public function test_private_category_still_requires_paid_plan(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'manual_plan_override' => 'starter']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/paid plan/');

        app(CategoryService::class)->create($tenant, $brand, [
            'name' => 'Internal',
            'asset_type' => AssetType::ASSET,
            'is_private' => true,
            'access_rules' => [
                ['type' => 'role', 'role' => 'admin'],
            ],
        ]);
    }
}
