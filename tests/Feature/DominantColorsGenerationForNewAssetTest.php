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
 * Dominant Colors Generation For New Asset Test
 *
 * Regression test to verify dominant_colors are generated for newly uploaded assets.
 * This test ensures the job completes successfully and persists dominant_colors.
 *
 * Fails loudly if:
 * - Job exits early (no dominant_colors written)
 * - dominant_colors not written to asset_metadata
 * - value_json contains no colors
 */
class DominantColorsGenerationForNewAssetTest extends TestCase
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
     * Test: Dominant colors are generated for newly uploaded image asset
     *
     * Creates a new image asset with thumbnail_status = COMPLETED,
     * runs PopulateAutomaticMetadataJob, and verifies dominant_colors are persisted.
     *
     * Fails loudly if:
     * - Job exits early (no dominant_colors in asset_metadata)
     * - dominant_colors value_json is empty or contains no colors
     */
    public function test_dominant_colors_are_generated_for_new_image_asset(): void
    {
        // Mock color analysis to return valid cluster data
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

        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create new image asset with thumbnail_status = COMPLETED
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'New Test Image',
            'original_filename' => 'new-test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/new-test-image.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED, // Force COMPLETED status
            'metadata' => ['category_id' => $this->category->id],
        ]);

        // Verify asset was created correctly
        $this->assertNotNull($asset->id, 'Asset must be created');
        $this->assertEquals(ThumbnailStatus::COMPLETED, $asset->thumbnail_status, 'thumbnail_status must be COMPLETED');
        $this->assertEquals(AssetStatus::VISIBLE, $asset->status, 'Asset status must be VISIBLE');

        // Get dominant_colors field ID
        $dominantColorsFieldId = DB::table('metadata_fields')
            ->where('key', 'dominant_colors')
            ->value('id');
        
        $this->assertNotNull(
            $dominantColorsFieldId,
            'CRITICAL: metadata_fields must have dominant_colors field. Run MetadataFieldsSeeder.'
        );

        // Verify no dominant_colors exist before job runs
        $beforeCount = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->count();
        
        $this->assertEquals(0, $beforeCount, 'No dominant_colors should exist before job runs');

        // Run PopulateAutomaticMetadataJob synchronously
        try {
            PopulateAutomaticMetadataJob::dispatchSync($asset->id);
        } catch (\Throwable $e) {
            $this->fail(sprintf(
                'CRITICAL: PopulateAutomaticMetadataJob failed with exception: %s - %s',
                get_class($e),
                $e->getMessage()
            ));
        }

        // Refresh asset to get latest state
        $asset->refresh();

        // FAIL LOUDLY: Assert dominant_colors was written to asset_metadata
        $hasDominantColors = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->exists();
        
        $this->assertTrue(
            $hasDominantColors,
            'CRITICAL: dominant_colors must be written to asset_metadata. Job may have exited early.'
        );

        // Get the actual value_json
        $valueJson = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $dominantColorsFieldId)
            ->value('value_json');

        $this->assertNotNull(
            $valueJson,
            'CRITICAL: dominant_colors value_json must not be null. Job may have written empty data.'
        );

        // Decode and verify colors array
        $colors = json_decode($valueJson, true);
        
        $this->assertIsArray(
            $colors,
            'CRITICAL: dominant_colors value_json must be a JSON array. Got: ' . gettype($colors)
        );

        $this->assertGreaterThan(
            0,
            count($colors),
            sprintf(
                'CRITICAL: dominant_colors value_json must contain at least 1 color. Got %d colors. Value: %s',
                count($colors),
                $valueJson
            )
        );

        // Verify each color has required structure
        foreach ($colors as $index => $color) {
            $this->assertIsArray(
                $color,
                sprintf('Color at index %d must be an array', $index)
            );
            $this->assertArrayHasKey(
                'hex',
                $color,
                sprintf('Color at index %d must have hex key', $index)
            );
            $this->assertArrayHasKey(
                'rgb',
                $color,
                sprintf('Color at index %d must have rgb key', $index)
            );
            $this->assertMatchesRegularExpression(
                '/^#[0-9A-Fa-f]{6}$/',
                $color['hex'],
                sprintf('Color at index %d hex must be valid 6-digit hex', $index)
            );
        }
    }
}
