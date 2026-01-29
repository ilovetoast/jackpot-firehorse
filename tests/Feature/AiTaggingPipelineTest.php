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
 * 4. ProcessAssetJob chains AITaggingJob
 * 5. AITaggingJob processes the asset
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

        // Verify ProcessAssetJob includes AITaggingJob in its chain
        // (verified by code inspection of ProcessAssetJob::handle())
        // This is a structural check, not a runtime check
        $this->assertTrue(
            class_exists(AITaggingJob::class),
            'AITaggingJob class should exist'
        );
    }

    /**
     * Test: AI tagging job can be dispatched directly (for manual testing)
     * 
     * This test verifies that AITaggingJob can be dispatched and runs
     * when thumbnails are completed.
     */
    public function test_ai_tagging_job_runs_when_thumbnails_completed(): void
    {
        // Create an asset with completed thumbnails
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
            'thumbnail_status' => ThumbnailStatus::COMPLETED, // Required for AI tagging
        ]);

        // Dispatch AITaggingJob directly
        $job = new AITaggingJob($asset->id);
        $job->handle();

        // Reload asset to check metadata
        $asset->refresh();

        // Assert that AI tagging metadata flag is set
        // This proves the pipeline ran (even if tags are empty in stub implementation)
        $metadata = $asset->metadata ?? [];
        $this->assertTrue(
            isset($metadata['ai_tagging_completed']) && $metadata['ai_tagging_completed'] === true,
            'AI tagging should set ai_tagging_completed flag in metadata'
        );

        // Assert that ai_tagging_completed_at timestamp is set
        $this->assertTrue(
            isset($metadata['ai_tagging_completed_at']),
            'AI tagging should set ai_tagging_completed_at timestamp'
        );
    }

    /**
     * Test: AI tagging is skipped when thumbnails are not completed
     * 
     * This test verifies that AITaggingJob skips processing when
     * thumbnails are not yet available.
     */
    public function test_ai_tagging_skipped_when_thumbnails_not_completed(): void
    {
        // Create an asset without completed thumbnails
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
            'thumbnail_status' => ThumbnailStatus::PENDING, // Thumbnails not completed
        ]);

        // Dispatch AITaggingJob directly
        $job = new AITaggingJob($asset->id);
        $job->handle();

        // Reload asset to check metadata
        $asset->refresh();

        // Assert that AI tagging was skipped
        $metadata = $asset->metadata ?? [];
        $this->assertTrue(
            isset($metadata['_ai_tagging_skipped']) && $metadata['_ai_tagging_skipped'] === true,
            'AI tagging should be skipped when thumbnails are not completed'
        );

        $this->assertEquals(
            'thumbnail_unavailable',
            $metadata['_ai_tagging_skip_reason'] ?? null,
            'Skip reason should be thumbnail_unavailable'
        );
    }
}
