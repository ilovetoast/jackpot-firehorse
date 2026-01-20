<?php

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\UploadSession;
use App\Services\PlanService;
use App\Services\TenantMetadataFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tenant Metadata Field Service Test
 *
 * Phase C3: Tests for tenant-scoped custom metadata field creation and management.
 *
 * These tests ensure:
 * - Fields are properly namespaced
 * - Plan limits are enforced
 * - Immutability is enforced
 * - Fields integrate with existing metadata engine
 *
 * @see TenantMetadataFieldService
 */
class TenantMetadataFieldServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TenantMetadataFieldService $service;
    protected PlanService $planService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planService = new PlanService();
        $this->service = new TenantMetadataFieldService($this->planService);
    }

    /**
     * Test that fields must start with 'custom__' prefix.
     */
    public function test_field_key_must_start_with_custom_prefix(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field key must start with "custom__" prefix');

        $this->service->createField($tenant, [
            'key' => 'invalid_key', // Missing custom__ prefix
            'system_label' => 'Invalid Key',
            'type' => 'text',
            'applies_to' => 'all',
        ]);
    }

    /**
     * Test that fields are created successfully with valid data.
     */
    public function test_field_creation_succeeds_with_valid_data(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan to allow custom fields
        $planServiceMock = $this->createMock(PlanService::class);
        $planServiceMock->method('getPlanLimits')
            ->with($tenant)
            ->willReturn(['max_custom_metadata_fields' => 10]);

        $service = new TenantMetadataFieldService($planServiceMock);

        $fieldId = $service->createField($tenant, [
            'key' => 'custom__test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'all',
        ]);

        $this->assertIsInt($fieldId);

        // Verify field was created
        $field = DB::table('metadata_fields')->where('id', $fieldId)->first();
        $this->assertNotNull($field);
        $this->assertEquals('custom__test_field', $field->key);
        $this->assertEquals('tenant', $field->scope);
        $this->assertEquals($tenant->id, $field->tenant_id);
        $this->assertEquals('manual', $field->population_mode);
        $this->assertTrue((bool) $field->is_active);
    }

    /**
     * Test that select fields require options.
     */
    public function test_select_fields_require_options(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan to allow custom fields
        $planServiceMock = $this->createMock(PlanService::class);
        $planServiceMock->method('getPlanLimits')
            ->with($tenant)
            ->willReturn(['max_custom_metadata_fields' => 10]);

        $service = new TenantMetadataFieldService($planServiceMock);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Options are required for select and multiselect fields');

        $service->createField($tenant, [
            'key' => 'custom__select_field',
            'system_label' => 'Select Field',
            'type' => 'select',
            'applies_to' => 'all',
            // Missing options
        ]);
    }

    /**
     * Test that plan limits are enforced.
     */
    public function test_plan_limits_are_enforced(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan with limit of 0 (no custom fields allowed)
        $this->mock(PlanService::class, function ($mock) use ($tenant) {
            $mock->shouldReceive('getPlanLimits')
                ->with($tenant)
                ->andReturn(['max_custom_metadata_fields' => 0]);
        });

        $service = new TenantMetadataFieldService($this->planService);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Your plan does not allow custom metadata fields');

        $service->createField($tenant, [
            'key' => 'custom__test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'all',
        ]);
    }

    /**
     * Test that keys must be unique per tenant.
     */
    public function test_keys_must_be_unique_per_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan to allow custom fields
        $planServiceMock = $this->createMock(PlanService::class);
        $planServiceMock->method('getPlanLimits')
            ->with($tenant)
            ->willReturn(['max_custom_metadata_fields' => 10]);

        $service = new TenantMetadataFieldService($planServiceMock);

        // Create first field
        $service->createField($tenant, [
            'key' => 'custom__test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'all',
        ]);

        // Try to create duplicate key
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A field with this key already exists for this tenant');

        $service->createField($tenant, [
            'key' => 'custom__test_field', // Duplicate
            'system_label' => 'Test Field 2',
            'type' => 'text',
            'applies_to' => 'all',
        ]);
    }

    /**
     * Test that fields can be disabled and enabled.
     */
    public function test_fields_can_be_disabled_and_enabled(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan to allow custom fields
        $planServiceMock = $this->createMock(PlanService::class);
        $planServiceMock->method('getPlanLimits')
            ->with($tenant)
            ->willReturn(['max_custom_metadata_fields' => 10]);

        $service = new TenantMetadataFieldService($planServiceMock);

        // Create field
        $fieldId = $service->createField($tenant, [
            'key' => 'custom__test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'all',
        ]);

        // Disable field
        $service->disableField($tenant, $fieldId);

        $field = DB::table('metadata_fields')->where('id', $fieldId)->first();
        $this->assertFalse((bool) $field->is_active);

        // Enable field
        $service->enableField($tenant, $fieldId);

        $field = DB::table('metadata_fields')->where('id', $fieldId)->first();
        $this->assertTrue((bool) $field->is_active);
    }

    /**
     * Test that fieldHasValues correctly detects fields with values.
     */
    public function test_field_has_values_detection(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan to allow custom fields
        $planServiceMock = $this->createMock(PlanService::class);
        $planServiceMock->method('getPlanLimits')
            ->with($tenant)
            ->willReturn(['max_custom_metadata_fields' => 10]);

        $service = new TenantMetadataFieldService($planServiceMock);

        // Create field
        $fieldId = $service->createField($tenant, [
            'key' => 'custom__test_field',
            'system_label' => 'Test Field',
            'type' => 'text',
            'applies_to' => 'all',
        ]);

        // Initially has no values
        $this->assertFalse($service->fieldHasValues($fieldId));

        // Create required dependencies for asset
        $storageBucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => null,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path',
        ]);

        // Create asset metadata value
        DB::table('asset_metadata')->insert([
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'value_json' => json_encode('test value'),
            'source' => 'user',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Now has values
        $this->assertTrue($service->fieldHasValues($fieldId));
    }

    /**
     * Test that select fields with options are created correctly.
     */
    public function test_select_field_with_options(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Mock plan to allow custom fields
        $planServiceMock = $this->createMock(PlanService::class);
        $planServiceMock->method('getPlanLimits')
            ->with($tenant)
            ->willReturn(['max_custom_metadata_fields' => 10]);

        $service = new TenantMetadataFieldService($planServiceMock);

        $fieldId = $service->createField($tenant, [
            'key' => 'custom__status',
            'system_label' => 'Status',
            'type' => 'select',
            'applies_to' => 'all',
            'options' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'published', 'label' => 'Published'],
                ['value' => 'archived', 'label' => 'Archived'],
            ],
        ]);

        // Verify options were created
        $options = DB::table('metadata_options')
            ->where('metadata_field_id', $fieldId)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $options);
        $this->assertEquals('draft', $options[0]->value);
        $this->assertEquals('Draft', $options[0]->system_label);
        $this->assertFalse((bool) $options[0]->is_system); // Tenant-created options
    }
}
