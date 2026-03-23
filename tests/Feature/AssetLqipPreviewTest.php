<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AssetDeliveryService;
use App\Services\ThumbnailGenerationService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LQIP (tiny blurred preview) is stored at metadata.preview_thumbnails.preview.path (S3 key), not blurhash.
 * Delivery uses AssetVariant::THUMB_PREVIEW → preview_thumbnail_url on API responses.
 */
class AssetLqipPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'LQIP Tenant', 'slug' => 'lqip-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'LQIP Brand',
            'slug' => 'lqip-brand',
        ]);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'lqip-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    public function test_thumb_preview_delivery_url_when_metadata_has_preview_path(): void
    {
        config(['cloudfront.domain' => 'cdn.example.com']);

        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $previewPath = 'tenants/'.$this->tenant->uuid.'/assets/test-asset/v1/thumbnails/preview/preview.webp';

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'LQIP',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$this->tenant->uuid.'/assets/test-asset/v1/original.jpg',
            'size_bytes' => 1024,
            'metadata' => [
                'preview_thumbnails' => [
                    'preview' => [
                        'path' => $previewPath,
                        'width' => 32,
                        'height' => 32,
                    ],
                ],
            ],
        ]);

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $asset,
            AssetVariant::THUMB_PREVIEW->value,
            DeliveryContext::AUTHENTICATED->value
        );

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('cdn.example.com', $url);
        $this->assertStringContainsString('preview.webp', $url);
    }

    public function test_thumb_preview_delivery_empty_without_preview_path(): void
    {
        config(['cloudfront.domain' => 'cdn.example.com']);

        $upload = UploadSession::create([
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
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'No LQIP',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$this->tenant->uuid.'/assets/test-asset/v1/original.jpg',
            'size_bytes' => 1024,
            'metadata' => [],
        ]);

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $asset,
            AssetVariant::THUMB_PREVIEW->value,
            DeliveryContext::AUTHENTICATED->value
        );

        $this->assertSame('', $url);
    }

    public function test_persist_early_lqip_metadata_merges_preview_thumbnails(): void
    {
        $upload = UploadSession::create([
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
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Merge',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$this->tenant->uuid.'/assets/test-asset/v1/original.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => 1],
        ]);

        $previewThumbnails = [
            'preview' => [
                'path' => 's3/key/preview.webp',
                'width' => 32,
                'height' => 32,
            ],
        ];

        $service = app(ThumbnailGenerationService::class);
        $method = new \ReflectionMethod(ThumbnailGenerationService::class, 'persistEarlyLqipMetadata');
        $method->setAccessible(true);
        $method->invoke($service, $asset, $previewThumbnails, null);

        $asset->refresh();
        $this->assertSame('s3/key/preview.webp', $asset->metadata['preview_thumbnails']['preview']['path']);
        $this->assertSame(1, $asset->metadata['category_id']);
    }
}
