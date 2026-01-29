<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateThumbnailsJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Thumbnail Failure State Test
 * 
 * Goal: Prevent assets from being stuck in PROCESSING state forever.
 * 
 * This test ensures that GenerateThumbnailsJob ALWAYS sets a terminal state:
 * - COMPLETED (success)
 * - FAILED (error)
 * - SKIPPED (unsupported format)
 * 
 * Never PROCESSING after job execution completes.
 * 
 * This prevents:
 * - Downstream jobs waiting forever for thumbnails
 * - Assets stuck in limbo
 * - Pipeline deadlocks
 */
class ThumbnailFailureStateTest extends TestCase
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
     * Test: Thumbnail job never leaves asset stuck in PROCESSING state
     * 
     * Verifies that GenerateThumbnailsJob ALWAYS sets a terminal state,
     * even if an exception occurs.
     * 
     * This test prevents the regression where assets get stuck in PROCESSING
     * forever, blocking downstream jobs.
     */
    public function test_thumbnail_job_never_leaves_processing_state(): void
    {
        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset stuck in PROCESSING state (simulating the bug)
        // Set thumbnail_started_at to simulate a job that started but never completed
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset Stuck in Processing',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::PROCESSING, // Stuck in PROCESSING
            'thumbnail_started_at' => now()->subMinutes(10), // Started 10 minutes ago (simulating stuck)
        ]);

        // Verify asset starts in PROCESSING
        $this->assertEquals(
            ThumbnailStatus::PROCESSING,
            $asset->thumbnail_status,
            'Asset should start in PROCESSING state for this test'
        );

        // Run GenerateThumbnailsJob
        // The job should detect the stuck PROCESSING state (started_at is 10 minutes ago)
        // and set it to FAILED via the timeout guard
        // This tests the safety guard that prevents assets from being stuck in PROCESSING forever
        try {
            $job = new GenerateThumbnailsJob($asset->id);
            $job->handle(app(\App\Services\ThumbnailGenerationService::class));
        } catch (\Throwable $e) {
            // Expected - job may fail without actual S3 file
            // But the timeout guard should have set FAILED before any exception
            // OR the catch block should have set FAILED
        }

        // Reload asset to check final state
        $asset->refresh();
        
        // Log final state for debugging
        \Illuminate\Support\Facades\Log::info('[ThumbnailFailureStateTest] Final asset state', [
            'asset_id' => $asset->id,
            'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            'thumbnail_error' => $asset->thumbnail_error ?? 'none',
            'thumbnail_started_at' => $asset->thumbnail_started_at?->toIso8601String() ?? 'null',
        ]);

        // CRITICAL ASSERTION: Asset must NOT remain in PROCESSING
        // It must be in one of: COMPLETED, FAILED, or SKIPPED
        $this->assertNotEquals(
            ThumbnailStatus::PROCESSING,
            $asset->thumbnail_status,
            'Thumbnail job must never leave asset stuck in PROCESSING. ' .
            'Current status: ' . ($asset->thumbnail_status?->value ?? 'null') . '. ' .
            'Error: ' . ($asset->thumbnail_error ?? 'none')
        );

        // Verify it's in a valid terminal state
        $validTerminalStates = [
            ThumbnailStatus::COMPLETED,
            ThumbnailStatus::FAILED,
            ThumbnailStatus::SKIPPED,
        ];

        $this->assertContains(
            $asset->thumbnail_status,
            $validTerminalStates,
            'Thumbnail status must be a valid terminal state: COMPLETED, FAILED, or SKIPPED'
        );
    }
}
