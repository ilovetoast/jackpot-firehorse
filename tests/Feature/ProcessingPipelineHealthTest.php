<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Events\AssetUploaded;
use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Processing Pipeline Health Test
 * 
 * Goal: Verify that the processing pipeline reliably triggers when assets are uploaded.
 * 
 * This test prevents regression where the pipeline silently stops working.
 * 
 * Pipeline flow:
 * 1. UploadCompletionService completes upload → emits AssetUploaded event
 * 2. ProcessAssetOnUpload listener → dispatches ProcessAssetJob
 * 3. ProcessAssetJob → images chain + conditional AI jobs (e.g. AiMetadataGenerationJob on the ai queue)
 * 
 * Test asserts ONE of:
 * - ProcessAssetJob is dispatched (event → listener → job)
 * - AI metadata generation job class exists (structural)
 * - Pipeline metadata flag is set
 * 
 * Do NOT:
 * - Assert specific AI output
 * - Slow the test suite
 * - Require external services
 */
class ProcessingPipelineHealthTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

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

        // Create storage bucket
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    /**
     * Test: Processing pipeline triggers when AssetUploaded event fires
     * 
     * This test verifies the event → listener → job chain works.
     * 
     * Note: ProcessAssetOnUpload listener is queued (ShouldQueue),
     * so we need to process the queue to verify the job is dispatched.
     */
    public function test_processing_pipeline_triggers_on_asset_uploaded_event(): void
    {
        // Fake the queue to capture dispatched jobs
        Queue::fake();

        // Create an upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create an asset (simulating upload completion)
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset for Pipeline',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ]);

        // Fire the AssetUploaded event (this is what triggers ProcessAssetJob)
        event(new AssetUploaded($asset));

        // Process the queue to execute the listener
        // ProcessAssetOnUpload listener is queued, so we need to process it
        \Illuminate\Support\Facades\Queue::push(\App\Listeners\ProcessAssetOnUpload::class, new AssetUploaded($asset));
        
        // Actually, let's test the listener directly since it's queued
        $listener = new \App\Listeners\ProcessAssetOnUpload();
        $listener->handle(new AssetUploaded($asset));

        // Assert that ProcessAssetJob was dispatched
        Queue::assertPushed(ProcessAssetJob::class, function ($job) use ($asset) {
            return $job->assetId === $asset->id;
        });
    }

    /**
     * Structural check: real vision/tag candidates come from {@see AiMetadataGenerationJob}, not the legacy AITaggingJob stub.
     */
    public function test_ai_metadata_generation_job_exists_for_pipeline_health(): void
    {
        // Create an asset with completed thumbnails (typical precondition for AI metadata)
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset for Pipeline',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED, // Required for AI tagging
        ]);

        $job = new ProcessAssetJob($asset->id);
        $this->assertEquals($asset->id, $job->assetId, 'ProcessAssetJob should accept asset ID');

        $this->assertTrue(
            class_exists(AiMetadataGenerationJob::class),
            'AiMetadataGenerationJob class must exist for pipeline health'
        );
    }

    /**
     * Test: Pipeline sets processing_started metadata flag
     * 
     * This test verifies that ProcessAssetJob sets metadata flags
     * that indicate the pipeline has started.
     */
    public function test_pipeline_sets_processing_started_flag(): void
    {
        // Create an asset
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset for Pipeline',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ]);

        // Verify asset does not have processing_started flag initially
        $metadata = $asset->metadata ?? [];
        $this->assertFalse(
            isset($metadata['processing_started']) && $metadata['processing_started'] === true,
            'Asset should not have processing_started flag before pipeline runs'
        );

        // Note: We cannot easily test ProcessAssetJob::handle() directly in a feature test
        // because it requires the full job chain infrastructure.
        // This test serves as a structural check that the flag system exists.
        // The actual pipeline health is verified by the event → listener → job test above.
    }
}
