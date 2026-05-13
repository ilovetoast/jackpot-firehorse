<?php

namespace Tests\Unit\Jobs;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateVideoPreviewJob;
use App\Jobs\GenerateVideoWebPlaybackJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\Assets\AssetProcessingBudgetService;
use App\Services\Assets\ProcessingBudgetDecision;
use App\Services\VideoWebPlaybackGenerationService;
use App\Services\VideoWebPlaybackOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GenerateVideoWebPlaybackJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createVideoAsset(string $filename, string $mime, array $metadata = []): Asset
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-vw']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-vw']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket-vw',
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

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'Vid',
            'original_filename' => $filename,
            'mime_type' => $mime,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.\Illuminate\Support\Str::uuid().'/v1/'.$filename,
            'size_bytes' => 1024,
            'metadata' => $metadata,
        ]);
    }

    public function test_dispatches_hover_preview_from_video_web_after_success(): void
    {
        Queue::fake();
        config(['assets.video.web_playback.enabled' => true]);
        $asset = $this->createVideoAsset('a.avi', 'video/x-msvideo', [
            'video' => ['preview_deferred_for_web_playback' => true],
        ]);

        $opt = Mockery::mock(VideoWebPlaybackOptimizationService::class);
        $opt->shouldReceive('decide')->andReturn([
            'should_generate' => true,
            'strategy' => 'transcode',
            'reason' => 'forced_extension',
            'extension' => 'avi',
            'source_mime' => 'video/x-msvideo',
        ]);

        $gen = Mockery::mock(VideoWebPlaybackGenerationService::class);
        $gen->shouldReceive('transcodeAndStore')->once()->andReturn([
            'success' => true,
            'path' => 'tenants/x/previews/video_web.mp4',
            'size_bytes' => 5000,
        ]);

        $budget = Mockery::mock(AssetProcessingBudgetService::class);
        $budget->shouldReceive('classify')->andReturn(
            ProcessingBudgetDecision::allowed('test', 1024, 'video/x-msvideo')
        );
        $this->app->instance(AssetProcessingBudgetService::class, $budget);

        (new GenerateVideoWebPlaybackJob((string) $asset->id))->handle($opt, $gen);

        Queue::assertPushed(GenerateVideoPreviewJob::class, function (GenerateVideoPreviewJob $job) use ($asset): bool {
            return (string) $job->assetId === (string) $asset->id
                && $job->previewSourceMode === GenerateVideoPreviewJob::HOVER_PREVIEW_FROM_VIDEO_WEB;
        });
    }

    public function test_dispatches_hover_preview_from_original_after_transcode_failure_when_deferred(): void
    {
        Queue::fake();
        config(['assets.video.web_playback.enabled' => true]);
        $asset = $this->createVideoAsset('a.avi', 'video/x-msvideo', [
            'video' => ['preview_deferred_for_web_playback' => true],
        ]);

        $opt = Mockery::mock(VideoWebPlaybackOptimizationService::class);
        $opt->shouldReceive('decide')->andReturn([
            'should_generate' => true,
            'strategy' => 'transcode',
            'reason' => 'forced_extension',
            'extension' => 'avi',
            'source_mime' => 'video/x-msvideo',
        ]);

        $gen = Mockery::mock(VideoWebPlaybackGenerationService::class);
        $gen->shouldReceive('transcodeAndStore')->once()->andReturn(['success' => false, 'error' => 'ffmpeg_not_found']);
        $gen->shouldReceive('mergeFailedMetadata')->once();

        $budget = Mockery::mock(AssetProcessingBudgetService::class);
        $budget->shouldReceive('classify')->andReturn(
            ProcessingBudgetDecision::allowed('test', 1024, 'video/x-msvideo')
        );
        $this->app->instance(AssetProcessingBudgetService::class, $budget);

        (new GenerateVideoWebPlaybackJob((string) $asset->id))->handle($opt, $gen);

        Queue::assertPushed(GenerateVideoPreviewJob::class, function (GenerateVideoPreviewJob $job) use ($asset): bool {
            return (string) $job->assetId === (string) $asset->id
                && $job->previewSourceMode === GenerateVideoPreviewJob::HOVER_PREVIEW_FROM_ORIGINAL_AFTER_WEB_FAILED;
        });
    }

    public function test_budget_skip_dispatches_original_preview_when_deferred(): void
    {
        Queue::fake();
        config(['assets.video.web_playback.enabled' => true]);
        $asset = $this->createVideoAsset('a.avi', 'video/x-msvideo', [
            'video' => ['preview_deferred_for_web_playback' => true],
        ]);

        $opt = Mockery::mock(VideoWebPlaybackOptimizationService::class);
        $opt->shouldReceive('decide')->andReturn([
            'should_generate' => true,
            'strategy' => 'transcode',
            'reason' => 'forced_extension',
            'extension' => 'avi',
            'source_mime' => 'video/x-msvideo',
        ]);

        $gen = Mockery::mock(VideoWebPlaybackGenerationService::class);
        $gen->shouldReceive('mergeSkippedMetadata')->once();
        $gen->shouldNotReceive('transcodeAndStore');

        $budget = Mockery::mock(AssetProcessingBudgetService::class);
        $budget->shouldReceive('classify')->andReturn(
            new ProcessingBudgetDecision(
                ProcessingBudgetDecision::SKIP_UNSUPPORTED_ON_WORKER,
                'test',
                'blocked',
                'staging_small',
                1024,
                null,
                'video/x-msvideo'
            )
        );
        $this->app->instance(AssetProcessingBudgetService::class, $budget);

        (new GenerateVideoWebPlaybackJob((string) $asset->id))->handle($opt, $gen);

        Queue::assertPushed(GenerateVideoPreviewJob::class, function (GenerateVideoPreviewJob $job) use ($asset): bool {
            return (string) $job->assetId === (string) $asset->id
                && $job->previewSourceMode === GenerateVideoPreviewJob::HOVER_PREVIEW_FROM_ORIGINAL;
        });
    }

    public function test_merges_failed_when_transcode_returns_error_without_ffmpeg(): void
    {
        Queue::fake();
        config(['assets.video.web_playback.enabled' => true]);
        $asset = $this->createVideoAsset('a.avi', 'video/x-msvideo');

        $opt = Mockery::mock(VideoWebPlaybackOptimizationService::class);
        $opt->shouldReceive('decide')->andReturn([
            'should_generate' => true,
            'strategy' => 'transcode',
            'reason' => 'forced_extension',
            'extension' => 'avi',
            'source_mime' => 'video/x-msvideo',
        ]);

        $gen = Mockery::mock(VideoWebPlaybackGenerationService::class);
        $gen->shouldReceive('transcodeAndStore')->once()->andReturn(['success' => false, 'error' => 'ffmpeg_not_found']);
        $gen->shouldReceive('mergeFailedMetadata')->once();

        $budget = Mockery::mock(AssetProcessingBudgetService::class);
        $budget->shouldReceive('classify')->andReturn(
            ProcessingBudgetDecision::allowed('test', 1024, 'video/x-msvideo')
        );
        $this->app->instance(AssetProcessingBudgetService::class, $budget);

        (new GenerateVideoWebPlaybackJob((string) $asset->id))->handle($opt, $gen);

        Queue::assertNothingPushed();
    }

    public function test_returns_early_when_feature_disabled(): void
    {
        config(['assets.video.web_playback.enabled' => false]);
        $asset = $this->createVideoAsset('a.avi', 'video/x-msvideo');
        $opt = Mockery::mock(VideoWebPlaybackOptimizationService::class);
        $opt->shouldNotReceive('decide');
        $gen = Mockery::mock(VideoWebPlaybackGenerationService::class);
        $gen->shouldNotReceive('transcodeAndStore');
        (new GenerateVideoWebPlaybackJob((string) $asset->id))->handle($opt, $gen);
    }
}
