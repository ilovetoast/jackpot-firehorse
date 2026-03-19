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
 * Dominant Color Persistence Regression Test
 *
 * Ensures dominant colors are persisted canonically to asset_metadata so that:
 * - Dominant colors appear in the asset drawer
 * - Dominant colors are usable by filters
 * - Existing filter + UI code works unchanged
 *
 * This test must fail if dominant colors are only written to assets.metadata.
 */
class DominantColorPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;

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
     * Regression: dominant_colors are written to asset_metadata (canonical), not only assets.metadata.
     *
     * Flow: create image asset with thumbnail_status COMPLETED, mock color analysis clusters,
     * run PopulateAutomaticMetadataJob, assert asset_metadata has row for dominant_colors with
     * value_json as non-empty array of color objects with valid hex.
     *
     * This test fails if dominant colors are only written to assets.metadata.
     */
    public function test_dominant_colors_are_written_to_asset_metadata(): void
    {
        $colorAnalysisResult = [
            'buckets' => ['blue', 'black', 'white'],
            'internal' => [
                'clusters' => [
                    ['lab' => [32, 10, -40], 'rgb' => [31, 58, 138], 'coverage' => 0.42],
                    ['lab' => [20, 0, 0], 'rgb' => [17, 24, 39], 'coverage' => 0.31],
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

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Image',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => ['category_id' => $this->category->id],
        ]);

        $dominantColorsFieldId = DB::table('metadata_fields')
            ->where('key', 'dominant_colors')
            ->value('id');
        $this->assertNotNull($dominantColorsFieldId, 'metadata_fields must have dominant_colors (run MetadataFieldsSeeder)');

        PopulateAutomaticMetadataJob::dispatchSync($asset->id);

        // Assert: asset_metadata has a row for dominant_colors (canonical persistence)
        $row = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->first();

        $this->assertNotNull($row, 'dominant_colors must be written to asset_metadata; writing only to assets.metadata is a regression');

        $decoded = json_decode($row->value_json, true);
        $this->assertIsArray($decoded, 'dominant_colors value_json must decode to an array');
        $this->assertGreaterThan(0, count($decoded), 'dominant_colors value_json must be non-empty');

        foreach ($decoded as $index => $color) {
            $this->assertIsArray($color, "Color at index {$index} must be an array");
            $this->assertArrayHasKey('hex', $color, "Color at index {$index} must have hex");
            $this->assertMatchesRegularExpression(
                '/^#[0-9A-Fa-f]{6}$/',
                $color['hex'],
                "Color at index {$index} hex must be valid 6-digit hex"
            );
        }
    }

}
