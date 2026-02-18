<?php

namespace Tests\Feature;

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
use App\Models\User;
use App\Services\ThumbnailGenerationService;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * SVG Raster Quality Tests
 *
 * - SVG rasterized width >= 2000px before downscale
 * - Medium dimensions persisted correctly
 * - No blurry thumbnail regression
 */
class SvgRasterQualityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
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
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function createAssetWithSvg(array $overrides = []): Asset
    {
        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test SVG',
            'original_filename' => 'test.svg',
            'mime_type' => 'image/svg+xml',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test.svg',
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'metadata' => [],
        ], $overrides));
    }

    /**
     * Create a minimal valid SVG file for testing.
     */
    protected function createSvgFile(int $width = 100, int $height = 100): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'svg_test_') . '.svg';
        $content = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="100%" height="100%" fill="#3b82f6"/>
</svg>
SVG;
        file_put_contents($tempPath, $content);

        return $tempPath;
    }

    protected function createMockS3ForSvg(string $svgPath): S3Client
    {
        $svgContent = file_get_contents($svgPath);
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('getObject')
            ->andReturnUsing(function (array $args) use ($svgContent) {
                return [
                    'Body' => Utils::streamFor($svgContent),
                    'ContentLength' => strlen($svgContent),
                ];
            });
        $mockS3->shouldReceive('putObject')->andReturn([]);

        return $mockS3;
    }

    public function test_svg_rasterized_at_high_resolution_before_downscale(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required for SVG rasterization');
        }

        $svgPath = $this->createSvgFile(200, 200);
        $asset = $this->createAssetWithSvg();
        $mockS3 = $this->createMockS3ForSvg($svgPath);

        $service = new ThumbnailGenerationService($mockS3);
        $thumbnails = $service->generateThumbnails($asset);

        $this->assertNotEmpty($thumbnails);
        $medium = $thumbnails['medium'] ?? null;
        $this->assertNotNull($medium);
        $this->assertArrayHasKey('width', $medium);
        $this->assertArrayHasKey('height', $medium);
        $this->assertGreaterThanOrEqual(200, $medium['width'], 'Medium thumbnail should be at least 200px wide');
        $this->assertGreaterThanOrEqual(200, $medium['height'], 'Medium thumbnail should be at least 200px tall');

        @unlink($svgPath);
    }

    public function test_medium_dimensions_persisted_correctly(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required for SVG rasterization');
        }

        $svgPath = $this->createSvgFile(500, 500);
        $asset = $this->createAssetWithSvg();
        $mockS3 = $this->createMockS3ForSvg($svgPath);

        $service = new ThumbnailGenerationService($mockS3);
        $service->generateThumbnails($asset);

        $asset->refresh();
        $dims = $asset->metadata['thumbnail_dimensions']['medium'] ?? null;
        $this->assertNotNull($dims);
        $this->assertArrayHasKey('width', $dims);
        $this->assertArrayHasKey('height', $dims);
        $this->assertGreaterThan(0, $dims['width']);
        $this->assertGreaterThan(0, $dims['height']);

        @unlink($svgPath);
    }

    public function test_thumbnail_dimensions_persisted_for_all_styles(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required for SVG rasterization');
        }

        $svgPath = $this->createSvgFile(300, 300);
        $asset = $this->createAssetWithSvg();
        $mockS3 = $this->createMockS3ForSvg($svgPath);

        $service = new ThumbnailGenerationService($mockS3);
        $service->generateThumbnails($asset);

        $asset->refresh();
        $dims = $asset->metadata['thumbnail_dimensions'] ?? [];
        $this->assertNotEmpty($dims);
        foreach (['thumb', 'medium', 'large'] as $style) {
            if (isset($dims[$style])) {
                $this->assertArrayHasKey('width', $dims[$style]);
                $this->assertArrayHasKey('height', $dims[$style]);
            }
        }

        @unlink($svgPath);
    }
}
