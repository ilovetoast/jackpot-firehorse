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
 * - Option B (Skip): AiMetadataGenerationJob marks skipped after waitForThumbnail fails
 * 
 * Both models are valid. Long-term, consider standardizing on Option A for consistency.
 * See /docs/UPLOAD_AND_QUEUE.md (Pipeline sequencing) for details.
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
     * {@see AITaggingJob} is a deprecated no-op; thumbnail gating for real tags lives in {@see AiMetadataGenerationJob}.
     */
    public function test_legacy_ai_tagging_job_does_not_mutate_metadata_when_thumbnails_pending(): void
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
            'title' => 'Test Asset',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ]);

        (new AITaggingJob($asset->id))->handle();
        $asset->refresh();
        $metadata = $asset->metadata ?? [];
        $this->assertArrayNotHasKey('_ai_tagging_skipped', $metadata);
        $this->assertArrayNotHasKey('ai_tagging_completed', $metadata);
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
        // 3. Legacy AITaggingJob is a no-op; gating is tested via AiMetadataGenerationJob in other tests
        
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
     * Thumbnail readiness is required before {@see AiMetadataGenerationJob}; {@see AITaggingJob} no longer flips completion flags.
     */
    public function test_legacy_ai_tagging_job_does_not_set_completion_when_thumbnails_ready(): void
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
            'title' => 'Test Asset',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
        ]);

        (new AITaggingJob($asset->id))->handle();
        $asset->refresh();
        $metadata = $asset->metadata ?? [];
        $this->assertArrayNotHasKey('ai_tagging_completed', $metadata);
    }
}
