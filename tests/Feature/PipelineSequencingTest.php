<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\AITaggingJob;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pipeline Sequencing Test
 * 
 * Goal: Verify that image-derived jobs (AI tagging, dominant colors, metadata)
 * do NOT run until thumbnails are ready.
 * 
 * Canonical Invariant:
 * NO image-derived job may run until:
 * - thumbnails exist (thumbnail_status === COMPLETED)
 * - OR a source image is confirmed readable
 * 
 * This test prevents regression where jobs run before thumbnails are generated,
 * which breaks:
 * - dominant color extraction
 * - AI image analysis
 * - metadata derivation
 * 
 * Test intent:
 * "If thumbnails are not ready, image-derived jobs do NOT run."
 * 
 * ARCHITECTURAL NOTE:
 * Currently, jobs use two different models for handling thumbnail dependencies:
 * - Option A (Retry): PopulateAutomaticMetadataJob uses release() to retry
 * - Option B (Skip): AITaggingJob skips and marks as skipped
 * 
 * Both models are valid. Long-term, consider standardizing on Option A for consistency.
 * See /docs/PIPELINE_SEQUENCING.md for details.
 * 
 * Do NOT:
 * - Test AI output
 * - Test specific metadata values
 * - Require external services
 * 
 * Test sequencing only.
 */
class PipelineSequencingTest extends TestCase
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
     * Test: AITaggingJob does not run when thumbnails are not ready
     * 
     * Verifies that AITaggingJob checks thumbnail_status and skips
     * if thumbnails are not COMPLETED.
     */
    public function test_ai_tagging_job_skips_when_thumbnails_not_ready(): void
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

        // Create asset with thumbnails NOT ready (PENDING)
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING, // Thumbnails NOT ready
        ]);

        // Run AITaggingJob
        $job = new AITaggingJob($asset->id);
        $job->handle();

        // Reload asset to check metadata
        $asset->refresh();

        // Assert job was skipped (marked as skipped in metadata)
        $metadata = $asset->metadata ?? [];
        $this->assertTrue(
            isset($metadata['_ai_tagging_skipped']) && $metadata['_ai_tagging_skipped'] === true,
            'AITaggingJob should be marked as skipped when thumbnails are not ready'
        );
        $this->assertEquals(
            'thumbnail_unavailable',
            $metadata['_ai_tagging_skip_reason'] ?? null,
            'Skip reason should be thumbnail_unavailable'
        );
        $this->assertFalse(
            isset($metadata['ai_tagging_completed']) && $metadata['ai_tagging_completed'] === true,
            'AI tagging should NOT be marked as completed when thumbnails are not ready'
        );
    }

    /**
     * Test: PopulateAutomaticMetadataJob gates on thumbnail readiness
     * 
     * Verifies that PopulateAutomaticMetadataJob checks thumbnail_status and
     * releases the job (reschedules) if thumbnails are not COMPLETED.
     * 
     * Note: Testing release() behavior requires queue context. This test verifies
     * the gate exists by code inspection and documents expected behavior.
     * The actual gate is verified in PopulateAutomaticMetadataJob::handle().
     */
    public function test_populate_automatic_metadata_job_gates_on_thumbnail_readiness(): void
    {
        // This test verifies the gate exists in code.
        // PopulateAutomaticMetadataJob::handle() checks thumbnail_status === COMPLETED
        // and calls release() if thumbnails are not ready.
        // 
        // Since release() only works in queue context, we verify the gate by:
        // 1. Code inspection (gate check exists in handle() method)
        // 2. Documenting expected behavior
        // 3. Verifying similar gate in AITaggingJob (which we can test)
        
        // Verify the gate check exists in PopulateAutomaticMetadataJob
        $reflection = new \ReflectionClass(PopulateAutomaticMetadataJob::class);
        $sourceCode = file_get_contents($reflection->getFileName());
        
        // Assert that the gate check exists in the code
        $this->assertStringContainsString(
            'thumbnail_status !== ThumbnailStatus::COMPLETED',
            $sourceCode,
            'PopulateAutomaticMetadataJob should check thumbnail_status before processing'
        );
        
        $this->assertStringContainsString(
            'release(',
            $sourceCode,
            'PopulateAutomaticMetadataJob should call release() when thumbnails are not ready'
        );
        
        // Document: In queue context, release() reschedules the job for retry.
        // This ensures image-derived jobs wait for thumbnails to be ready.
    }

    /**
     * Test: Image-derived jobs run after thumbnails are marked ready
     * 
     * Verifies that when thumbnails are COMPLETED, image-derived jobs
     * proceed normally.
     */
    public function test_image_derived_jobs_run_after_thumbnails_ready(): void
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

        // Create asset with thumbnails READY (COMPLETED)
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED, // Thumbnails READY
        ]);

        // Run AITaggingJob
        $job = new AITaggingJob($asset->id);
        $job->handle();

        // Reload asset to check metadata
        $asset->refresh();

        // Assert job completed (not skipped)
        $metadata = $asset->metadata ?? [];
        $this->assertFalse(
            isset($metadata['_ai_tagging_skipped']) && $metadata['_ai_tagging_skipped'] === true,
            'AITaggingJob should NOT be skipped when thumbnails are ready'
        );
        $this->assertTrue(
            isset($metadata['ai_tagging_completed']) && $metadata['ai_tagging_completed'] === true,
            'AI tagging should be marked as completed when thumbnails are ready'
        );
    }
}
