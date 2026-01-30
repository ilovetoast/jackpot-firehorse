<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Automation\ColorAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AVIF dominant color extraction test.
 *
 * Verifies that when an AVIF asset has thumbnail_status=COMPLETED and a medium
 * thumbnail path, color analysis runs (against the thumbnail) and dominant colors
 * are persisted. Color analysis now uses the generated thumbnail (JPEG/PNG), not
 * the original AVIF file.
 */
class AvifDominantColorExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

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

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Image Category',
            'slug' => 'image-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
    }

    /**
     * AVIF asset with thumbnail completed and medium path: color analysis is invoked
     * (mocked to return cluster data) and dominant colors are persisted.
     */
    public function test_avif_asset_with_thumbnail_completed_gets_dominant_colors_persisted(): void
    {
        $colorAnalysisResult = [
            'buckets' => ['blue', 'green', 'white'],
            'internal' => [
                'clusters' => [
                    ['lab' => [32, 10, -40], 'rgb' => [31, 58, 138], 'coverage' => 0.42],
                    ['lab' => [55, -30, 25], 'rgb' => [34, 139, 34], 'coverage' => 0.31],
                    ['lab' => [98, 0, 0], 'rgb' => [249, 250, 251], 'coverage' => 0.15],
                ],
                'ignored_pixels' => 0.0,
            ],
        ];

        $this->mock(ColorAnalysisService::class, function ($mock) use ($colorAnalysisResult) {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn($colorAnalysisResult);
        });

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // AVIF asset with thumbnail_status COMPLETED and medium thumbnail path set
        // (analysis runs on this thumbnail, not the original AVIF)
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'AVIF Test Image',
            'original_filename' => 'test.avif',
            'mime_type' => 'image/avif',
            'size_bytes' => 2048,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test.avif',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'category_id' => $this->category->id,
                'thumbnails' => [
                    'medium' => ['path' => 'assets/test/thumbnails/medium-test.avif.jpg'],
                ],
            ],
        ]);

        $this->assertSame(ThumbnailStatus::COMPLETED, $asset->thumbnail_status);
        $this->assertNotNull($asset->thumbnailPathForStyle('medium'));

        $dominantColorsFieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
        $this->assertNotNull($dominantColorsFieldId);

        $beforeCount = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->count();
        $this->assertEquals(0, $beforeCount);

        PopulateAutomaticMetadataJob::dispatchSync($asset->id);

        $hasDominantColors = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->exists();
        $this->assertTrue($hasDominantColors, 'dominant_colors must be written for AVIF asset with thumbnail');

        $valueJson = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->value('value_json');
        $colors = json_decode($valueJson, true);
        $this->assertIsArray($colors);
        $this->assertGreaterThan(0, count($colors));
        $this->assertArrayHasKey('hex', $colors[0]);
        $this->assertArrayHasKey('rgb', $colors[0]);
    }
}
