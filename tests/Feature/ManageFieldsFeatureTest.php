<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MetadataAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManageFieldsFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Brand, 2: User, 3: Category}
     */
    private function tenantBrandAdminCategory(): array
    {
        $tenant = Tenant::create(['name' => 'Fields Co', 'slug' => 'fields-co']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fields Brand',
            'slug' => 'fields-brand',
        ]);
        $user = User::create([
            'email' => 'fields-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'F',
            'last_name' => 'A',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Logos',
            'slug' => 'logos-workspace',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $user, $category];
    }

    private function actingTenantBrand(User $user, Tenant $tenant, Brand $brand): self
    {
        return $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id]);
    }

    public function test_manage_fields_inertia_includes_registry_categories_and_slug_query(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandAdminCategory();

        $this->actingTenantBrand($user, $tenant, $brand)
            ->get('/app/manage/categories?category='.urlencode($category->slug))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Categories')
                ->has('brand')
                ->has('categories')
                ->has('registry')
                ->where('initial_category_slug', $category->slug)
                ->has('canManageVisibility')
                ->has('canManageFields')
                ->has('customFieldsLimit'));
    }

    public function test_manage_fields_low_coverage_filter_passes_keys_from_analytics(): void
    {
        [$tenant, $brand, $user] = $this->tenantBrandAdminCategory();

        $this->mock(MetadataAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('getAnalytics')
                ->once()
                ->andReturn([
                    'coverage' => [
                        'lowest_coverage_fields' => [
                            [
                                'field_key' => 'usage_rights',
                                'field_label' => 'Usage Rights',
                                'coverage_percentage' => 5,
                            ],
                        ],
                    ],
                ]);
        });

        $this->actingTenantBrand($user, $tenant, $brand)
            ->get('/app/manage/categories?filter=low_coverage')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Categories')
                ->where('field_filter', 'low_coverage')
                ->where('low_coverage_field_keys', ['usage_rights']));
    }

    public function test_manage_fields_without_filter_has_null_field_filter_and_empty_keys(): void
    {
        [$tenant, $brand, $user] = $this->tenantBrandAdminCategory();

        $this->actingTenantBrand($user, $tenant, $brand)
            ->get('/app/manage/categories')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('field_filter', null)
                ->where('low_coverage_field_keys', []));
    }

    public function test_field_category_visibility_patch_persists(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandAdminCategory();

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'manage_fields_test',
            'system_label' => 'Manage Fields Test',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingTenantBrand($user, $tenant, $brand)
            ->patchJson("/app/api/tenant/metadata/fields/{$fieldId}/categories/{$category->id}/visibility", [
                'is_hidden' => true,
            ])
            ->assertOk()
            ->assertJsonFragment([
                'field_id' => $fieldId,
                'category_id' => $category->id,
                'is_hidden' => true,
            ]);

        $this->assertDatabaseHas('metadata_field_visibility', [
            'metadata_field_id' => $fieldId,
            'category_id' => $category->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'is_hidden' => true,
        ]);
    }

    public function test_field_visibility_post_updates_category_scoped_upload(): void
    {
        [$tenant, $brand, $user, $category] = $this->tenantBrandAdminCategory();

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'manage_fields_upload',
            'system_label' => 'Upload Test',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingTenantBrand($user, $tenant, $brand)
            ->postJson("/app/api/tenant/metadata/fields/{$fieldId}/visibility", [
                'show_on_upload' => false,
                'category_id' => $category->id,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('metadata_field_visibility', [
            'metadata_field_id' => $fieldId,
            'category_id' => $category->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'is_upload_hidden' => true,
        ]);
    }
}
