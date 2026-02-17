<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * System Automated Filter Schema Test
 *
 * Regression test to ensure system automated filters (like dominant_hue_group)
 * always appear in the filter schema, even when:
 * - is_internal_only = true
 * - category has no pivot record
 * - field is hidden from edit
 *
 * This prevents system query-only fields from being incorrectly excluded.
 */
class SystemAutomatedFilterSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create test brand
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create test user
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        // Create a category (may or may not have pivot record for dominant_hue_group)
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        // Ensure metadata fields are seeded
        $this->artisan('db:seed', ['--class' => 'MetadataFieldsSeeder']);
    }

    /**
     * Test: dominant_hue_group always appears in filter schema when enabled
     *
     * This test verifies that system automated filters are included in the
     * filterable schema even when:
     * - is_internal_only = true
     * - category has no pivot record (category_field doesn't exist)
     * - field is hidden from edit (show_on_edit = false)
     */
    public function test_dominant_hue_group_always_appears_in_filter_schema_when_enabled(): void
    {
        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        // Resolve schema for image assets
        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        // Get filterable fields
        $filterableFields = $filterService->getFilterableFields($schema, $this->category, $this->tenant);

        // Find dominant_hue_group in filterable fields
        $hueField = collect($filterableFields)->first(function ($field) {
            return ($field['field_key'] ?? null) === 'dominant_hue_group';
        });

        // Assert: dominant_hue_group should appear in filterable fields
        $this->assertNotNull(
            $hueField,
            'dominant_hue_group should appear in filterable schema even when is_internal_only=true'
        );

        // Assert: field properties are correct
        $this->assertEquals('dominant_hue_group', $hueField['field_key']);
        $this->assertTrue($hueField['is_filterable'] ?? false, 'Field should be filterable');

        // Verify the field exists in the resolved schema
        $schemaField = collect($schema['fields'])->first(function ($field) {
            return ($field['key'] ?? null) === 'dominant_hue_group';
        });

        $this->assertNotNull($schemaField, 'dominant_hue_group should exist in resolved schema');
        $this->assertEquals(
            'automatic',
            $schemaField['population_mode'] ?? null,
            'Field should have population_mode=automatic'
        );
        $this->assertTrue(
            $schemaField['show_in_filters'] ?? false,
            'Field should have show_in_filters=true'
        );
    }

    /**
     * Test: System automated filters appear even without category pivot
     *
     * This test verifies that system automated filters don't require
     * category_field pivot records to appear in the filter schema.
     */
    public function test_system_automated_filters_appear_without_category_pivot(): void
    {
        // Ensure no category_field pivot exists for dominant_hue_group
        $hueFieldId = DB::table('metadata_fields')
            ->where('key', 'dominant_hue_group')
            ->value('id');

        if ($hueFieldId) {
            DB::table('category_fields')
                ->where('category_id', $this->category->id)
                ->where('metadata_field_id', $hueFieldId)
                ->delete();
        }

        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        // Resolve schema
        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        // Get filterable fields
        $filterableFields = $filterService->getFilterableFields($schema, $this->category, $this->tenant);

        // Assert: dominant_hue_group should still appear
        $hueField = collect($filterableFields)->first(function ($field) {
            return ($field['field_key'] ?? null) === 'dominant_hue_group';
        });

        $this->assertNotNull(
            $hueField,
            'dominant_hue_group should appear even without category_field pivot'
        );
    }

    /**
     * Test: System automated filters appear even when hidden from edit
     *
     * This test verifies that show_on_edit=false doesn't prevent
     * system automated filters from appearing in the filter schema.
     */
    public function test_system_automated_filters_appear_when_hidden_from_edit(): void
    {
        // Verify dominant_hue_group is hidden from edit
        $hueField = DB::table('metadata_fields')
            ->where('key', 'dominant_hue_group')
            ->first();

        $this->assertNotNull($hueField, 'dominant_hue_group field should exist');

        // Update to ensure show_on_edit is false (should already be)
        DB::table('metadata_fields')
            ->where('key', 'dominant_hue_group')
            ->update(['show_on_edit' => false]);

        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        // Resolve schema
        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        // Get filterable fields
        $filterableFields = $filterService->getFilterableFields($schema, $this->category, $this->tenant);

        // Assert: dominant_hue_group should still appear
        $hueFieldInFilters = collect($filterableFields)->first(function ($field) {
            return ($field['field_key'] ?? null) === 'dominant_hue_group';
        });

        $this->assertNotNull(
            $hueFieldInFilters,
            'dominant_hue_group should appear in filters even when show_on_edit=false'
        );
    }
}
