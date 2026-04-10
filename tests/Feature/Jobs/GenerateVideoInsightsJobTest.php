<?php

namespace Tests\Feature\Jobs;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateVideoInsightsJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\FileTypeService;
use App\Services\VideoAiMinuteEstimator;
use App\Services\VideoInsightsSearchIndexWriter;
use App\Services\VideoInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests: job handle(), Eloquent, and metadata persistence.
 *
 * Branching logic for early exits lives in {@see \App\Support\VideoInsights\VideoInsightsJobPreflight} (fast pure unit tests).
 *
 * @group integration
 * @group database
 * @group jobs
 */
class GenerateVideoInsightsJobTest extends TestCase
{
    use RefreshDatabase;

    private function baseAssetRow(Tenant $tenant, Brand $brand, StorageBucket $bucket, UploadSession $upload): array
    {
        return [
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'Clip',
            'original_filename' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.\Illuminate\Support\Str::uuid().'/v1/original.mp4',
            'size_bytes' => 1024,
        ];
    }

    private function seedTenantStack(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-vij']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-vij']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return [$tenant, $brand, $bucket, $upload];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stale_processing_is_normalized_when_insights_already_complete(): void
    {
        [$tenant, $brand, $bucket, $upload] = $this->seedTenantStack();

        $asset = Asset::create(array_merge($this->baseAssetRow($tenant, $brand, $bucket, $upload), [
            'metadata' => [
                'ai_video_insights_completed_at' => now()->toIso8601String(),
                'ai_video_status' => 'processing',
            ],
        ]));

        $this->mock(VideoInsightsService::class, function ($mock) {
            $mock->shouldNotReceive('analyze');
        });

        $job = new GenerateVideoInsightsJob($asset->id);
        $job->handle(
            app(VideoInsightsService::class),
            app(AiUsageService::class),
            app(AiTagPolicyService::class),
            app(FileTypeService::class),
            app(VideoAiMinuteEstimator::class),
            app(VideoInsightsSearchIndexWriter::class),
        );

        $asset->refresh();
        $this->assertSame('completed', $asset->metadata['ai_video_status'] ?? null);
    }

    public function test_non_video_asset_clears_orphan_video_ai_queued_or_processing_flags(): void
    {
        [$tenant, $brand, $bucket, $upload] = $this->seedTenantStack();

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'Still',
            'original_filename' => 'still.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.\Illuminate\Support\Str::uuid().'/v1/original.jpg',
            'size_bytes' => 512,
            'metadata' => [
                'ai_video_status' => 'processing',
            ],
        ]);

        $this->mock(VideoInsightsService::class, function ($mock) {
            $mock->shouldNotReceive('analyze');
        });

        $job = new GenerateVideoInsightsJob($asset->id);
        $job->handle(
            app(VideoInsightsService::class),
            app(AiUsageService::class),
            app(AiTagPolicyService::class),
            app(FileTypeService::class),
            app(VideoAiMinuteEstimator::class),
            app(VideoInsightsSearchIndexWriter::class),
        );

        $asset->refresh();
        $this->assertArrayNotHasKey('ai_video_status', $asset->metadata ?? []);
    }

    public function test_video_insights_skipped_when_video_ai_feature_disabled(): void
    {
        config(['assets.video_ai.enabled' => false]);

        [$tenant, $brand, $bucket, $upload] = $this->seedTenantStack();

        $asset = Asset::create(array_merge($this->baseAssetRow($tenant, $brand, $bucket, $upload), [
            'metadata' => [
                'ai_video_status' => 'queued',
            ],
        ]));

        $this->mock(VideoInsightsService::class, function ($mock) {
            $mock->shouldNotReceive('analyze');
        });

        $job = new GenerateVideoInsightsJob($asset->id);
        $job->handle(
            app(VideoInsightsService::class),
            app(AiUsageService::class),
            app(AiTagPolicyService::class),
            app(FileTypeService::class),
            app(VideoAiMinuteEstimator::class),
            app(VideoInsightsSearchIndexWriter::class),
        );

        $asset->refresh();
        $this->assertSame('skipped', $asset->metadata['ai_video_status'] ?? null);
        $this->assertSame('video_ai_disabled', $asset->metadata['ai_video_insights_skip_reason'] ?? null);
    }
}
