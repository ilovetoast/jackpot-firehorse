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
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandReferenceAsset;
use App\Models\BrandVisualReference;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
use App\Services\BrandIntelligence\BrandIntelligenceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssetEmbeddingTest extends TestCase
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

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'view brand', 'guard_name' => 'web']);
        $this->tenant = Tenant::create(['name' => 'Embedding Tenant', 'slug' => 'embedding-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Embedding Brand',
            'slug' => 'embedding-brand',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Deliverables',
            'slug' => 'deliverables',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
        ]);
        $this->user = User::create([
            'email' => 'embedding@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Embedding',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'view brand']);
        $this->user->setRoleForTenant($this->tenant, 'member');
        $this->user->assignRole($role);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'embedding-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        BrandVisualReference::withoutEvents(function () {
            BrandVisualReference::create([
                'brand_id' => $this->brand->id,
                'asset_id' => null,
                'type' => BrandVisualReference::TYPE_LOGO,
                'embedding_vector' => null,
            ]);
        });

        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->method('fetchThumbnailForVisionAnalysis')->willReturn(null);
        $this->app->instance(AiMetadataGenerationService::class, $meta);

        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->never())->method('analyzeImage');
        $this->app->instance(AIProviderInterface::class, $ai);
    }

    protected function createImageAsset(): Asset
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
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Test Image',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test.jpg',
            'metadata' => [
                'category_id' => $this->category->id,
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
                'thumbnails' => ['medium' => ['path' => 'assets/test/thumb.jpg']],
                'dominant_colors' => [['hex' => '#003388', 'coverage' => 1]],
            ],
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'analysis_status' => 'scoring',
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
    }

    /**
     * @param  list<float>  $vec
     */
    protected function seedStyleReferences(Brand $brand, Asset $asset, array $vec, int $count = 3): void
    {
        BrandVisualReference::withoutEvents(function () use ($brand, $asset, $vec, $count) {
            for ($i = 0; $i < $count; $i++) {
                BrandVisualReference::create([
                    'brand_id' => $brand->id,
                    'asset_id' => $asset->id,
                    'embedding_vector' => $vec,
                    'type' => BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
                    'reference_type' => BrandVisualReference::REFERENCE_TYPE_STYLE,
                    'reference_tier' => BrandVisualReference::TIER_GUIDELINE,
                ]);
            }
        });
    }

    /**
     * Asset embedding is generated when job runs (mocked to avoid API calls).
     */
    public function test_asset_embedding_is_generated(): void
    {
        $mockVector = array_fill(0, 512, 0.1);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $mockVector)));
        $mockVector = array_map(fn ($x) => $x / $norm, $mockVector);

        $mock = $this->createMock(ImageEmbeddingServiceInterface::class);
        $mock->method('embedAsset')->willReturn($mockVector);
        $this->app->instance(ImageEmbeddingServiceInterface::class, $mock);

        $asset = $this->createImageAsset();

        $job = new GenerateAssetEmbeddingJob($asset->id);
        $job->handle(app(ImageEmbeddingServiceInterface::class));

        $this->assertDatabaseHas('asset_embeddings', [
            'asset_id' => $asset->id,
        ]);
        $embedding = AssetEmbedding::where('asset_id', $asset->id)->first();
        $this->assertNotNull($embedding);
        $this->assertCount(512, $embedding->embedding_vector);
    }

    /**
     * Cosine similarity maps to reference similarity score (0–100) via Brand Intelligence.
     */
    public function test_cosine_similarity_returns_expected_range(): void
    {
        $asset = $this->createImageAsset();

        // Identical vectors -> cosine = 1 -> score 100
        $vec = array_fill(0, 64, 1.0 / 8);
        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'model' => 'test',
        ]);

        $this->seedStyleReferences($this->brand, $asset, $vec, 3);

        $engine = app(BrandIntelligenceEngine::class);
        $result = $engine->scoreAsset($asset->fresh());

        $this->assertNotNull($result);
        $ref = $result['breakdown_json']['reference_similarity'] ?? [];
        $this->assertTrue($ref['used'] ?? false);
        $this->assertFalse($ref['fallback_used'] ?? true);
        $this->assertSame(100, $ref['score_percent'] ?? 0);
    }

    /**
     * Imagery similarity scores when refs exist and asset has embedding.
     */
    public function test_imagery_similarity_scores_when_refs_exist(): void
    {
        $asset = $this->createImageAsset();
        $vec = array_fill(0, 64, 0.5);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vec)));
        $vec = array_map(fn ($x) => $x / $norm, $vec);

        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'model' => 'test',
        ]);

        $this->seedStyleReferences($this->brand, $asset, $vec, 3);

        $engine = app(BrandIntelligenceEngine::class);
        $result = $engine->scoreAsset($asset->fresh());

        $this->assertNotNull($result);
        $ref = $result['breakdown_json']['reference_similarity'] ?? [];
        $this->assertTrue($ref['used'] ?? false);
        $this->assertGreaterThanOrEqual(0, $ref['score_percent'] ?? -1);
        $this->assertLessThanOrEqual(100, $ref['score_percent'] ?? 101);
    }

    /**
     * Centroid similarity: asset equal to centroid of two refs scores near 100.
     */
    public function test_centroid_similarity_is_average_of_references(): void
    {
        $asset = $this->createImageAsset();

        // Two refs with identical vectors; centroid = same vector
        $vec = array_fill(0, 64, 1.0 / 8);
        $centroid = $vec;

        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $centroid,
            'model' => 'test',
        ]);

        $this->seedStyleReferences($this->brand, $asset, $vec, 3);

        $engine = app(BrandIntelligenceEngine::class);
        $result = $engine->scoreAsset($asset->fresh());

        $this->assertNotNull($result);
        $ref = $result['breakdown_json']['reference_similarity'] ?? [];
        $this->assertTrue($ref['used'] ?? false);
        $this->assertSame(100, $ref['score_percent'] ?? 0);
    }

    /**
     * Matching reference pool yields higher similarity than an orthogonal pool (requires ≥3 style refs).
     */
    public function test_embedding_similarity_higher_when_refs_match_asset_than_mismatched(): void
    {
        $asset = $this->createImageAsset();
        $dim = 64;

        $assetVec = array_fill(0, $dim, 0.0);
        $assetVec[0] = 1.0;
        $an = sqrt(array_sum(array_map(fn ($x) => $x * $x, $assetVec)));
        $assetVec = array_map(fn ($x) => $x / $an, $assetVec);

        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $assetVec,
            'model' => 'test',
        ]);

        $this->seedStyleReferences($this->brand, $asset, $assetVec, 3);

        $engine = app(BrandIntelligenceEngine::class);
        $high = $engine->scoreAsset($asset->fresh());

        BrandVisualReference::where('brand_id', $this->brand->id)->delete();

        $bad = array_fill(0, $dim, 0.0);
        $bad[$dim - 1] = 1.0;
        $bn = sqrt(array_sum(array_map(fn ($x) => $x * $x, $bad)));
        $bad = array_map(fn ($x) => $x / $bn, $bad);

        $this->seedStyleReferences($this->brand, $asset, $bad, 3);

        $low = $engine->scoreAsset($asset->fresh());

        $this->assertNotNull($high);
        $this->assertNotNull($low);
        $highPct = $high['breakdown_json']['reference_similarity']['score_percent'] ?? 0;
        $lowPct = $low['breakdown_json']['reference_similarity']['score_percent'] ?? 0;
        $this->assertGreaterThan($lowPct, $highPct);
    }

    public function test_fallback_used_when_style_reference_count_below_three(): void
    {
        $asset = $this->createImageAsset();
        $vec = array_fill(0, 64, 1.0 / 8);

        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'model' => 'test',
        ]);

        $this->seedStyleReferences($this->brand, $asset, $vec, 2);

        $engine = app(BrandIntelligenceEngine::class);
        $result = $engine->scoreAsset($asset->fresh());

        $this->assertNotNull($result);
        $ref = $result['breakdown_json']['reference_similarity'] ?? [];
        $this->assertFalse($ref['used'] ?? true);
        $this->assertTrue($ref['fallback_used'] ?? false);
    }

    public function test_scoring_uses_promoted_brand_reference_assets(): void
    {
        $dim = 64;
        $parallel = array_fill(0, $dim, 0.0);
        $parallel[0] = 1.0;
        $pn = sqrt(array_sum(array_map(fn ($x) => $x * $x, $parallel)));
        $parallel = array_map(fn ($x) => $x / $pn, $parallel);

        $ortho = array_fill(0, $dim, 0.0);
        $ortho[1] = 1.0;
        $on = sqrt(array_sum(array_map(fn ($x) => $x * $x, $ortho)));
        $ortho = array_map(fn ($x) => $x / $on, $ortho);

        $scored = $this->createImageAsset();
        AssetEmbedding::create([
            'asset_id' => $scored->id,
            'embedding_vector' => $parallel,
            'model' => 'test',
        ]);

        $vectors = [$ortho, $parallel, $parallel];
        foreach ($vectors as $vec) {
            $a = $this->createImageAsset();
            AssetEmbedding::create([
                'asset_id' => $a->id,
                'embedding_vector' => $vec,
                'model' => 'test',
            ]);
            BrandReferenceAsset::create([
                'brand_id' => $this->brand->id,
                'asset_id' => $a->id,
                'reference_type' => BrandReferenceAsset::REFERENCE_TYPE_STYLE,
                'tier' => BrandReferenceAsset::TIER_REFERENCE,
                'weight' => 0.6,
                'created_by' => $this->user->id,
            ]);
        }

        $engine = app(BrandIntelligenceEngine::class);
        $result = $engine->scoreAsset($scored->fresh());

        $ref = $result['breakdown_json']['reference_similarity'] ?? [];
        $this->assertTrue($ref['used'] ?? false);
        $this->assertFalse($ref['fallback_used'] ?? true);
    }

    public function test_tier_weighting_affects_reference_similarity_score(): void
    {
        $dim = 64;
        $parallel = array_fill(0, $dim, 0.0);
        $parallel[0] = 1.0;
        $pn = sqrt(array_sum(array_map(fn ($x) => $x * $x, $parallel)));
        $parallel = array_map(fn ($x) => $x / $pn, $parallel);

        // Weak match: cosine ~0.25 with $parallel (above NOISE_SIMILARITY_FLOOR 0.2, below strong matches)
        $weak = array_fill(0, $dim, 0.0);
        $weak[0] = 0.25;
        $weak[1] = sqrt(1.0 - 0.25 * 0.25);
        $wn = sqrt(array_sum(array_map(fn ($x) => $x * $x, $weak)));
        $weak = array_map(fn ($x) => $x / $wn, $weak);

        $run = function (bool $badRefAsGuideline) use ($parallel, $weak) {
            BrandReferenceAsset::where('brand_id', $this->brand->id)->delete();

            $scored = $this->createImageAsset();
            AssetEmbedding::create([
                'asset_id' => $scored->id,
                'embedding_vector' => $parallel,
                'model' => 'test',
            ]);

            $vectors = [$weak, $parallel, $parallel];
            foreach ($vectors as $idx => $vec) {
                $a = $this->createImageAsset();
                AssetEmbedding::create([
                    'asset_id' => $a->id,
                    'embedding_vector' => $vec,
                    'model' => 'test',
                ]);
                $isBad = $idx === 0;
                if ($isBad && $badRefAsGuideline) {
                    BrandReferenceAsset::create([
                        'brand_id' => $this->brand->id,
                        'asset_id' => $a->id,
                        'reference_type' => BrandReferenceAsset::REFERENCE_TYPE_STYLE,
                        'tier' => BrandReferenceAsset::TIER_GUIDELINE,
                        'weight' => 1.0,
                        'created_by' => $this->user->id,
                    ]);
                } else {
                    BrandReferenceAsset::create([
                        'brand_id' => $this->brand->id,
                        'asset_id' => $a->id,
                        'reference_type' => BrandReferenceAsset::REFERENCE_TYPE_STYLE,
                        'tier' => BrandReferenceAsset::TIER_REFERENCE,
                        'weight' => 0.6,
                        'created_by' => $this->user->id,
                    ]);
                }
            }

            $engine = app(BrandIntelligenceEngine::class);

            return $engine->scoreAsset($scored->fresh());
        };

        $whenBadIsGuideline = $run(true);
        $whenBadIsReference = $run(false);

        $pctGuideline = $whenBadIsGuideline['breakdown_json']['reference_similarity']['score_percent'] ?? 0;
        $pctReference = $whenBadIsReference['breakdown_json']['reference_similarity']['score_percent'] ?? 0;

        $this->assertGreaterThan($pctGuideline, $pctReference);
    }
}
