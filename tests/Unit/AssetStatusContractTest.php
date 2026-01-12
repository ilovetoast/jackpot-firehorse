<?php

namespace Tests\Unit;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\StorageBucket;
use App\Services\AssetCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Status Contract Test
 *
 * Ensures the asset visibility contract is maintained:
 * - No job mutates Asset.status
 * - Visibility remains unchanged after processing
 * - Processing completion is tracked via metadata/flags, not status
 */
class AssetStatusContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_jobs_do_not_mutate_asset_status(): void
    {
        // Create test tenant and brand
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create required dependencies
        $storageBucket = \App\Models\StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $uploadSession = \App\Models\UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset with VISIBLE status
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'metadata' => [],
        ]);

        $originalStatus = $asset->status;

        // Run processing job
        $job = new ProcessAssetJob($asset->id);
        $job->handle();

        // Reload asset
        $asset->refresh();

        // Assert status remains VISIBLE (not mutated)
        $this->assertEquals($originalStatus, $asset->status);
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status);
    }

    public function test_visibility_unchanged_after_processing_completion(): void
    {
        // Create test tenant and brand
        $tenant = Tenant::create([
            'name' => 'Test Tenant 2',
            'slug' => 'test-tenant-2',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand 2',
            'slug' => 'test-brand-2',
        ]);

        // Create required dependencies
        $storageBucket = \App\Models\StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket-2',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $uploadSession = \App\Models\UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset with VISIBLE status
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
            ],
        ]);

        $originalStatus = $asset->status;

        // Check completion (this should not mutate status)
        $completionService = app(AssetCompletionService::class);
        $isComplete = $completionService->isComplete($asset);

        $this->assertTrue($isComplete, 'Asset should meet completion criteria');

        // Reload asset
        $asset->refresh();

        // Assert status remains VISIBLE (visibility unchanged)
        $this->assertEquals($originalStatus, $asset->status);
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status);
    }

    public function test_completion_is_tracked_via_metadata_not_status(): void
    {
        // Create test tenant and brand
        $tenant = Tenant::create([
            'name' => 'Test Tenant 3',
            'slug' => 'test-tenant-3',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand 3',
            'slug' => 'test-brand-3',
        ]);

        // Create required dependencies
        $storageBucket = \App\Models\StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket-3',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $uploadSession = \App\Models\UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset with VISIBLE status and completion metadata
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
                'pipeline_completed_at' => now()->toIso8601String(),
            ],
        ]);

        // Verify completion is determined by metadata/flags, not status
        $completionService = app(AssetCompletionService::class);
        $isComplete = $completionService->isComplete($asset);

        $this->assertTrue($isComplete);
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status); // Status is VISIBILITY, not completion
    }
}
