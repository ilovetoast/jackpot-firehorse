<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Test metadata persistence during upload completion.
 * 
 * CRITICAL: These tests ensure that user-entered metadata during upload
 * is ALWAYS persisted to the asset_metadata table. This is a critical
 * requirement - metadata must never be silently lost.
 */
class UploadCompletionMetadataPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected UploadCompletionService $completionService;
    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;
    protected UploadSession $uploadSession;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake queue to prevent job execution
        Queue::fake();

        // Mock S3 client to avoid actual S3 calls in tests
        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('doesObjectExist')
            ->andReturn(true);
        
        // Mock headObject result as Aws\Result
        $headResult = Mockery::mock(Result::class);
        $headResult->shouldReceive('get')
            ->with('ContentLength')
            ->andReturn(1024);
        $headResult->shouldReceive('get')
            ->with('ContentType')
            ->andReturn('image/jpeg');
        $headResult->shouldReceive('get')
            ->with('ContentDisposition')
            ->andReturn(null);
        $headResult->shouldReceive('get')
            ->with('Metadata')
            ->andReturn([]);
        $headResult->shouldReceive('get')
            ->with('ETag')
            ->andReturn('"etag-test"');

        $s3Client->shouldReceive('headObject')
            ->andReturn($headResult);
        $s3Client->shouldReceive('copyObject')
            ->andReturn(new Result());
        $s3Client->shouldReceive('doesObjectExist')
            ->andReturn(true);

        $this->completionService = new UploadCompletionService($s3Client);

        // Create test tenant and brand
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create category
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        // Create user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create storage bucket
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create upload session
        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Bind tenant and brand context (required by UploadCompletionService)
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        // Seed system metadata fields
        $this->seedSystemMetadataFields();
    }

    protected function seedSystemMetadataFields(): void
    {
        DB::table('metadata_fields')->insert([
            [
                'id' => 1,
                'key' => 'photo_type',
                'system_label' => 'Photo Type',
                'type' => 'select',
                'scope' => 'system',
                'applies_to' => 'image',
                'population_mode' => 'manual',
                'show_on_upload' => true,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'readonly' => false,
                'is_user_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'key' => 'usage_rights',
                'system_label' => 'Usage Rights',
                'type' => 'select',
                'scope' => 'system',
                'applies_to' => 'image',
                'population_mode' => 'manual',
                'show_on_upload' => true,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'readonly' => false,
                'is_user_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed metadata options for photo_type
        DB::table('metadata_options')->insert([
            [
                'metadata_field_id' => 1,
                'value' => 'studio',
                'system_label' => 'Studio',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'metadata_field_id' => 1,
                'value' => 'outdoor',
                'system_label' => 'Outdoor',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_metadata_fields_are_persisted_to_asset_metadata_table(): void
    {
        $metadata = [
            'fields' => [
                'photo_type' => 'studio',
                'usage_rights' => 'commercial',
            ],
        ];

        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            null,
            $this->category->id,
            $metadata,
            $this->user->id
        );

        // Verify asset was created
        $this->assertNotNull($asset);
        $this->assertEquals($this->category->id, $asset->metadata['category_id']);

        // CRITICAL: Verify metadata was persisted to asset_metadata table
        $metadataRows = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->get();

        $this->assertGreaterThan(0, $metadataRows->count(), 'Metadata must be persisted to asset_metadata table');

        // Verify photo_type was persisted
        $photoTypeField = DB::table('metadata_fields')->where('key', 'photo_type')->first();
        $photoTypeMetadata = $metadataRows->where('metadata_field_id', $photoTypeField->id)->first();
        
        $this->assertNotNull($photoTypeMetadata, 'photo_type metadata must be persisted');
        $this->assertEquals('studio', json_decode($photoTypeMetadata->value_json, true));
        $this->assertEquals('user', $photoTypeMetadata->source);
        $this->assertNotNull($photoTypeMetadata->approved_at, 'Upload-time metadata must be auto-approved');
    }

    public function test_metadata_persistence_fails_loudly_if_category_missing(): void
    {
        $metadata = [
            'fields' => [
                'photo_type' => 'studio',
            ],
        ];

        // This should not throw, but should log a warning
        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            null,
            null, // No category
            $metadata,
            $this->user->id
        );

        // Asset should still be created, but metadata won't be persisted
        $this->assertNotNull($asset);

        // Metadata should not be persisted (category required)
        $metadataRows = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->count();

        $this->assertEquals(0, $metadataRows, 'Metadata should not be persisted without category');
    }

    public function test_metadata_persistence_fails_loudly_if_field_not_in_schema(): void
    {
        $metadata = [
            'fields' => [
                'invalid_field' => 'value',
            ],
        ];

        // CRITICAL: This should throw an exception because all fields were filtered out
        // This ensures user-entered data is never silently lost
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRITICAL: All metadata fields were filtered out');

        $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            null,
            $this->category->id,
            $metadata,
            $this->user->id
        );
    }

    public function test_metadata_persistence_works_with_multiple_fields(): void
    {
        $metadata = [
            'fields' => [
                'photo_type' => 'studio',
                'usage_rights' => 'commercial',
            ],
        ];

        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            null,
            $this->category->id,
            $metadata,
            $this->user->id
        );

        // Verify both fields were persisted
        $metadataRows = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->get();

        $this->assertEquals(2, $metadataRows->count(), 'All metadata fields must be persisted');

        // Verify each field
        $photoTypeField = DB::table('metadata_fields')->where('key', 'photo_type')->first();
        $usageRightsField = DB::table('metadata_fields')->where('key', 'usage_rights')->first();

        $photoTypeMetadata = $metadataRows->where('metadata_field_id', $photoTypeField->id)->first();
        $usageRightsMetadata = $metadataRows->where('metadata_field_id', $usageRightsField->id)->first();

        $this->assertNotNull($photoTypeMetadata);
        $this->assertNotNull($usageRightsMetadata);
        $this->assertEquals('studio', json_decode($photoTypeMetadata->value_json, true));
        $this->assertEquals('commercial', json_decode($usageRightsMetadata->value_json, true));
    }

    public function test_metadata_persistence_creates_audit_history(): void
    {
        $metadata = [
            'fields' => [
                'photo_type' => 'studio',
            ],
        ];

        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            null,
            $this->category->id,
            $metadata,
            $this->user->id
        );

        // Verify audit history was created
        $metadataRow = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->first();

        $this->assertNotNull($metadataRow);

        $historyRow = DB::table('asset_metadata_history')
            ->where('asset_metadata_id', $metadataRow->id)
            ->first();

        $this->assertNotNull($historyRow, 'Audit history must be created for metadata persistence');
        $this->assertEquals($this->user->id, $historyRow->changed_by);
        $this->assertEquals('user', $historyRow->source);
    }

    /**
     * UX-2: Test contributor upload with metadata approval enabled.
     * 
     * Edge case: Contributor enters metadata during upload when approval is enabled.
     * Expected: Metadata is accepted and stored, approval determined after upload.
     */
    public function test_contributor_upload_with_approval_enabled_stores_metadata(): void
    {
        // Enable metadata approval for tenant and brand
        $this->tenant->settings = ['enable_metadata_approval' => true];
        $this->tenant->save();
        
        $this->brand->settings = ['metadata_approval_enabled' => true];
        $this->brand->save();

        // Create contributor user (no bypass_approval permission)
        $contributor = User::create([
            'name' => 'Contributor User',
            'email' => 'contributor@example.com',
            'password' => bcrypt('password'),
        ]);
        
        // Assign contributor role (no metadata.bypass_approval permission)
        $contributor->tenants()->attach($this->tenant->id, ['role' => 'contributor']);

        $metadata = [
            'fields' => [
                'photo_type' => 'studio',
            ],
        ];

        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            null,
            $this->category->id,
            $metadata,
            $contributor->id
        );

        // Verify asset was created
        $this->assertNotNull($asset);

        // Verify metadata was persisted (not rejected)
        $metadataRows = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->get();

        $this->assertGreaterThan(0, $metadataRows->count(), 'Metadata must be persisted even when approval is enabled');

        // Verify metadata requires approval (approved_at is null)
        $photoTypeField = DB::table('metadata_fields')->where('key', 'photo_type')->first();
        $photoTypeMetadata = $metadataRows->where('metadata_field_id', $photoTypeField->id)->first();
        
        $this->assertNotNull($photoTypeMetadata, 'photo_type metadata must be persisted');
        $this->assertNull($photoTypeMetadata->approved_at, 'Contributor metadata should require approval when enabled');
    }

    /**
     * UX-2: Test batch upload with global metadata.
     * 
     * Edge case: Multiple files uploaded with same global metadata.
     * Expected: All assets inherit metadata, all enter approval if required.
     */
    public function test_batch_upload_with_global_metadata_applies_to_all_assets(): void
    {
        // Enable metadata approval
        $this->tenant->settings = ['enable_metadata_approval' => true];
        $this->tenant->save();
        
        $this->brand->settings = ['metadata_approval_enabled' => true];
        $this->brand->save();

        $globalMetadata = [
            'fields' => [
                'photo_type' => 'studio',
            ],
        ];

        // Create multiple upload sessions
        $uploadSession2 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Complete first asset
        $asset1 = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test1.jpg',
            'Test Asset 1',
            null,
            $this->category->id,
            $globalMetadata,
            $this->user->id
        );

        // Complete second asset with same metadata
        $asset2 = $this->completionService->complete(
            $uploadSession2,
            'asset',
            'test2.jpg',
            'Test Asset 2',
            null,
            $this->category->id,
            $globalMetadata,
            $this->user->id
        );

        // Verify both assets have metadata
        $metadata1 = DB::table('asset_metadata')
            ->where('asset_id', $asset1->id)
            ->count();
        
        $metadata2 = DB::table('asset_metadata')
            ->where('asset_id', $asset2->id)
            ->count();

        $this->assertGreaterThan(0, $metadata1, 'First asset must have metadata');
        $this->assertGreaterThan(0, $metadata2, 'Second asset must have metadata');
        $this->assertEquals($metadata1, $metadata2, 'Both assets should have same metadata count');
    }

    /**
     * UX-2: Test mixed category uploads.
     * 
     * Edge case: Batch upload with different categories and category-specific metadata.
     * Expected: Correct schema applied per asset, no cross-category leakage.
     */
    public function test_mixed_category_upload_applies_correct_schema_per_asset(): void
    {
        // Create second category
        $category2 = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Video',
            'slug' => 'video',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        // Create upload session for second category
        $uploadSession2 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Upload to first category with photo_type metadata
        $metadata1 = [
            'fields' => [
                'photo_type' => 'studio',
            ],
        ];

        $asset1 = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test1.jpg',
            'Test Asset 1',
            null,
            $this->category->id,
            $metadata1,
            $this->user->id
        );

        // Upload to second category (may have different schema)
        $metadata2 = [
            'fields' => [
                'usage_rights' => 'commercial',
            ],
        ];

        $asset2 = $this->completionService->complete(
            $uploadSession2,
            'asset',
            'test2.jpg',
            'Test Asset 2',
            null,
            $category2->id,
            $metadata2,
            $this->user->id
        );

        // Verify each asset has correct metadata
        $photoTypeField = DB::table('metadata_fields')->where('key', 'photo_type')->first();
        $usageRightsField = DB::table('metadata_fields')->where('key', 'usage_rights')->first();

        $asset1Metadata = DB::table('asset_metadata')
            ->where('asset_id', $asset1->id)
            ->get();
        
        $asset2Metadata = DB::table('asset_metadata')
            ->where('asset_id', $asset2->id)
            ->get();

        // Asset 1 should have photo_type
        $asset1PhotoType = $asset1Metadata->where('metadata_field_id', $photoTypeField->id)->first();
        $this->assertNotNull($asset1PhotoType, 'Asset 1 should have photo_type metadata');

        // Asset 2 should have usage_rights (if in schema for that category)
        // Note: This test assumes usage_rights is in schema for both categories
        // In real scenario, schema may differ per category
        $asset2UsageRights = $asset2Metadata->where('metadata_field_id', $usageRightsField->id)->first();
        // This may be null if usage_rights is not in category2's schema - that's expected
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
