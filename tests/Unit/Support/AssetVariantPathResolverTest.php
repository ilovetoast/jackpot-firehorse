<?php

namespace Tests\Unit\Support;

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
use App\Services\AssetVariantPathResolver;
use App\Support\AssetVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for AssetVariantPathResolver.
 */
class AssetVariantPathResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function createAsset(array $overrides = []): Asset
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
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

        return Asset::create(array_merge([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'Test',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/' . $tenant->uuid . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.jpg',
            'size_bytes' => 1024,
        ], $overrides));
    }

    public function test_resolve_original_returns_storage_root_path(): void
    {
        $asset = $this->createAsset(['storage_root_path' => 'tenants/abc/assets/123/v1/original.jpg']);

        $resolver = app(AssetVariantPathResolver::class);
        $path = $resolver->resolve($asset, AssetVariant::ORIGINAL->value);

        $this->assertSame('tenants/abc/assets/123/v1/original.jpg', $path);
    }

    public function test_resolve_thumb_small_uses_metadata_when_available(): void
    {
        $asset = $this->createAsset([
            'storage_root_path' => 'tenants/abc/assets/123/v1/original.jpg',
            'metadata' => [
                'thumbnails' => [
                    'thumb' => ['path' => 'tenants/abc/assets/123/v1/thumbnails/thumb/thumb.webp'],
                ],
            ],
        ]);

        $resolver = app(AssetVariantPathResolver::class);
        $path = $resolver->resolve($asset, AssetVariant::THUMB_SMALL->value);

        $this->assertSame('tenants/abc/assets/123/v1/thumbnails/thumb/thumb.webp', $path);
    }

    public function test_resolve_thumb_small_fallback_when_no_metadata(): void
    {
        $asset = $this->createAsset(['storage_root_path' => 'tenants/abc/assets/123/v1/original.jpg']);

        $resolver = app(AssetVariantPathResolver::class);
        $path = $resolver->resolve($asset, AssetVariant::THUMB_SMALL->value);

        $this->assertStringContainsString('thumbnails/thumb/', $path);
        $this->assertStringEndsWith('.webp', $path);
    }

    public function test_resolve_video_preview_uses_column_when_available(): void
    {
        $asset = $this->createAsset([
            'storage_root_path' => 'tenants/abc/assets/123/v1/original.mp4',
            'video_preview_url' => 'tenants/abc/assets/123/v1/previews/video_preview.mp4',
        ]);

        $resolver = app(AssetVariantPathResolver::class);
        $path = $resolver->resolve($asset, AssetVariant::VIDEO_PREVIEW->value);

        $this->assertSame('tenants/abc/assets/123/v1/previews/video_preview.mp4', $path);
    }

    public function test_resolve_pdf_page_with_options(): void
    {
        $asset = $this->createAsset(['storage_root_path' => 'tenants/abc/assets/123/v1/original.pdf']);

        $resolver = app(AssetVariantPathResolver::class);
        $path = $resolver->resolve($asset, AssetVariant::PDF_PAGE->value, ['page' => 5]);

        $this->assertStringContainsString('pdf_pages/page-5.webp', $path);
    }
}
