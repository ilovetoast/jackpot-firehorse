<?php

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
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
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Phase L.5: Test category-based approval rules in upload completion.
 * 
 * Verifies that:
 * - Assets are auto-published when category does not require approval
 * - Assets remain unpublished when category requires approval
 * - Assets remain unpublished when no category is provided
 */
class UploadCompletionApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected UploadCompletionService $completionService;
    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

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
        
        $s3Client->shouldReceive('headObject')
            ->andReturn($headResult);

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

        // Bind tenant and brand context (required by UploadCompletionService)
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    public function test_asset_is_auto_published_when_category_does_not_require_approval(): void
    {
        // Create category that does not require approval
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Auto-Publish Category',
            'slug' => 'auto-publish',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Complete upload with category
        $asset = $this->completionService->complete(
            $uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            'temp/uploads/' . $uploadSession->id . '/original',
            $category->id,
            null,
            $this->user->id
        );

        // Refresh to get latest state
        $asset->refresh();

        // Assert asset is published
        $this->assertNotNull($asset->published_at, 'Asset should be auto-published when category does not require approval');
        $this->assertEquals($this->user->id, $asset->published_by_id, 'Asset should be published by the uploader');
        $this->assertTrue($asset->isPublished(), 'Asset should be marked as published');
    }

    public function test_asset_remains_unpublished_when_category_requires_approval(): void
    {
        // Create category that requires approval
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Required Category',
            'slug' => 'approval-required',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => true,
        ]);

        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Complete upload with category
        $asset = $this->completionService->complete(
            $uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            'temp/uploads/' . $uploadSession->id . '/original',
            $category->id,
            null,
            $this->user->id
        );

        // Refresh to get latest state
        $asset->refresh();

        // Phase L.5.1: Assert asset is NOT published and status is HIDDEN
        $this->assertNull($asset->published_at, 'Asset should remain unpublished when category requires approval');
        $this->assertNull($asset->published_by_id, 'Asset should not have published_by_id when unpublished');
        $this->assertFalse($asset->isPublished(), 'Asset should not be marked as published');
        $this->assertEquals(AssetStatus::HIDDEN, $asset->status, 'Asset should have HIDDEN status when pending approval');
    }

    public function test_asset_remains_unpublished_when_no_category_provided(): void
    {
        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Complete upload without category
        $asset = $this->completionService->complete(
            $uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            'temp/uploads/' . $uploadSession->id . '/original',
            null, // No category
            null,
            $this->user->id
        );

        // Refresh to get latest state
        $asset->refresh();

        // Assert asset is NOT published
        $this->assertNull($asset->published_at, 'Asset should remain unpublished when no category is provided');
        $this->assertNull($asset->published_by_id, 'Asset should not have published_by_id when unpublished');
        $this->assertFalse($asset->isPublished(), 'Asset should not be marked as published');
    }

    public function test_asset_remains_unpublished_when_no_user_id_provided(): void
    {
        // Create category that does not require approval
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Auto-Publish Category',
            'slug' => 'auto-publish',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Complete upload without user ID
        $asset = $this->completionService->complete(
            $uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            'temp/uploads/' . $uploadSession->id . '/original',
            $category->id,
            null,
            null // No user ID
        );

        // Refresh to get latest state
        $asset->refresh();

        // Assert asset is NOT published (requires user ID for auto-publish)
        $this->assertNull($asset->published_at, 'Asset should remain unpublished when no user ID is provided');
        $this->assertNull($asset->published_by_id, 'Asset should not have published_by_id when unpublished');
        $this->assertFalse($asset->isPublished(), 'Asset should not be marked as published');
    }

    public function test_asset_remains_unpublished_when_category_not_found(): void
    {
        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Complete upload with non-existent category ID
        $asset = $this->completionService->complete(
            $uploadSession,
            'asset',
            'test.jpg',
            'Test Asset',
            'temp/uploads/' . $uploadSession->id . '/original',
            99999, // Non-existent category ID
            null,
            $this->user->id
        );

        // Refresh to get latest state
        $asset->refresh();

        // Assert asset is NOT published (category not found)
        $this->assertNull($asset->published_at, 'Asset should remain unpublished when category is not found');
        $this->assertNull($asset->published_by_id, 'Asset should not have published_by_id when unpublished');
        $this->assertFalse($asset->isPublished(), 'Asset should not be marked as published');
    }
}
