<?php

namespace Tests\Unit\Services\Assets;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
