<?php

namespace Tests\Unit\Jobs;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\FinalizeAssetJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProcessAssetJobStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that ProcessAssetJob does NOT mutate Asset.status.
     *
     * Given an Asset with status = VISIBLE
     * When ProcessAssetJob runs
     * Then Asset.status remains VISIBLE
     */
    public function test_process_asset_job_does_not_mutate_status(): void
    {
        // Fake the bus to prevent actual job dispatch
        Bus::fake();

        // Create test data
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create required dependencies for Asset
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
            'metadata' => [],
        ]);

        // Store original status
        $originalStatus = $asset->status;

        // Run ProcessAssetJob
        $job = new ProcessAssetJob($asset->id);
        $job->handle();

        // Reload asset from database
        $asset->refresh();

        // Assert status remains VISIBLE (not mutated)
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status);
        $this->assertEquals($originalStatus, $asset->status);
    }

    /**
     * Test that FinalizeAssetJob does NOT mutate status (completion tracked via metadata).
     *
     * FinalizeAssetJob should set pipeline_completed_at in metadata, not change status.
     * Status represents visibility only, not processing completion.
     */
    public function test_finalize_asset_job_does_not_mutate_status(): void
    {
        // Create test data
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-2',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-2',
        ]);

        // Create required dependencies for Asset
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

        // Create asset with VISIBLE status and all completion criteria met
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
                'metadata_extracted' => true,
                'ai_tagging_completed' => true,
                'preview_generated' => true,
            ],
        ]);

        $originalStatus = $asset->status;

        // Run FinalizeAssetJob (should set pipeline_completed_at, not change status)
        $job = new FinalizeAssetJob($asset->id);
        $job->handle();

        // Reload asset from database
        $asset->refresh();

        // Assert status remains VISIBLE (not mutated)
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status);
        $this->assertEquals($originalStatus, $asset->status);
        
        // Assert pipeline_completed_at was set in metadata
        $this->assertArrayHasKey('pipeline_completed_at', $asset->metadata);
    }

    /**
     * Test that ProcessAssetJob sets metadata but does not change status.
     */
    public function test_process_asset_job_sets_metadata_not_status(): void
    {
        // Fake the bus to prevent actual job dispatch
        Bus::fake();

        // Create test data
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-3',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-3',
        ]);

        // Create required dependencies for Asset
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
            'metadata' => [],
        ]);

        // Run ProcessAssetJob
        $job = new ProcessAssetJob($asset->id);
        $job->handle();

        // Reload asset from database
        $asset->refresh();

        // Assert status remains VISIBLE
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status);

        // Assert metadata was set
        $this->assertArrayHasKey('processing_started', $asset->metadata);
        $this->assertTrue($asset->metadata['processing_started']);
        $this->assertArrayHasKey('processing_started_at', $asset->metadata);
    }
}
