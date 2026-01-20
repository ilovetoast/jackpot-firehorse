<?php

namespace Tests\Unit\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Services\MetadataPermissionResolver;
use App\Services\MetadataSchemaResolver;
use App\Services\MetadataVisibilityResolver;
use App\Services\SystemMetadataVisibilityService;
use App\Services\UploadMetadataSchemaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Upload Metadata Schema Resolver Test
 *
 * Phase 2 – Step 2: Tests for upload metadata schema resolution.
 *
 * These tests LOCK upload-specific filtering behavior permanently.
 * Any changes to upload resolver behavior must update these tests intentionally.
 *
 * @see docs/PHASE_1_5_METADATA_SCHEMA.md
 * @see UploadMetadataSchemaResolver
 */
class UploadMetadataSchemaResolverTest extends TestCase
{
    use RefreshDatabase;

    protected UploadMetadataSchemaResolver $resolver;
    protected MetadataSchemaResolver $metadataSchemaResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metadataSchemaResolver = new MetadataSchemaResolver();
        $permissionResolver = new MetadataPermissionResolver();
        $visibilityService = new SystemMetadataVisibilityService();
        $visibilityResolver = new MetadataVisibilityResolver($visibilityService);
        $this->resolver = new UploadMetadataSchemaResolver(
            $this->metadataSchemaResolver,
            $permissionResolver,
            $visibilityResolver
        );
    }

    /**
     * Test 1: Delegation Integrity
     * Upload resolver uses MetadataSchemaResolver output
     * No duplicate inheritance logic
     */
    public function test_delegation_integrity(): void
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
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create tenant-level override
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $fieldId,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve using canonical resolver
        $canonicalSchema = $this->metadataSchemaResolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Resolve using upload resolver
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions: Upload resolver should use canonical resolver output
        $this->assertCount(1, $canonicalSchema['fields'], 'Canonical resolver should return field');
        $this->assertCount(1, $uploadSchema['groups'], 'Upload resolver should return grouped field');
        
        $uploadField = $uploadSchema['groups'][0]['fields'][0];
        $canonicalField = $canonicalSchema['fields'][0];
        
        // Verify field data matches (delegation working)
        $this->assertEquals($canonicalField['field_id'], $uploadField['field_id']);
        $this->assertEquals($canonicalField['key'], $uploadField['key']);
        $this->assertEquals($canonicalField['display_label'], $uploadField['display_label']);
    }

    /**
     * Test 2: Upload Visibility Filtering
     * Field visible but is_upload_visible = false → excluded
     * Field visible and upload_visible = true → included
     */
    public function test_upload_visibility_filtering(): void
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

        // Create field 1: upload-visible
        $field1Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_upload_visible',
            'system_label' => 'Upload Visible Field',
            'type' => 'text',
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

        // Create field 2: not upload-visible
        $field2Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_not_upload_visible',
            'system_label' => 'Not Upload Visible Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => false, // Not upload-visible
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant-level override hides field2 from upload
        DB::table('metadata_field_visibility')->insert([
            'metadata_field_id' => $field2Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => false,
            'is_upload_hidden' => true, // Hidden from upload
            'is_filter_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions
        $fieldKeys = [];
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $fieldKeys[] = $field['key'];
            }
        }

        $this->assertContains('field_upload_visible', $fieldKeys, 'Upload-visible field should be included');
        $this->assertNotContains('field_not_upload_visible', $fieldKeys, 'Not upload-visible field should be excluded');
    }

    /**
     * Test 3: Rating Field Exclusion
     * rating field present in canonical schema
     * rating field excluded from upload schema
     */
    public function test_rating_field_exclusion(): void
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

        // Create rating field
        $ratingFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'quality_rating',
            'system_label' => 'Quality Rating',
            'type' => 'rating', // Rating type
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'technical',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create regular field
        $regularFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'regular_field',
            'system_label' => 'Regular Field',
            'type' => 'text',
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

        // Verify canonical resolver includes rating field
        $canonicalSchema = $this->metadataSchemaResolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        $canonicalFieldKeys = array_column($canonicalSchema['fields'], 'key');
        $this->assertContains('quality_rating', $canonicalFieldKeys, 'Canonical resolver should include rating field');

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions: Rating field should be excluded from upload schema
        $uploadFieldKeys = [];
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $uploadFieldKeys[] = $field['key'];
            }
        }

        $this->assertNotContains('quality_rating', $uploadFieldKeys, 'Rating field should be excluded from upload schema');
        $this->assertContains('regular_field', $uploadFieldKeys, 'Regular field should be included');
    }

    /**
     * Test 4: Internal-Only Field Exclusion
     * is_internal_only = true → excluded
     */
    public function test_internal_only_field_exclusion(): void
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

        // Create internal-only field
        $internalFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'internal_field',
            'system_label' => 'Internal Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => true, // Internal only
            'group_key' => 'technical',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create regular field
        $regularFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'regular_field',
            'system_label' => 'Regular Field',
            'type' => 'text',
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

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions
        $uploadFieldKeys = [];
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $uploadFieldKeys[] = $field['key'];
            }
        }

        $this->assertNotContains('internal_field', $uploadFieldKeys, 'Internal-only field should be excluded');
        $this->assertContains('regular_field', $uploadFieldKeys, 'Regular field should be included');
    }

    /**
     * Test 5: applies_to Filtering
     * Field applies_to mismatch → excluded
     * applies_to = all → included
     */
    public function test_applies_to_filtering(): void
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

        // Create field for 'all' asset types
        $fieldAllId = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_all',
            'system_label' => 'Field for All',
            'type' => 'text',
            'applies_to' => 'all', // Applies to all
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

        // Create field for 'video' only
        $fieldVideoId = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_video',
            'system_label' => 'Field for Video',
            'type' => 'text',
            'applies_to' => 'video', // Only video
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

        // Resolve upload schema for 'image' asset type
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions
        $uploadFieldKeys = [];
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $uploadFieldKeys[] = $field['key'];
            }
        }

        $this->assertContains('field_all', $uploadFieldKeys, 'Field with applies_to=all should be included');
        $this->assertNotContains('field_video', $uploadFieldKeys, 'Field with applies_to=video should be excluded for image');
    }

    /**
     * Test 6: Grouping Behavior
     * Fields grouped by group_key
     * null group_key → "General"
     * Stable group ordering
     */
    public function test_grouping_behavior(): void
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

        // Create fields with different group_keys
        $field1Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_creative',
            'system_label' => 'Creative Field',
            'type' => 'text',
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

        $field2Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_technical',
            'system_label' => 'Technical Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'technical',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $field3Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'field_general',
            'system_label' => 'General Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null, // Null group_key
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions
        $this->assertCount(3, $uploadSchema['groups'], 'Should have three groups');

        $groupKeys = array_column($uploadSchema['groups'], 'key');
        $this->assertContains('creative', $groupKeys);
        $this->assertContains('technical', $groupKeys);
        $this->assertContains('general', $groupKeys, 'Null group_key should map to "general"');

        // Verify groups are sorted (stable order)
        $this->assertEquals('creative', $uploadSchema['groups'][0]['key'], 'Groups should be sorted');
        $this->assertEquals('general', $uploadSchema['groups'][1]['key']);
        $this->assertEquals('technical', $uploadSchema['groups'][2]['key']);

        // Verify group labels
        $creativeGroup = collect($uploadSchema['groups'])->firstWhere('key', 'creative');
        $this->assertEquals('Creative', $creativeGroup['label']);

        $generalGroup = collect($uploadSchema['groups'])->firstWhere('key', 'general');
        $this->assertEquals('General', $generalGroup['label']);
    }

    /**
     * Test 7: Options Pass-Through
     * Options included exactly as resolved
     * No fallback options added
     * Field with zero options still included
     */
    public function test_options_pass_through(): void
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

        // Create select field with options
        $selectFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'select_field',
            'system_label' => 'Select Field',
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

        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $selectFieldId,
            'value' => 'opt1',
            'system_label' => 'Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $selectFieldId,
            'value' => 'opt2',
            'system_label' => 'Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Hide option2 at tenant level
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option2Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create text field (no options)
        $textFieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'text_field',
            'system_label' => 'Text Field',
            'type' => 'text',
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

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Get canonical schema for comparison
        $canonicalSchema = $this->metadataSchemaResolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Find select field in upload schema
        $uploadSelectField = null;
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['key'] === 'select_field') {
                    $uploadSelectField = $field;
                    break 2;
                }
            }
        }

        // Find select field in canonical schema
        $canonicalSelectField = collect($canonicalSchema['fields'])->firstWhere('key', 'select_field');

        // Assertions: Options should match exactly
        $this->assertNotNull($uploadSelectField, 'Select field should be in upload schema');
        $this->assertCount(1, $uploadSelectField['options'], 'Should have one option (option2 hidden)');
        $this->assertEquals('opt1', $uploadSelectField['options'][0]['value'], 'Option should match canonical resolver');
        $this->assertEquals(
            $canonicalSelectField['options'],
            $uploadSelectField['options'],
            'Options should match canonical resolver exactly'
        );

        // Verify text field is included (no options)
        $uploadTextField = null;
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['key'] === 'text_field') {
                    $uploadTextField = $field;
                    break 2;
                }
            }
        }

        $this->assertNotNull($uploadTextField, 'Text field should be included');
        $this->assertIsArray($uploadTextField['options'], 'Options should be array');
        $this->assertCount(0, $uploadTextField['options'], 'Text field should have empty options array');
    }

    /**
     * Test 8: Empty Upload Schema
     * No upload-visible fields → groups array empty
     */
    public function test_empty_upload_schema(): void
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

        // Create field that is not upload-visible
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'not_upload_field',
            'system_label' => 'Not Upload Field',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => false, // Not upload-visible
            'is_internal_only' => false,
            'group_key' => 'creative',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Assertions
        $this->assertArrayHasKey('groups', $uploadSchema);
        $this->assertIsArray($uploadSchema['groups']);
        $this->assertCount(0, $uploadSchema['groups'], 'Groups array should be empty when no upload-visible fields');
    }

    /**
     * Snapshot Test: Full Upload Schema Output
     * Locks the complete upload schema structure
     */
    public function test_snapshot_full_upload_schema(): void
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

        // Create multiple fields with different types and groups
        $field1Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'creative_text',
            'system_label' => 'Creative Text',
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
            'key' => 'technical_select',
            'system_label' => 'Technical Select',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => false,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'technical',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create options for select field
        $option1Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $field2Id,
            'value' => 'tech_opt1',
            'system_label' => 'Tech Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option2Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $field2Id,
            'value' => 'tech_opt2',
            'system_label' => 'Tech Option 2',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Hide option2 at tenant level
        DB::table('metadata_option_visibility')->insert([
            'metadata_option_id' => $option2Id,
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'category_id' => null,
            'is_hidden' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $field3Id = DB::table('metadata_fields')->insertGetId([
            'key' => 'general_multiselect',
            'system_label' => 'General Multiselect',
            'type' => 'multiselect',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => null, // Null group_key → "General"
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create options for multiselect field
        $option3Id = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $field3Id,
            'value' => 'multi_opt1',
            'system_label' => 'Multi Option 1',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Resolve upload schema
        $uploadSchema = $this->resolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            'image'
        );

        // Snapshot assertions - any change must be intentional
        $this->assertArrayHasKey('groups', $uploadSchema);
        $this->assertCount(3, $uploadSchema['groups'], 'Should have three groups');

        // Verify group structure
        $groupKeys = array_column($uploadSchema['groups'], 'key');
        $this->assertEquals(['creative', 'general', 'technical'], $groupKeys, 'Groups should be sorted');

        // Verify Creative group
        $creativeGroup = collect($uploadSchema['groups'])->firstWhere('key', 'creative');
        $this->assertEquals('Creative', $creativeGroup['label']);
        $this->assertCount(1, $creativeGroup['fields']);
        $creativeField = $creativeGroup['fields'][0];
        $this->assertEquals('creative_text', $creativeField['key']);
        $this->assertEquals('Creative Text', $creativeField['display_label']);
        $this->assertEquals('text', $creativeField['type']);
        $this->assertFalse($creativeField['is_required'], 'is_required should be false (stub)');
        $this->assertIsArray($creativeField['options']);
        $this->assertCount(0, $creativeField['options']);

        // Verify Technical group
        $technicalGroup = collect($uploadSchema['groups'])->firstWhere('key', 'technical');
        $this->assertEquals('Technical', $technicalGroup['label']);
        $this->assertCount(1, $technicalGroup['fields']);
        $technicalField = $technicalGroup['fields'][0];
        $this->assertEquals('technical_select', $technicalField['key']);
        $this->assertEquals('Technical Select', $technicalField['display_label']);
        $this->assertEquals('select', $technicalField['type']);
        $this->assertFalse($technicalField['is_required'], 'is_required should be false (stub)');
        $this->assertCount(1, $technicalField['options'], 'Should have one option (option2 hidden)');
        $this->assertEquals('tech_opt1', $technicalField['options'][0]['value']);
        $this->assertEquals('Tech Option 1', $technicalField['options'][0]['display_label']);

        // Verify General group
        $generalGroup = collect($uploadSchema['groups'])->firstWhere('key', 'general');
        $this->assertEquals('General', $generalGroup['label']);
        $this->assertCount(1, $generalGroup['fields']);
        $generalField = $generalGroup['fields'][0];
        $this->assertEquals('general_multiselect', $generalField['key']);
        $this->assertEquals('General Multiselect', $generalField['display_label']);
        $this->assertEquals('multiselect', $generalField['type']);
        $this->assertFalse($generalField['is_required'], 'is_required should be false (stub)');
        $this->assertCount(1, $generalField['options']);

        // Verify no visibility flags in output
        foreach ($uploadSchema['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $this->assertArrayNotHasKey('is_visible', $field, 'Visibility flags should not be in output');
                $this->assertArrayNotHasKey('is_upload_visible', $field, 'Visibility flags should not be in output');
                $this->assertArrayNotHasKey('is_filterable', $field, 'Visibility flags should not be in output');
                $this->assertArrayNotHasKey('is_internal_only', $field, 'Visibility flags should not be in output');
            }
        }
    }
}
