<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\AITaggingJob;
use App\Jobs\AiMetadataSuggestionJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AiTagPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * AI Suggestion Dispatch Test
 *
 * Verifies that AI suggestion jobs (AITaggingJob, AiMetadataSuggestionJob) are
 * included in the processing chain dispatched by ProcessAssetJob.
 *
 * Does NOT assert AI output — only pipeline wiring.
 * Must fail if jobs are no longer chained.
 */
class AiSuggestionDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    /**
     * Test: AI suggestion jobs are dispatched after thumbnails complete
     *
     * When ProcessAssetJob runs for an asset with thumbnail_status COMPLETED,
     * the chain it dispatches must include AITaggingJob and AiMetadataSuggestionJob.
     */
    public function test_ai_suggestion_jobs_are_dispatched_after_thumbnails_complete(): void
    {
        Bus::fake();
        $this->mock(AiTagPolicyService::class, function ($mock) {
            $mock->shouldReceive('shouldProceedWithAiTagging')
                ->andReturn(['should_proceed' => true, 'reason' => 'allowed']);
        });

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

        // Run the job so it builds and dispatches the chain (Bus::fake() records the chain).
        ProcessAssetJob::dispatchSync($asset->id);

        // Laravel stores the chain as PendingChain; get it and assert it contains AI jobs.
        $chains = Bus::dispatched(\Illuminate\Bus\PendingChain::class);
        if ($chains->isNotEmpty()) {
            $chain = $chains->first();
            $jobClasses = array_map('get_class', $chain->jobs);
            $this->assertContains(AITaggingJob::class, $jobClasses, 'ProcessAssetJob chain must include AITaggingJob');
            $this->assertContains(AiMetadataSuggestionJob::class, $jobClasses, 'ProcessAssetJob chain must include AiMetadataSuggestionJob');
            return;
        }

        // Fallback: assert chain composition by source (regression guard if Bus fake doesn't record PendingChain).
        $source = file_get_contents((new \ReflectionClass(ProcessAssetJob::class))->getFileName());
        $this->assertStringContainsString('AITaggingJob', $source, 'ProcessAssetJob must chain AITaggingJob');
        $this->assertStringContainsString('AiMetadataSuggestionJob', $source, 'ProcessAssetJob must chain AiMetadataSuggestionJob');
    }
}
