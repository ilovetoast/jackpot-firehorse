<?php

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Services\AssetPathGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetPathGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_original_path_returns_canonical_structure(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => StorageBucket::create(['tenant_id' => $tenant->id, 'name' => 'b', 'region' => 'us-east-1', 'status' => StorageBucketStatus::ACTIVE])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'temp/placeholder',
        ]);

        $generator = app(AssetPathGenerator::class);
        $path = $generator->generateOriginalPath($tenant, $asset, 1, 'jpg');

        $this->assertStringStartsWith("tenants/{$tenant->uuid}/assets/{$asset->id}/v1/", $path);
        $this->assertStringEndsWith('original.jpg', $path);
    }

    public function test_generate_thumbnail_path_returns_canonical_structure(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => StorageBucket::create(['tenant_id' => $tenant->id, 'name' => 'b', 'region' => 'us-east-1', 'status' => StorageBucketStatus::ACTIVE])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'temp/placeholder',
        ]);

        $generator = app(AssetPathGenerator::class);
        $path = $generator->generateThumbnailPath($tenant, $asset, 1, 'grid', 'grid.jpg');

        $this->assertEquals("tenants/{$tenant->uuid}/assets/{$asset->id}/v1/thumbnails/grid/grid.jpg", $path);
    }

    public function test_generate_pdf_page_path_returns_canonical_structure(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => StorageBucket::create([
                'tenant_id' => $tenant->id,
                'name' => 'b',
                'region' => 'us-east-1',
                'status' => StorageBucketStatus::ACTIVE,
            ])->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_root_path' => 'temp/placeholder',
        ]);

        $generator = app(AssetPathGenerator::class);
        $path = $generator->generatePdfPagePath($tenant, $asset, 1, 7, 'webp');

        $this->assertSame("tenants/{$tenant->uuid}/assets/{$asset->id}/v1/pdf_pages/page-7.webp", $path);
    }

    public function test_throws_when_tenant_uuid_missing(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        \DB::table('tenants')->where('id', $tenant->id)->update(['uuid' => null]);
        $tenant->refresh();
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b'])->id,
            'storage_bucket_id' => StorageBucket::create(['tenant_id' => $tenant->id, 'name' => 'b', 'region' => 'us-east-1', 'status' => StorageBucketStatus::ACTIVE])->id,
            'title' => 'Test',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'temp/placeholder',
        ]);

        $generator = app(AssetPathGenerator::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant UUID required');
        $generator->generateOriginalPath($tenant, $asset, 1, 'jpg');
    }

    public function test_throws_when_version_less_than_one(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b'])->id,
            'storage_bucket_id' => StorageBucket::create(['tenant_id' => $tenant->id, 'name' => 'b', 'region' => 'us-east-1', 'status' => StorageBucketStatus::ACTIVE])->id,
            'title' => 'Test',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'temp/placeholder',
        ]);

        $generator = app(AssetPathGenerator::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Version must be >= 1');
        $generator->generateOriginalPath($tenant, $asset, 0, 'jpg');
    }
}
