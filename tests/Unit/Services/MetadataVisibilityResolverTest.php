<?php

namespace Tests\Unit\Services;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SystemCategory;
use App\Models\Tenant;
use App\Services\MetadataVisibilityResolver;
use App\Services\SystemMetadataVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Metadata Visibility Resolver Test
 *
 * Phase C2: Tests for centralized category suppression filtering.
 *
 * These tests ensure that:
 * - Suppressed fields are filtered out consistently
 * - Unsuppressed fields appear everywhere they should
 * - No behavior regressions occur
 *
 * @see MetadataVisibilityResolver
 */
class MetadataVisibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    protected MetadataVisibilityResolver $resolver;
    protected SystemMetadataVisibilityService $visibilityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visibilityService = new SystemMetadataVisibilityService();
        $this->resolver = new MetadataVisibilityResolver($this->visibilityService);
    }

    /**
     * Test that suppressed fields are filtered out.
     */
    public function test_suppressed_fields_are_filtered_out(): void
    {
        // Create tenant, brand, and system category
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $systemCategory = SystemCategory::create([
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 0,
            'version' => 1,
        ]);

        // Create brand category linked to system category
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'system_category_id' => $systemCategory->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Logos',
            'slug' => 'logos',
            'is_system' => true,
            'is_private' => false,
            'is_locked' => false,
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create another field that is not suppressed
        $fieldId2 = DB::table('metadata_fields')->insertGetId([
            'key' => 'color_palette',
            'system_label' => 'Color Palette',
            'type' => 'multiselect',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Suppress photo_type for Logos category
        $this->visibilityService->suppressForCategory($fieldId, $systemCategory->id);

        // Create test fields array
        $fields = [
            [
                'field_id' => $fieldId,
                'key' => 'photo_type',
                'display_label' => 'Photo Type',
                'type' => 'select',
            ],
            [
                'field_id' => $fieldId2,
                'key' => 'color_palette',
                'display_label' => 'Color Palette',
                'type' => 'multiselect',
            ],
        ];

        // Filter fields
        $filtered = $this->resolver->filterVisibleFields($fields, $category);

        // Assert suppressed field is filtered out
        $this->assertCount(1, $filtered, 'Should return only one field');
        $this->assertEquals($fieldId2, $filtered[0]['field_id'], 'Should return unsuppressed field');
        $this->assertEquals('color_palette', $filtered[0]['key'], 'Should return color_palette field');
    }

    /**
     * Test that unsuppressed fields appear.
     */
    public function test_unsuppressed_fields_appear(): void
    {
        // Create tenant, brand, and system category
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $systemCategory = SystemCategory::create([
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 0,
            'version' => 1,
        ]);

        // Create brand category linked to system category
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'system_category_id' => $systemCategory->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Photography',
            'slug' => 'photography',
            'is_system' => true,
            'is_private' => false,
            'is_locked' => false,
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test fields array
        $fields = [
            [
                'field_id' => $fieldId,
                'key' => 'photo_type',
                'display_label' => 'Photo Type',
                'type' => 'select',
            ],
        ];

        // Filter fields (no suppression)
        $filtered = $this->resolver->filterVisibleFields($fields, $category);

        // Assert field appears
        $this->assertCount(1, $filtered, 'Should return one field');
        $this->assertEquals($fieldId, $filtered[0]['field_id'], 'Should return photo_type field');
    }

    /**
     * Test that fields appear when no category is provided.
     */
    public function test_fields_appear_without_category(): void
    {
        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test fields array
        $fields = [
            [
                'field_id' => $fieldId,
                'key' => 'photo_type',
                'display_label' => 'Photo Type',
                'type' => 'select',
            ],
        ];

        // Filter fields without category
        $filtered = $this->resolver->filterVisibleFields($fields, null);

        // Assert field appears
        $this->assertCount(1, $filtered, 'Should return one field');
        $this->assertEquals($fieldId, $filtered[0]['field_id'], 'Should return photo_type field');
    }

    /**
     * Test that fields appear when category has no system_category_id.
     */
    public function test_fields_appear_with_custom_category(): void
    {
        // Create tenant and brand
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create custom category (no system_category_id)
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'system_category_id' => null, // Custom category
            'asset_type' => AssetType::ASSET,
            'name' => 'Custom Category',
            'slug' => 'custom-category',
            'is_system' => false,
            'is_private' => false,
            'is_locked' => false,
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test fields array
        $fields = [
            [
                'field_id' => $fieldId,
                'key' => 'photo_type',
                'display_label' => 'Photo Type',
                'type' => 'select',
            ],
        ];

        // Filter fields
        $filtered = $this->resolver->filterVisibleFields($fields, $category);

        // Assert field appears (custom categories don't have suppression)
        $this->assertCount(1, $filtered, 'Should return one field');
        $this->assertEquals($fieldId, $filtered[0]['field_id'], 'Should return photo_type field');
    }

    /**
     * Test that isFieldVisible works correctly.
     */
    public function test_is_field_visible(): void
    {
        // Create tenant, brand, and system category
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $systemCategory = SystemCategory::create([
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 0,
            'version' => 1,
        ]);

        // Create brand category linked to system category
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'system_category_id' => $systemCategory->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Logos',
            'slug' => 'logos',
            'is_system' => true,
            'is_private' => false,
            'is_locked' => false,
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $field = [
            'field_id' => $fieldId,
            'key' => 'photo_type',
            'display_label' => 'Photo Type',
            'type' => 'select',
        ];

        // Test unsuppressed field
        $this->assertTrue($this->resolver->isFieldVisible($field, $category), 'Unsuppressed field should be visible');

        // Suppress field
        $this->visibilityService->suppressForCategory($fieldId, $systemCategory->id);

        // Test suppressed field
        $this->assertFalse($this->resolver->isFieldVisible($field, $category), 'Suppressed field should not be visible');
    }
}
