<?php

namespace Tests\Feature;

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
 * Regression test to ensure system automated filters (like dominant_color_bucket)
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

        // Create a category (may or may not have pivot record for dominant_color_bucket)
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => 'image',
            'is_system' => false,
        ]);

        // Ensure metadata fields are seeded
        $this->artisan('db:seed', ['--class' => 'MetadataFieldsSeeder']);
    }

    /**
     * Test: dominant_color_bucket always appears in filter schema when enabled
     *
     * This test verifies that system automated filters are included in the
     * filterable schema even when:
     * - is_internal_only = true
     * - category has no pivot record (category_field doesn't exist)
     * - field is hidden from edit (show_on_edit = false)
     */
    public function test_dominant_color_bucket_always_appears_in_filter_schema_when_enabled(): void
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

        // Find dominant_color_bucket in filterable fields
        $bucketField = collect($filterableFields)->first(function ($field) {
            return ($field['field_key'] ?? null) === 'dominant_color_bucket';
        });

        // Assert: dominant_color_bucket should appear in filterable fields
        $this->assertNotNull(
            $bucketField,
            'dominant_color_bucket should appear in filterable schema even when is_internal_only=true'
        );

        // Assert: field properties are correct
        $this->assertEquals('dominant_color_bucket', $bucketField['field_key']);
        $this->assertTrue($bucketField['is_filterable'] ?? false, 'Field should be filterable');

        // Verify the field exists in the resolved schema
        $schemaField = collect($schema['fields'])->first(function ($field) {
            return ($field['key'] ?? null) === 'dominant_color_bucket';
        });

        $this->assertNotNull($schemaField, 'dominant_color_bucket should exist in resolved schema');
        $this->assertTrue(
            $schemaField['is_internal_only'] ?? false,
            'Field should be marked as is_internal_only'
        );
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
        // Ensure no category_field pivot exists for dominant_color_bucket
        $bucketFieldId = DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
            ->value('id');

        if ($bucketFieldId) {
            DB::table('category_fields')
                ->where('category_id', $this->category->id)
                ->where('metadata_field_id', $bucketFieldId)
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

        // Assert: dominant_color_bucket should still appear
        $bucketField = collect($filterableFields)->first(function ($field) {
            return ($field['field_key'] ?? null) === 'dominant_color_bucket';
        });

        $this->assertNotNull(
            $bucketField,
            'dominant_color_bucket should appear even without category_field pivot'
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
        // Verify dominant_color_bucket is hidden from edit
        $bucketField = DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
            ->first();

        $this->assertNotNull($bucketField, 'dominant_color_bucket field should exist');

        // Update to ensure show_on_edit is false (should already be)
        DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
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

        // Assert: dominant_color_bucket should still appear
        $bucketFieldInFilters = collect($filterableFields)->first(function ($field) {
            return ($field['field_key'] ?? null) === 'dominant_color_bucket';
        });

        $this->assertNotNull(
            $bucketFieldInFilters,
            'dominant_color_bucket should appear in filters even when show_on_edit=false'
        );
    }
}
