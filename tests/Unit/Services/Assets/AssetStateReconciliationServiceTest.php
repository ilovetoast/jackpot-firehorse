<?php

namespace Tests\Unit\Services\Assets;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Category;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetStateReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_with_pipeline_completed_cannot_remain_uploading(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'temp/test/file.jpg',
            'original_filename' => 'file.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'analysis_status' => 'uploading',
            'thumbnail_status' => ThumbnailStatus::FAILED,
            'metadata' => [
                'pipeline_completed_at' => now()->toIso8601String(),
                'thumbnails_generated' => true,
            ],
        ]);

        $service = app(AssetStateReconciliationService::class);
        $result = $service->reconcile($asset->fresh());

        $this->assertTrue($result['updated']);
        $this->assertNotEmpty($result['changes']);

        $asset->refresh();
        $this->assertSame('complete', $asset->analysis_status);
        $this->assertSame(ThumbnailStatus::COMPLETED->value, $asset->thumbnail_status?->value);
    }

    public function test_rule_7_resets_studio_export_version_and_dispatches_process_job(): void
    {
        Bus::fake();

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'temp/test/file.mp4',
            'original_filename' => 'file.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'analysis_status' => 'uploading',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'source' => 'studio_composition_video_export',
            'metadata' => [],
        ]);

        $version = AssetVersion::query()->create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => 'temp/test/file.mp4',
            'file_size' => 1024,
            'mime_type' => 'video/mp4',
            'width' => 1280,
            'height' => 720,
            'checksum' => 'abc',
            'is_current' => true,
            'pipeline_status' => 'complete',
            'uploaded_by' => null,
        ]);

        $service = app(AssetStateReconciliationService::class);
        $result = $service->reconcile($asset->fresh());

        $this->assertTrue($result['updated']);
        $this->assertContains('Rule 7: version.pipeline_status → pending; ProcessAssetJob dispatched', $result['changes']);

        $version->refresh();
        $this->assertSame('pending', $version->pipeline_status);

        Bus::assertDispatched(ProcessAssetJob::class, function (ProcessAssetJob $job) use ($asset): bool {
            return $job->assetId === $asset->id;
        });
    }

    public function test_rule_7_resets_version_when_eager_thumbnails_ran_before_main_pipeline(): void
    {
        Bus::fake();

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/file.mp4',
            'original_filename' => 'studio-animation-1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::AI_GENERATED,
            'analysis_status' => 'uploading',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'source' => 'studio_animation',
            'metadata' => [
                'thumbnails_generated' => true,
            ],
        ]);

        $version = AssetVersion::query()->create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => 'assets/test/file.mp4',
            'file_size' => 1024,
            'mime_type' => 'video/mp4',
            'width' => 1280,
            'height' => 720,
            'checksum' => 'abc',
            'is_current' => true,
            'pipeline_status' => 'complete',
            'uploaded_by' => null,
            'metadata' => [
                'thumbnails_generated' => true,
            ],
        ]);

        $service = app(AssetStateReconciliationService::class);
        $result = $service->reconcile($asset->fresh());

        $this->assertTrue($result['updated']);
        $this->assertContains('Rule 7: version.pipeline_status → pending; ProcessAssetJob dispatched', $result['changes']);

        $version->refresh();
        $this->assertSame('pending', $version->pipeline_status);

        Bus::assertDispatched(ProcessAssetJob::class, function (ProcessAssetJob $job) use ($asset): bool {
            return $job->assetId === $asset->id;
        });
    }

    public function test_rule_8_sets_published_at_for_completed_studio_export_with_category(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Video',
            'slug' => 'video',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
            'is_hidden' => false,
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'temp/test/file.mp4',
            'original_filename' => 'studio-export-1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::AI_GENERATED,
            'analysis_status' => 'complete',
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'source' => 'studio_composition_video_export',
            'published_at' => null,
            'metadata' => [
                'category_id' => $category->id,
                'pipeline_completed_at' => now()->toIso8601String(),
            ],
        ]);

        $service = app(AssetStateReconciliationService::class);
        $result = $service->reconcile($asset->fresh());

        $this->assertTrue($result['updated']);
        $this->assertContains('Rule 8: published_at set for studio composition video export', $result['changes']);

        $asset->refresh();
        $this->assertNotNull($asset->published_at);
        $this->assertTrue($asset->isVisibleInGrid());
    }

    public function test_rule_9_backfills_storage_bucket_id_for_studio_output_without_bucket(): void
    {
        config(['storage.shared_bucket' => 'test-bucket']);

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => null,
            'storage_root_path' => 'tenants/'.$tenant->id.'/originals/x.mp4',
            'original_filename' => 'anim.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::AI_GENERATED,
            'analysis_status' => 'complete',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'source' => 'studio_animation',
            'metadata' => [],
        ]);

        $service = app(AssetStateReconciliationService::class);
        $result = $service->reconcile($asset->fresh());

        $this->assertTrue($result['updated']);
        $this->assertContains('Rule 9: storage_bucket_id backfilled for studio output asset', $result['changes']);

        $asset->refresh();
        $this->assertSame($bucket->id, $asset->storage_bucket_id);
    }
}
