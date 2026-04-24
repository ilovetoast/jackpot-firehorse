<?php

namespace Tests\Unit\Studio;

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
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use App\Studio\Rendering\StudioGoogleFontFileCache;
use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioRenderingFontResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioTenantFontStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_font_asset_stages_to_local_cache(): void
    {
        Storage::fake('s3');
        Config::set('studio_rendering.font_cache_dir', 'studio/font-cache-test-'.Str::random(8));
        Config::set('studio_rendering.default_font_path', '');

        [$tenant, $brand, $asset] = $this->createFontAssetOnS3();

        $cache = new StudioRenderingFontFileCache;
        $path = $cache->materializeFromAsset($tenant, (int) $brand->id, $asset, (string) $asset->storage_root_path);
        $this->assertFileExists($path);
        $this->assertStringEndsWith('.ttf', strtolower($path));
        $path2 = $cache->materializeFromAsset($tenant, (int) $brand->id, $asset, (string) $asset->storage_root_path);
        $this->assertSame($path, $path2);
    }

    public function test_font_asset_wrong_brand_throws(): void
    {
        Config::set('studio_rendering.default_font_path', '');

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.Str::random(6)]);
        $brandA = Brand::create(['tenant_id' => $tenant->id, 'name' => 'A', 'slug' => 'a-'.Str::random(4)]);
        $brandB = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-'.Str::random(4)]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bk-'.Str::random(4),
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brandB->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brandB->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => null,
            'title' => 'Font',
            'original_filename' => 'font.ttf',
            'mime_type' => 'font/ttf',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'fonts/x.ttf',
            'size_bytes' => 10,
        ]);

        $resolver = new StudioRenderingFontResolver(new StudioRenderingFontFileCache, new StudioGoogleFontFileCache);
        try {
            $resolver->resolveForTextLayer(
                $tenant,
                (int) $brandA->id,
                ['font_asset_id' => (string) $asset->id, 'font_family' => 'F'],
                'F',
            );
            $this->fail('Expected StudioFontResolutionException');
        } catch (StudioFontResolutionException $e) {
            $this->assertSame('font_asset_wrong_brand', $e->errorCode);
        }
    }

    public function test_materialize_rejects_woff_asset_extension(): void
    {
        Storage::fake('s3');
        Config::set('studio_rendering.font_cache_dir', 'studio/font-cache-test-'.Str::random(8));
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.Str::random(6)]);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-'.Str::random(4)]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bk-'.Str::random(4),
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);
        $key = 'fonts/x.woff';
        Storage::disk('s3')->put($key, str_repeat('w', 200));
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => null,
            'title' => 'Bad font',
            'original_filename' => 'x.woff',
            'mime_type' => 'font/woff',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => $key,
            'size_bytes' => 200,
        ]);
        $cache = new StudioRenderingFontFileCache;
        $this->expectException(StudioFontResolutionException::class);
        $this->expectExceptionMessage('not supported');
        $cache->materializeFromAsset($tenant, (int) $brand->id, $asset, $key);
    }

    public function test_cache_key_suffix_changes_when_asset_updated_at_changes(): void
    {
        Storage::fake('s3');
        Config::set('studio_rendering.font_cache_dir', 'studio/font-cache-test-'.Str::random(8));
        [$tenant, $brand, $asset] = $this->createFontAssetOnS3();
        $cache = new StudioRenderingFontFileCache;
        $h1 = $cache->buildCacheKeySuffix($asset, (string) $asset->storage_root_path);
        $asset->touch();
        $asset->refresh();
        $h2 = $cache->buildCacheKeySuffix($asset, (string) $asset->storage_root_path);
        $this->assertNotSame($h1, $h2);
    }

    /**
     * @return array{0: Tenant, 1: Brand, 2: Asset}
     */
    private function createFontAssetOnS3(): array
    {
        $tenant = Tenant::create(['name' => 'FT', 'slug' => 'ft-'.Str::random(6)]);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'FB', 'slug' => 'fb-'.Str::random(6)]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'b-'.Str::random(4),
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);
        $key = 'tenant-fonts/'.Str::uuid().'/font.ttf';
        Storage::disk('s3')->put($key, str_repeat('OTTO', 200));
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => null,
            'title' => 'Font',
            'original_filename' => 'font.ttf',
            'mime_type' => 'font/ttf',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => $key,
            'size_bytes' => 800,
        ]);

        return [$tenant, $brand, $asset];
    }
}
