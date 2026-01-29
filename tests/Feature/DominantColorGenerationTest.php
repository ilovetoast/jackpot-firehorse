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
 * Dominant Color Generation Test
 *
 * Verifies that dominant colors are generated and persisted after thumbnails complete.
 * Regression test: job runs, colors are persisted to asset_metadata; future refactors cannot silently break it.
 */
class DominantColorGenerationTest extends TestCase
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
     * Test: Dominant colors are generated after thumbnails complete
     *
     * When PopulateAutomaticMetadataJob runs for an image asset with thumbnail_status COMPLETED
     * and color analysis returns cluster data, dominant colors are persisted to asset_metadata.
     */
    public function test_dominant_colors_are_generated_after_thumbnails_complete(): void
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

        PopulateAutomaticMetadataJob::dispatchSync($asset->id);

        $dominantColorsFieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
        $this->assertNotNull($dominantColorsFieldId, 'metadata_fields must have dominant_colors field (run MetadataFieldsSeeder)');

        $this->assertDatabaseHas('asset_metadata', [
            'asset_id' => $asset->id,
            'metadata_field_id' => $dominantColorsFieldId,
        ]);
    }
}
