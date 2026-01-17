<?php

namespace Tests\Unit\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Services\MetadataSchemaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Metadata Schema Resolver Test
 *
 * Phase 1.5 – Step 4: Tests for metadata schema resolution.
 *
 * These tests LOCK inheritance behavior permanently.
 * Any changes to resolver behavior must update these tests intentionally.
 *
 * @see docs/PHASE_1_5_METADATA_SCHEMA.md
 */
class MetadataSchemaResolverTest extends TestCase
{
    use RefreshDatabase;

    protected MetadataSchemaResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MetadataSchemaResolver();
    }

    /**
     * Test 1: System-only fields, no overrides
     * Returns system defaults, field visible, options visible
     */
    public function test_system_only_fields_no_overrides(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
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

        // Create options
        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option1',
            'system_label' => 'Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option2',
            'system_label' => 'Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Should return one field');
        $field = $schema['fields'][0];
        $this->assertEquals($fieldId, $field['field_id']);
        $this->assertEquals('test_field', $field['key']);
        $this->assertEquals('Test Field', $field['display_label']);
        $this->assertTrue($field['is_visible'], 'Field should be visible');
        $this->assertTrue($field['is_upload_visible'], 'Field should be visible on upload');
        $this->assertTrue($field['is_filterable'], 'Field should be filterable');
        $this->assertCount(2, $field['options'], 'Should have two options');
        $this->assertEquals('option1', $field['options'][0]['value']);
        $this->assertEquals('option2', $field['options'][1]['value']);
    }

    /**
     * Test 2: Tenant-level override hides field
     * Field excluded entirely
     */
    public function test_tenant_level_override_hides_field(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create tenant-level override hiding the field
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(0, $schema['fields'], 'Field should be excluded');
    }

    /**
     * Test 3: Brand-level override re-enables field
     * Field visible again, brand override wins
     */
    public function test_brand_level_override_re_enables_field(): void
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

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides field
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Brand-level override re-enables field
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, $brand->id, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be visible');
        $field = $schema['fields'][0];
        $this->assertEquals($fieldId, $field['field_id']);
        $this->assertTrue($field['is_visible'], 'Field should be visible (brand override wins)');
    }

    /**
     * Test 4: Category-level override hides field
     * Category override wins
     */
    public function test_category_level_override_hides_field(): void
    {
        // Create tenant, brand, and category
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => 'image',
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_system' => false,
            'is_private' => false,
            'is_locked' => false,
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Brand-level override shows field
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Category-level override hides field
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'is_hidden' => true,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, $brand->id, $category->id, 'image');

        // Assertions
        $this->assertCount(0, $schema['fields'], 'Field should be hidden (category override wins)');
    }

    /**
     * Test 5: Upload visibility suppressed
     * Field visible in schema, field excluded from upload schema (flag)
     */
    public function test_upload_visibility_suppressed(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field (upload visible by default)
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true, // Default: visible on upload
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides from upload
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => true, // Hidden from upload
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be visible');
        $field = $schema['fields'][0];
        $this->assertTrue($field['is_visible'], 'Field should be visible');
        $this->assertFalse($field['is_upload_visible'], 'Field should be hidden from upload');
    }

    /**
     * Test 6: Filter visibility suppressed
     * Field visible, field excluded from filters
     */
    public function test_filter_visibility_suppressed(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field (filterable by default)
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true, // Default: filterable
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides from filters
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => true, // Hidden from filters
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be visible');
        $field = $schema['fields'][0];
        $this->assertTrue($field['is_visible'], 'Field should be visible');
        $this->assertFalse($field['is_filterable'], 'Field should be hidden from filters');
    }

    /**
     * Test 7: Option hidden at tenant level
     * Option excluded
     */
    public function test_option_hidden_at_tenant_level(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create options
        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option1',
            'system_label' => 'Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option2',
            'system_label' => 'Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides option1
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be visible');
        $field = $schema['fields'][0];
        $this->assertCount(1, $field['options'], 'Should have one option');
        $this->assertEquals('option2', $field['options'][0]['value'], 'Only option2 should be visible');
    }

    /**
     * Test 8: Option re-enabled at brand level
     * Option included
     */
    public function test_option_re_enabled_at_brand_level(): void
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

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create options
        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option1',
            'system_label' => 'Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option2',
            'system_label' => 'Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides option1
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Brand-level override re-enables option1
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => null,
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, $brand->id, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be visible');
        $field = $schema['fields'][0];
        $this->assertCount(2, $field['options'], 'Should have both options');
        $optionValues = array_column($field['options'], 'value');
        $this->assertContains('option1', $optionValues, 'Option1 should be visible (brand override wins)');
        $this->assertContains('option2', $optionValues, 'Option2 should be visible');
    }

    /**
     * Test 9: applies_to mismatch
     * Field excluded
     */
    public function test_applies_to_mismatch(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field for 'video' only
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'video', // Only applies to video
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema for 'image' asset type
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(0, $schema['fields'], 'Field should be excluded (applies_to mismatch)');

        // Resolve schema for 'video' asset type
        $schema = $this->resolver->resolve($tenant->id, null, null, 'video');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be included for video');
    }

    /**
     * Test 10: Field visible but all options hidden
     * Field included, options array empty
     */
    public function test_field_visible_but_all_options_hidden(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create system metadata field
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'test_field',
            'system_label' => 'Test Field',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null,
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create options
        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option1',
            'system_label' => 'Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => 'option2',
            'system_label' => 'Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides all options
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option2Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, null, null, 'image');

        // Assertions
        $this->assertCount(1, $schema['fields'], 'Field should be visible');
        $field = $schema['fields'][0];
        $this->assertTrue($field['is_visible'], 'Field should be visible');
        $this->assertCount(0, $field['options'], 'Options array should be empty');
    }

    /**
     * Snapshot test: Full resolution with tenant + brand + category
     * Locks the complete inheritance behavior
     */
    public function test_snapshot_full_resolution(): void
    {
        // Create tenant, brand, and category
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => 'image',
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_system' => false,
            'is_private' => false,
            'is_locked' => false,
        ]);

        // Create multiple metadata fields
        $field1Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_all',
            'system_label' => 'Field for All',
            'type' => 'text',
            'applies_to' => 'all',
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

        $field2Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_image',
            'system_label' => 'Field for Image',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => false,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => false,
            'is_internal_only' => true,
            'group_key' => 'technical',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create options for field2
        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $field2Id,
            'value' => 'opt1',
            'system_label' => 'Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $field2Id,
            'value' => 'opt2',
            'system_label' => 'Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level overrides
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $field1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => true,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Brand-level overrides
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $field1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => false, // Re-enable upload visibility
            'is_filter_hidden' => true, // Hide from filters
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Category-level overrides
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option1Id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'is_hidden' => false, // Re-enable option1 at category level
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve schema
        $schema = $this->resolver->resolve($tenant->id, $brand->id, $category->id, 'image');

        // Snapshot assertions - any change must be intentional
        $this->assertCount(2, $schema['fields'], 'Should have two fields');

        // Field 1: field_all
        $field1 = collect($schema['fields'])->firstWhere('key', 'field_all');
        $this->assertNotNull($field1, 'field_all should exist');
        $this->assertEquals('Field for All', $field1['display_label']);
        $this->assertTrue($field1['is_visible']);
        $this->assertTrue($field1['is_upload_visible'], 'Brand override re-enables upload visibility');
        $this->assertFalse($field1['is_filterable'], 'Brand override hides from filters');
        $this->assertEquals('creative', $field1['group_key']);

        // Field 2: field_image
        $field2 = collect($schema['fields'])->firstWhere('key', 'field_image');
        $this->assertNotNull($field2, 'field_image should exist');
        $this->assertEquals('Field for Image', $field2['display_label']);
        $this->assertTrue($field2['is_visible']);
        $this->assertFalse($field2['is_upload_visible'], 'System default: not upload visible');
        $this->assertFalse($field2['is_filterable'], 'System default: not filterable');
        $this->assertTrue($field2['is_internal_only']);
        $this->assertCount(2, $field2['options'], 'Should have both options (category re-enables option1)');
        $optionValues = array_column($field2['options'], 'value');
        $this->assertContains('opt1', $optionValues, 'Option1 should be visible (category override wins)');
        $this->assertContains('opt2', $optionValues, 'Option2 should be visible');
    }
}
