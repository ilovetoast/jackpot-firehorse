<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Events\AssetUploaded;
use App\Jobs\AITaggingJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Test AI Tagging Pipeline
 * 
 * Goal: Verify that AI tagging pipeline fires when expected.
 * This test does NOT assert specific AI output - only that the pipeline triggers.
 * 
 * The pipeline flow:
 * 1. Asset is created via UploadCompletionService
 * 2. AssetUploaded event is fired
 * 3. ProcessAssetOnUpload listener dispatches ProcessAssetJob
 * 4. ProcessAssetJob runs the images chain and conditionally dispatches AI work (e.g. AiMetadataGenerationJob on the ai queue)
 * 5. {@see AITaggingJob} is deprecated and no longer performs vision or writes tags
 */
class AiTaggingPipelineTest extends TestCase
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
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    /**
     * Test: AI tagging pipeline can be triggered (minimal probe)
     * 
     * This test verifies that:
     * - AITaggingJob can be dispatched
     * - The job structure is correct
     * 
     * Note: Full event chain testing requires event listener registration.
     * This minimal test verifies the job itself is functional.
     */
    public function test_ai_tagging_job_can_be_dispatched(): void
    {
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
            'title' => 'Test Asset for AI Tagging',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED, // Thumbnails must be completed for AI tagging
        ]);

        // Verify AITaggingJob can be instantiated and dispatched
        $job = new AITaggingJob($asset->id);
        $this->assertEquals($asset->id, $job->assetId, 'AITaggingJob should accept asset ID');

        // Legacy stub still exists for queued retries; real tagging is AiMetadataGenerationJob.
        $this->assertTrue(
            class_exists(AITaggingJob::class),
            'AITaggingJob class should exist'
        );
    }

    /**
     * Legacy {@see AITaggingJob} is a no-op; completion flags come from {@see \App\Jobs\AiMetadataGenerationJob}.
     */
    public function test_legacy_ai_tagging_job_does_not_set_pipeline_flags(): void
    {
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
            'title' => 'Test Asset for AI Tagging',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
        ]);

        (new AITaggingJob($asset->id))->handle();
        $asset->refresh();
        $this->assertArrayNotHasKey('ai_tagging_completed', $asset->metadata ?? []);
    }

    public function test_legacy_ai_tagging_job_noop_when_thumbnails_pending(): void
    {
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
            'title' => 'Test Asset for AI Tagging',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ]);

        (new AITaggingJob($asset->id))->handle();
        $asset->refresh();
        $this->assertArrayNotHasKey('_ai_tagging_skipped', $asset->metadata ?? []);
    }
}
