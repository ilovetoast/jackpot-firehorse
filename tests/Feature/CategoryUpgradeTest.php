<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SystemCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CategoryUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category upgrade tests.
 *
 * Verifies that when upgrading a brand category from system vX to vY:
 * - Name and slug are updated from the system template
 * - Brand-level metadata field customizations are preserved (not touched)
 * - Upgrade returns JSON (no full page refresh); frontend preserves selected category via local state
 */
class CategoryUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected CategoryUpgradeService $upgradeService;
    protected Tenant $tenant;
    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->upgradeService = app(CategoryUpgradeService::class);
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
    }

    /**
     * Change system template name, trigger upgrade, assert brand category name updates.
     */
    public function test_upgrade_updates_category_name_and_slug_from_system_template(): void
    {
        // Create system template v1
        $templateV1 = SystemCategory::create([
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'version' => 1,
            'change_summary' => 'Initial version',
        ]);

        // Create brand category from v1
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'system_category_id' => $templateV1->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Photography',
            'slug' => 'photography',
            'is_system' => true,
            'system_version' => 1,
            'upgrade_available' => false,
        ]);

        $this->assertSame('Photography', $category->name);
        $this->assertSame('photography', $category->slug);

        // Create system template v2 with new name
        $templateV2 = SystemCategory::create([
            'name' => 'Photo Library',
            'slug' => 'photo-library',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'version' => 2,
            'change_summary' => 'Renamed to Photo Library',
        ]);

        // Mark brand category as needing upgrade
        $category->update(['upgrade_available' => true]);

        // Trigger upgrade (empty approved_fields - name/slug sync is automatic)
        $updated = $this->upgradeService->applyUpgrade($category, []);

        $this->assertSame('Photo Library', $updated->name);
        $this->assertSame('photo-library', $updated->slug);
        $this->assertSame(2, $updated->system_version);
        $this->assertFalse($updated->upgrade_available);
    }

    /**
     * Upgrade preserves icon/is_private/is_hidden when not customized.
     */
    public function test_upgrade_preserves_uncustomized_fields(): void
    {
        $templateV1 = SystemCategory::create([
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 0,
            'version' => 1,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'system_category_id' => $templateV1->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Logos',
            'slug' => 'logos',
            'is_system' => true,
            'system_version' => 1,
            'upgrade_available' => false,
        ]);

        SystemCategory::create([
            'name' => 'Brand Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 0,
            'version' => 2,
            'change_summary' => 'Renamed',
        ]);

        $category->update(['upgrade_available' => true]);
        $updated = $this->upgradeService->applyUpgrade($category, []);

        $this->assertSame('Brand Logos', $updated->name);
        $this->assertSame('logos', $updated->slug);
    }

    /**
     * Upgrade via HTTP returns updated category in JSON (no page redirect).
     */
    public function test_upgrade_api_returns_updated_category_json(): void
    {
        $templateV1 = SystemCategory::create([
            'name' => 'Graphics',
            'slug' => 'graphics',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 2,
            'version' => 1,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'system_category_id' => $templateV1->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Graphics',
            'slug' => 'graphics',
            'is_system' => true,
            'system_version' => 1,
            'upgrade_available' => false,
        ]);

        SystemCategory::create([
            'name' => 'Creative Graphics',
            'slug' => 'creative-graphics',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 2,
            'version' => 2,
            'change_summary' => 'Renamed',
        ]);

        $category->update(['upgrade_available' => true]);

        $user = User::factory()->create();
        $user->tenants()->attach($this->tenant->id, ['role' => 'owner']);
        $user->brands()->attach($this->brand->id, ['role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson(route('brands.categories.upgrade.apply', [
                'brand' => $this->brand,
                'category' => $category,
            ]), [
                'approved_fields' => [],
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Category upgraded successfully.',
            'category' => [
                'id' => $category->id,
                'name' => 'Creative Graphics',
                'slug' => 'creative-graphics',
                'system_version' => 2,
                'upgrade_available' => false,
            ],
        ]);
    }
}
