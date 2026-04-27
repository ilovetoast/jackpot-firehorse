<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AiTagPolicyService;
use App\Services\FileInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetProcessingWorkerBudgetPipelineTest extends TestCase
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
            'slug' => 'test-tenant-wb',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-wb',
        ]);

        $this->user = User::create([
            'email' => 'wb-user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket-wb',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    /**
     * Normal JPEG within limits should still dispatch the main pipeline (thumbnails first).
     */
    public function test_ten_megabyte_jpeg_dispatches_thumbnail_chain_on_staging_small(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'assets.processing.throttle_enabled' => false,
        ]);
        $this->mock(AiTagPolicyService::class, function ($mock) {
            $mock->shouldReceive('shouldProceedWithAiTagging')
                ->andReturn(['should_proceed' => true, 'reason' => 'allowed']);
        });
        $this->mock(FileInspectionService::class, function ($mock) {
            $mock->shouldReceive('inspect')->andReturn([
                'mime_type' => 'image/jpeg',
                'file_size' => 10 * 1024 * 1024,
                'width' => 2000,
                'height' => 2000,
                'is_image' => true,
            ]);
        });

        Bus::fake()->except([ProcessAssetJob::class]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'JPEG',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10 * 1024 * 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'tenants/x/assets/y/v1/original.jpg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'uploading',
        ]);

        $version = AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => 'tenants/x/assets/y/v1/original.jpg',
            'file_size' => 10 * 1024 * 1024,
            'mime_type' => 'image/jpeg',
            'checksum' => 'test-checksum-jpeg',
            'pipeline_status' => 'pending',
            'is_current' => true,
            'metadata' => [],
        ]);
        ProcessAssetJob::dispatchSync($version->id);

        Bus::assertDispatched(GenerateThumbnailsJob::class);
    }

    /**
     * 1 GB PSD on staging_small must not dispatch the heavy thumbnail chain from this worker.
     */
    public function test_oversized_psd_on_staging_small_does_not_dispatch_main_chain(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'asset_processing.profiles.staging_small.max_psd_mb' => 250,
            'assets.processing.throttle_enabled' => false,
            'asset_processing.defer_heavy_to_queue' => false,
        ]);
        $this->mock(AiTagPolicyService::class, function ($mock) {
            $mock->shouldReceive('shouldProceedWithAiTagging')
                ->andReturn(['should_proceed' => false, 'reason' => 'test']);
        });

        Bus::fake()->except([ProcessAssetJob::class]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);

        $psdBytes = 1024 * 1024 * 1024;
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'PSD',
            'original_filename' => 'huge.psd',
            'mime_type' => 'image/vnd.adobe.photoshop',
            'size_bytes' => $psdBytes,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'tenants/x/assets/y/v1/original.psd',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'uploading',
            'metadata' => [],
        ]);

        $version = AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => 'tenants/x/assets/y/v1/original.psd',
            'file_size' => $psdBytes,
            'mime_type' => 'image/vnd.adobe.photoshop',
            'checksum' => 'test-checksum-psd',
            'pipeline_status' => 'pending',
            'is_current' => true,
            'metadata' => [],
        ]);
        ProcessAssetJob::dispatchSync($version->id);

        Bus::assertNotDispatched(GenerateThumbnailsJob::class);

        $asset->refresh();
        $this->assertSame(ThumbnailStatus::SKIPPED, $asset->thumbnail_status);
        $this->assertSame('deferred_to_heavy_worker', $asset->metadata['worker_processing_code'] ?? null);
        $this->assertStringContainsString('heavy media worker', strtolower($asset->metadata['worker_processing_message'] ?? ''));
        $version->refresh();
        $this->assertSame('complete', $version->pipeline_status);
    }

    public function test_defer_heavy_to_queue_dispatches_process_job_on_target_queue(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'asset_processing.profiles.staging_small.max_psd_mb' => 250,
            'asset_processing.profiles.heavy.max_psd_mb' => 1500,
            'assets.processing.throttle_enabled' => false,
            'asset_processing.defer_heavy_to_queue' => true,
            'queue.images_heavy_queue' => 'images-heavy',
            // PSD must route to a queue name different from `images` so defer re-dispatch runs (not short-circuit).
            'queue.images_psd_queue' => 'images-psd',
            'queue.images_queue' => 'images',
        ]);

        Queue::fake();

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);

        $psdBytes = 1024 * 1024 * 1024;
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'PSD',
            'original_filename' => 'huge.psd',
            'mime_type' => 'image/vnd.adobe.photoshop',
            'size_bytes' => $psdBytes,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'tenants/x/assets/y/v1/original.psd',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'analysis_status' => 'uploading',
        ]);

        $version = AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => 'tenants/x/assets/y/v1/original.psd',
            'file_size' => $psdBytes,
            'mime_type' => 'image/vnd.adobe.photoshop',
            'checksum' => 'test-checksum-psd-defer',
            'pipeline_status' => 'pending',
            'is_current' => true,
            'metadata' => [],
        ]);

        $job = new ProcessAssetJob($version->id);
        $job->onQueue('images');
        $job->handle();

        Queue::assertPushed(ProcessAssetJob::class, function (ProcessAssetJob $j): bool {
            return $j->queue === 'images-psd';
        });
    }

    public function test_thumbnail_preferred_remains_off_when_config_disabled(): void
    {
        config([
            'assets.thumbnail.preferred.enabled' => false,
            'asset_processing.worker_profile' => 'normal',
        ]);
        $this->assertFalse((bool) config('assets.thumbnail.preferred.enabled'));
    }
}
