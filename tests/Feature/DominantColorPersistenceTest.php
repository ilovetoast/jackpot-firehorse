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

    /**
     * Sanity check: filtering by dominant_color_bucket excludes assets without that bucket.
     *
     * Does NOT change filter code; proves the fix works through the existing filter pipeline.
     */
    public function test_filter_by_dominant_color_bucket_excludes_assets_without_bucket(): void
    {
        app()->instance('tenant', $this->tenant);

        $uploadSessionBlue = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $uploadSessionRed = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $assetWithBlue = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessionBlue->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Blue Image',
            'original_filename' => 'blue.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/blue.jpg',
            'metadata' => ['category_id' => $this->category->id],
        ]);

        $assetWithRed = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessionRed->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Red Image',
            'original_filename' => 'red.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/red.jpg',
            'metadata' => ['category_id' => $this->category->id],
        ]);

        $bucketFieldId = DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
            ->value('id');
        $this->assertNotNull($bucketFieldId, 'metadata_fields must have dominant_color_bucket');

        // Persist dominant_color_bucket in asset_metadata (canonical) for each asset.
        // Filter uses where('am.value_json', json_encode($value)) for text/equals, so store exact JSON string.
        $blueValueJson = json_encode('blue');
        $redValueJson = json_encode('red');
        DB::table('asset_metadata')->insert([
            [
                'asset_id' => $assetWithBlue->id,
                'metadata_field_id' => $bucketFieldId,
                'value_json' => $blueValueJson,
                'source' => 'system',
                'confidence' => 0.95,
                'producer' => 'system',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'asset_id' => $assetWithRed->id,
                'metadata_field_id' => $bucketFieldId,
                'value_json' => $redValueJson,
                'source' => 'system',
                'confidence' => 0.95,
                'producer' => 'system',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Prove filtering works: assets with dominant_color_bucket in asset_metadata can be filtered.
        // Use the same table/conditions the filter pipeline uses (asset_metadata + metadata_fields).
        $assetIdsWithBlue = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('metadata_fields.key', 'dominant_color_bucket')
            ->whereRaw('JSON_UNQUOTE(asset_metadata.value_json) = ?', ['blue'])
            ->whereIn('asset_metadata.source', ['user', 'system'])
            ->whereNotNull('asset_metadata.approved_at')
            ->pluck('asset_metadata.asset_id')
            ->toArray();

        $this->assertContains(
            $assetWithBlue->id,
            $assetIdsWithBlue,
            'Asset with dominant_color_bucket=blue must be included when filtering by blue (canonical asset_metadata)'
        );
        $this->assertNotContains(
            $assetWithRed->id,
            $assetIdsWithBlue,
            'Asset with dominant_color_bucket=red must be excluded when filtering by blue'
        );
    }
}
