<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\AITaggingJob;
use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\AiMetadataSuggestionJob;
use App\Jobs\AiTagAutoApplyJob;
use App\Jobs\ExtractMetadataJob;
use App\Jobs\GenerateThumbnailsJob;
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
     * ProcessAssetJob now dispatches two chains:
     *   - main chain (images queue): thumbnails first, then preview, metadata, tagging flag, finalize, promote.
     *   - AI chain  (ai queue):      AiMetadataGenerationJob → AiTagAutoApplyJob → AiMetadataSuggestionJob.
     *
     * This test asserts both chains are dispatched and that:
     *   - AITaggingJob (pipeline flag step) stays in the main chain
     *   - AI vision/suggestion jobs (AiMetadataGenerationJob, AiMetadataSuggestionJob) live on the AI chain
     *   - GenerateThumbnailsJob runs before ExtractMetadataJob in the main chain (fast path)
     *   - the AI chain queue is the dedicated ai queue
     */
    public function test_ai_suggestion_jobs_are_dispatched_after_thumbnails_complete(): void
    {
        // Fake every job EXCEPT ProcessAssetJob so its handle() actually runs and we
        // can observe the inner Bus::chain dispatches it makes.
        Bus::fake()->except([ProcessAssetJob::class]);
        // Throttle uses Redis::throttle which is unnecessary noise for chain-shape tests.
        config()->set('assets.processing.throttle_enabled', false);
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

        // dispatchSync runs the handler in-process; queue routing for ProcessAssetJob
        // itself is not under test here. Bus::fake captures the inner Bus::chain dispatches
        // for the main + ai chains so we can inspect their composition / queue via the
        // chain head's `chained` (serialized subsequent jobs) + `queue` properties.
        ProcessAssetJob::dispatchSync($asset->id);

        $mainHead = Bus::dispatched(GenerateThumbnailsJob::class)->first();
        $aiHead = Bus::dispatched(AiMetadataGenerationJob::class)->first();

        $this->assertNotNull($mainHead, 'ProcessAssetJob must dispatch the main pipeline chain (head: GenerateThumbnailsJob)');
        $this->assertNotNull($aiHead, 'ProcessAssetJob must dispatch a separate AI chain (head: AiMetadataGenerationJob)');

        $mainClasses = $this->chainClasses($mainHead);
        $aiClasses = $this->chainClasses($aiHead);

        $this->assertContains(AITaggingJob::class, $mainClasses, 'AITaggingJob (pipeline flag) must remain in main chain');
        $this->assertNotContains(AiMetadataSuggestionJob::class, $mainClasses, 'AiMetadataSuggestionJob must NOT be in the main chain');
        $this->assertNotContains(AiMetadataGenerationJob::class, $mainClasses, 'AiMetadataGenerationJob must NOT be in the main chain');

        $this->assertContains(AiMetadataGenerationJob::class, $aiClasses, 'AiMetadataGenerationJob must be in the AI chain');
        $this->assertContains(AiMetadataSuggestionJob::class, $aiClasses, 'AiMetadataSuggestionJob must be in the AI chain');

        $thumbIdx = array_search(GenerateThumbnailsJob::class, $mainClasses, true);
        $extractIdx = array_search(ExtractMetadataJob::class, $mainClasses, true);
        $this->assertIsInt($thumbIdx, 'Main chain must include GenerateThumbnailsJob');
        $this->assertIsInt($extractIdx, 'Main chain must include ExtractMetadataJob');
        $this->assertLessThan($extractIdx, $thumbIdx, 'GenerateThumbnailsJob must run before ExtractMetadataJob (time-to-first-thumbnail fast path)');

        $aiQueue = (string) config('queue.ai_queue', 'ai');
        $this->assertSame($aiQueue, (string) $aiHead->queue, 'AI chain must run on the ai queue, not images');
    }

    /**
     * Resolve the full ordered list of class names for a chain whose dispatched
     * head job is `$head`. Subsequent jobs in a Laravel chain are stored serialized
     * on `$head->chained`.
     *
     * @return array<int, class-string>
     */
    protected function chainClasses(object $head): array
    {
        $classes = [get_class($head)];

        foreach (($head->chained ?? []) as $serialized) {
            $job = is_string($serialized) ? unserialize($serialized) : $serialized;
            $classes[] = is_object($job) ? get_class($job) : (string) $job;
        }

        return $classes;
    }

    /**
     * Time-to-first-thumbnail regression guard. The main chain order must keep
     * GenerateThumbnailsJob ahead of any non-essential enrichment work so users
     * see standard thumbnails as quickly as possible after upload.
     */
    public function test_main_chain_runs_thumbnails_before_enrichment(): void
    {
        Bus::fake()->except([ProcessAssetJob::class]);
        config()->set('assets.processing.throttle_enabled', false);
        $this->mock(AiTagPolicyService::class, function ($mock) {
            $mock->shouldReceive('shouldProceedWithAiTagging')
                ->andReturn(['should_proceed' => false, 'reason' => 'disabled_for_test']);
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
            'title' => 'Fast path JPEG',
            'original_filename' => 'fast-path.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/fast-path.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ]);

        ProcessAssetJob::dispatchSync($asset->id);

        $mainHead = Bus::dispatched(GenerateThumbnailsJob::class)->first();
        $this->assertNotNull($mainHead, 'Main pipeline chain must be dispatched with GenerateThumbnailsJob as head');

        $classes = $this->chainClasses($mainHead);
        $thumbIdx = array_search(GenerateThumbnailsJob::class, $classes, true);
        $extractIdx = array_search(ExtractMetadataJob::class, $classes, true);

        $this->assertSame(0, $thumbIdx, 'GenerateThumbnailsJob must be the first job in the main chain');
        $this->assertIsInt($extractIdx);
        $this->assertGreaterThan($thumbIdx, $extractIdx, 'ExtractMetadataJob must run after GenerateThumbnailsJob');
    }

    /**
     * AI vision jobs must NOT land on the images queue (they would compete with thumbnail
     * workers). When ProcessAssetJob dispatches the AI chain it must target the dedicated
     * ai queue, and individual AI jobs must have their queue overridden so the chain queue
     * setting cannot be bypassed by their QueuesOnImagesChannel constructor.
     */
    public function test_ai_jobs_do_not_run_on_images_queue(): void
    {
        Bus::fake()->except([ProcessAssetJob::class]);
        config()->set('assets.processing.throttle_enabled', false);
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
            'title' => 'AI queue test',
            'original_filename' => 'ai-queue.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/ai-queue.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
        ]);

        ProcessAssetJob::dispatchSync($asset->id);

        $aiHead = Bus::dispatched(AiMetadataGenerationJob::class)->first();
        $this->assertNotNull($aiHead, 'AI chain must be dispatched with AiMetadataGenerationJob as head');

        $aiQueue = (string) config('queue.ai_queue', 'ai');
        $imagesQueue = (string) config('queue.images_queue', 'images');

        $this->assertSame($aiQueue, (string) $aiHead->queue, 'AI chain head must use ai queue');
        $this->assertNotSame($imagesQueue, (string) $aiHead->queue, 'AI chain head must NOT use images queue');

        // Each subsequent AI job in the chain has its queue pinned by ProcessAssetJob
        // (`$job->onQueue($aiQueue)`) so it cannot be silently rerouted to images via
        // QueuesOnImagesChannel constructors.
        foreach (($aiHead->chained ?? []) as $serialized) {
            $job = is_string($serialized) ? unserialize($serialized) : $serialized;
            if ($job instanceof AiMetadataGenerationJob
                || $job instanceof AiTagAutoApplyJob
                || $job instanceof AiMetadataSuggestionJob
            ) {
                $this->assertSame(
                    $aiQueue,
                    (string) $job->queue,
                    sprintf('%s must be routed to the ai queue, got %s', get_class($job), (string) $job->queue)
                );
            }
        }
    }
}
