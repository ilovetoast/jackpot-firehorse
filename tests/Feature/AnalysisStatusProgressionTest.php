<?php

namespace Tests\Feature;

use App\Contracts\ImageEmbeddingServiceInterface;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\GenerateAssetEmbeddingJob;
use App\Jobs\PopulateAutomaticMetadataJob;
use App\Jobs\ProcessAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandVisualReference;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Automation\ColorAnalysisService;
use App\Services\BrandDNA\BrandComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Analysis Status Progression Test
 *
 * Verifies that analysis_status transitions correctly through the pipeline:
 * uploading → generating_thumbnails → extracting_metadata → generating_embedding → scoring → complete
 */
class AnalysisStatusProgressionTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
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

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
    }

    protected function createImageAsset(array $overrides = []): Asset
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
            'title' => 'Test Image',
            'original_filename' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test-image.jpg',
            'metadata' => ['category_id' => $this->category->id],
            'thumbnail_status' => ThumbnailStatus::PENDING,
        ], $overrides));
    }

    /**
     * Simulate job chain and assert correct analysis_status transitions.
     */
    public function test_analysis_status_progression_for_image_asset(): void
    {
        $colorResult = [
            'buckets' => ['blue', 'black'],
            'internal' => [
                'clusters' => [
                    ['lab' => [32, 10, -40], 'rgb' => [31, 58, 138], 'coverage' => 0.5],
                    ['lab' => [20, 0, 0], 'rgb' => [17, 24, 39], 'coverage' => 0.5],
                ],
                'ignored_pixels' => 0.0,
            ],
        ];
        $this->mock(ColorAnalysisService::class, fn ($mock) => $mock->shouldReceive('analyze')->andReturn($colorResult));

        Bus::fake();

        // 1. ProcessAssetJob: upload finishes → generating_thumbnails
        $asset = $this->createImageAsset();
        $this->assertSame('uploading', $asset->analysis_status ?? 'uploading');

        $processJob = new ProcessAssetJob($asset->id);
        $processJob->handle();
        $asset->refresh();
        $this->assertSame('generating_thumbnails', $asset->analysis_status, 'ProcessAssetJob should set generating_thumbnails');

        // 2. GenerateThumbnailsJob: thumbnails complete → extracting_metadata
        // (GenerateThumbnailsJob requires S3 for verification; we simulate the transition)
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'analysis_status' => 'extracting_metadata',
            'metadata' => array_merge($asset->metadata ?? [], [
                'thumbnails' => [
                    'thumb' => ['path' => 'assets/test/thumb.jpg'],
                    'medium' => ['path' => 'assets/test/medium.jpg'],
                    'large' => ['path' => 'assets/test/large.jpg'],
                ],
            ]),
        ]);
        $asset->refresh();
        $this->assertSame('extracting_metadata', $asset->analysis_status, 'After thumbnails: extracting_metadata');

        // 3. PopulateAutomaticMetadataJob: dominant colors + hue group stored → generating_embedding
        $metaJob = new PopulateAutomaticMetadataJob($asset->id);
        $metaJob->handle(
            app(\App\Services\AutomaticMetadataWriter::class),
            app(\App\Services\MetadataSchemaResolver::class),
            app(ColorAnalysisService::class),
            app(\App\Services\Automation\DominantColorsExtractor::class)
        );
        $asset->refresh();
        $this->assertSame('generating_embedding', $asset->analysis_status, 'PopulateAutomaticMetadataJob should set generating_embedding');

        // 4. GenerateAssetEmbeddingJob: embedding saved → scoring
        $mockVector = array_fill(0, 64, 0.5);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $mockVector)));
        $mockVector = array_map(fn ($x) => $x / $norm, $mockVector);
        $this->mock(ImageEmbeddingServiceInterface::class, function ($mock) use ($mockVector) {
            $mock->shouldReceive('embedAsset')->once()->andReturn($mockVector);
        });

        $embedJob = new GenerateAssetEmbeddingJob($asset->id);
        $embedJob->handle(app(ImageEmbeddingServiceInterface::class));
        $asset->refresh();
        $this->assertSame('scoring', $asset->analysis_status, 'GenerateAssetEmbeddingJob should set scoring');

        // 5. BrandComplianceService::scoreAsset: scoring finishes → complete
        // Ensure asset meets completion criteria (thumbnail_status, ai_tagging_completed, metadata_extracted)
        $metadata = $asset->metadata ?? [];
        $metadata['ai_tagging_completed'] = true;
        $metadata['metadata_extracted'] = true;
        $asset->update(['metadata' => $metadata]);

        $brandModel = BrandModel::firstOrCreate(
            ['brand_id' => $this->brand->id],
            ['is_enabled' => false]
        );
        $vec = array_fill(0, 64, 0.5);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vec)));
        $vec = array_map(fn ($x) => $x / $norm, $vec);
        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'type' => BrandVisualReference::TYPE_LOGO,
        ]);
        $version = BrandModelVersion::firstOrCreate(
            ['brand_model_id' => $brandModel->id, 'version_number' => 1],
            [
                'source_type' => 'manual',
                'model_payload' => [
                    'scoring_rules' => [],
                    'scoring_config' => [
                        'color_weight' => 0,
                        'typography_weight' => 0,
                        'tone_weight' => 0,
                        'imagery_weight' => 1.0,
                    ],
                ],
                'status' => 'active',
            ]
        );
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);
        $asset->refresh();

        $this->assertNotNull($result, 'Scoring should succeed when analysis_status is scoring');
        $this->assertSame('complete', $asset->analysis_status, 'BrandComplianceService should set complete when scoring finishes');
    }

    /**
     * When a pipeline job runs with unexpected analysis_status, it must not mutate state.
     * Jobs log a warning and exit without updating analysis_status.
     */
    public function test_invalid_status_transition_does_not_mutate_state(): void
    {
        $colorResult = [
            'buckets' => ['blue', 'black'],
            'internal' => [
                'clusters' => [
                    ['lab' => [32, 10, -40], 'rgb' => [31, 58, 138], 'coverage' => 0.5],
                    ['lab' => [20, 0, 0], 'rgb' => [17, 24, 39], 'coverage' => 0.5],
                ],
                'ignored_pixels' => 0.0,
            ],
        ];
        $this->mock(ColorAnalysisService::class, fn ($mock) => $mock->shouldReceive('analyze')->andReturn($colorResult));

        Bus::fake();

        // 1. ProcessAssetJob expects 'uploading' — asset has 'extracting_metadata' → abort
        $asset = $this->createImageAsset(['analysis_status' => 'extracting_metadata']);
        $beforeStatus = $asset->analysis_status;

        $processJob = new ProcessAssetJob($asset->id);
        $processJob->handle();
        $asset->refresh();

        $this->assertSame($beforeStatus, $asset->analysis_status, 'ProcessAssetJob must not mutate when status is wrong');

        // 2. PopulateAutomaticMetadataJob expects 'extracting_metadata' — asset has 'scoring' → abort
        $asset->update([
            'analysis_status' => 'scoring',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => array_merge($asset->metadata ?? [], ['category_id' => $this->category->id]),
        ]);
        $beforeStatus = 'scoring';

        $metaJob = new PopulateAutomaticMetadataJob($asset->id);
        $metaJob->handle(
            app(\App\Services\AutomaticMetadataWriter::class),
            app(\App\Services\MetadataSchemaResolver::class),
            app(ColorAnalysisService::class),
            app(\App\Services\Automation\DominantColorsExtractor::class)
        );
        $asset->refresh();

        $this->assertSame($beforeStatus, $asset->analysis_status, 'PopulateAutomaticMetadataJob must not mutate when status is wrong');

        // 3. GenerateAssetEmbeddingJob expects 'generating_embedding' — asset has 'extracting_metadata' → abort
        $asset->update(['analysis_status' => 'extracting_metadata']);
        $beforeStatus = 'extracting_metadata';

        $mockVector = array_fill(0, 64, 0.5);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $mockVector)));
        $mockVector = array_map(fn ($x) => $x / $norm, $mockVector);
        $this->mock(ImageEmbeddingServiceInterface::class, function ($mock) use ($mockVector) {
            $mock->shouldReceive('embedAsset')->never();
        });

        $embedJob = new GenerateAssetEmbeddingJob($asset->id);
        $embedJob->handle(app(ImageEmbeddingServiceInterface::class));
        $asset->refresh();

        $this->assertSame($beforeStatus, $asset->analysis_status, 'GenerateAssetEmbeddingJob must not mutate when status is wrong');
    }
}
