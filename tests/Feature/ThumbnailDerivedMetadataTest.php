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
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Models\SystemIncident;
use App\Services\BrandDNA\BrandComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Thumbnail-Derived Metadata Feature Tests
 *
 * Verifies capability-based thumbnail metadata for SVG, PDF, video.
 * - hasRasterThumbnail, supportsThumbnailMetadata, thumbnailDimensions
 * - Orientation, resolution_class, dominant_colors derived from thumbnails
 */
class ThumbnailDerivedMetadataTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Test', 'slug' => 'test']);
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test',
            'slug' => 'test',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
    }

    protected function createAsset(string $mimeType, string $filename, array $metadata = []): Asset
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

        return Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'mime_type' => $mimeType,
            'original_filename' => $filename,
            'storage_root_path' => 'temp/' . $filename,
            'size_bytes' => 1024,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => array_merge([
                'category_id' => $this->category->id,
                'thumbnails' => [
                    'medium' => ['path' => 'assets/thumbnails/medium/' . $filename],
                ],
                'thumbnail_dimensions' => [
                    'medium' => ['width' => 800, 'height' => 600],
                ],
            ], $metadata),
        ]);
    }

    public function test_has_raster_thumbnail_for_image_svg_pdf_video(): void
    {
        $image = $this->createAsset('image/jpeg', 'test.jpg');
        $svg = $this->createAsset('image/svg+xml', 'test.svg');
        $pdf = $this->createAsset('application/pdf', 'test.pdf');
        $video = $this->createAsset('video/mp4', 'test.mp4');

        $this->assertTrue($image->hasRasterThumbnail());
        $this->assertTrue($svg->hasRasterThumbnail());
        $this->assertTrue($pdf->hasRasterThumbnail());
        $this->assertTrue($video->hasRasterThumbnail());
    }

    public function test_supports_thumbnail_metadata_when_completed_with_medium_path(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $this->assertTrue($asset->supportsThumbnailMetadata());
    }

    public function test_supports_thumbnail_metadata_false_when_status_not_completed(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $asset->thumbnail_status = ThumbnailStatus::PENDING;
        $this->assertFalse($asset->supportsThumbnailMetadata());
    }

    public function test_supports_thumbnail_metadata_false_when_medium_path_missing(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $metadata = $asset->metadata ?? [];
        unset($metadata['thumbnails']['medium']);
        $asset->metadata = $metadata;
        $this->assertFalse($asset->supportsThumbnailMetadata());
    }

    public function test_thumbnail_dimensions_returns_medium_dimensions(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $dims = $asset->thumbnailDimensions('medium');
        $this->assertNotNull($dims);
        $this->assertEquals(800, $dims['width']);
        $this->assertEquals(600, $dims['height']);
    }

    public function test_thumbnail_dimensions_returns_null_when_missing(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $metadata = $asset->metadata ?? [];
        unset($metadata['thumbnail_dimensions']);
        $asset->metadata = $metadata;
        $this->assertNull($asset->thumbnailDimensions('medium'));
    }

    public function test_pdf_supports_thumbnail_metadata(): void
    {
        $pdf = $this->createAsset('application/pdf', 'test.pdf');
        $this->assertTrue($pdf->hasRasterThumbnail());
        $this->assertTrue($pdf->supportsThumbnailMetadata());
    }

    public function test_video_supports_thumbnail_metadata(): void
    {
        $video = $this->createAsset('video/mp4', 'test.mp4');
        $this->assertTrue($video->hasRasterThumbnail());
        $this->assertTrue($video->supportsThumbnailMetadata());
    }

    public function test_visual_metadata_ready_when_dimensions_valid(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $this->assertTrue($asset->visualMetadataReady());
    }

    public function test_visual_metadata_ready_false_when_dimensions_missing(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $metadata = $asset->metadata ?? [];
        unset($metadata['thumbnail_dimensions']);
        $asset->metadata = $metadata;
        $asset->save();
        $asset->refresh();
        $this->assertFalse($asset->visualMetadataReady());
    }

    public function test_visual_metadata_ready_false_when_thumbnail_timeout(): void
    {
        $asset = $this->createAsset('image/jpeg', 'test.jpg');
        $metadata = $asset->metadata ?? [];
        $metadata['thumbnail_timeout'] = true;
        $asset->metadata = $metadata;
        $asset->save();
        $asset->refresh();
        $this->assertFalse($asset->visualMetadataReady());
    }

    /**
     * Upload PDF → delete medium thumbnail dimensions → rerun metadata job.
     * Assert: metadata not populated, no crash, BrandCompliance returns incomplete.
     */
    public function test_pdf_missing_thumbnail_dimensions_metadata_job_defensive(): void
    {
        $asset = $this->createAsset('application/pdf', 'test.pdf');
        $asset->update([
            'analysis_status' => 'extracting_metadata',
            'metadata' => array_merge($asset->metadata ?? [], [
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
            ]),
        ]);

        // Simulate "delete medium thumbnail" / legacy asset: remove dimensions
        $metadata = $asset->metadata ?? [];
        unset($metadata['thumbnail_dimensions']);
        $asset->update(['metadata' => $metadata]);
        $asset->refresh();

        $this->assertTrue($asset->supportsThumbnailMetadata());
        $this->assertFalse($asset->visualMetadataReady());

        // Rerun metadata job — must not crash
        PopulateAutomaticMetadataJob::dispatchSync($asset->id);
        $asset->refresh();

        // Metadata not populated (no dominant colors)
        $dominantColors = $asset->metadata['dominant_colors'] ?? null;
        $this->assertEmpty($dominantColors, 'Metadata should not be populated when thumbnail dimensions missing');

        // BrandCompliance marks incomplete (use existing BrandModel from Brand::created event)
        $brandModel = $this->brand->brandModel ?? BrandModel::create([
            'brand_id' => $this->brand->id,
            'is_enabled' => false,
        ]);
        $brandModel->update(['is_enabled' => true]);
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'scoring_rules' => ['allowed_color_palette' => [['hex' => '#003388']]],
                'scoring_config' => ['color_weight' => 1.0, 'typography_weight' => 0, 'tone_weight' => 0, 'imagery_weight' => 0],
            ],
            'status' => 'active',
        ]);
        $brandModel->update(['active_version_id' => $version->id]);

        $complianceService = app(BrandComplianceService::class);
        $complianceService->scoreAsset($asset, $this->brand);

        $score = \App\Models\BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($score);
        $this->assertSame('incomplete', $score->evaluation_status);

        // Incident created for expected visual metadata missing
        $incident = SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->where('title', 'Expected visual metadata missing')
            ->first();
        $this->assertNotNull($incident, 'Incident should be created when expected visual metadata is missing');
    }
}
