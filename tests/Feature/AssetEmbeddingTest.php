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
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\BrandVisualReference;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\BrandDNA\BrandComplianceService;
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
     * Cosine similarity returns expected range (tested via scoreImagerySimilarity).
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

        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'type' => BrandVisualReference::TYPE_LOGO,
        ]);

        $brandModel = $this->brand->brandModel ?: BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
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
        ]);
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $imagery = $result['breakdown_payload']['imagery'] ?? null;
        $this->assertSame('scored', $imagery['status'] ?? null);
        $this->assertSame(100, $imagery['score'] ?? 0);
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

        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'type' => BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
        ]);

        $brandModel = $this->brand->brandModel ?: BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
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
        ]);
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $this->assertSame('scored', $result['breakdown_payload']['imagery']['status'] ?? null);
        $this->assertGreaterThanOrEqual(0, $result['breakdown_payload']['imagery']['score'] ?? -1);
        $this->assertLessThanOrEqual(100, $result['breakdown_payload']['imagery']['score'] ?? 101);
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

        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'type' => BrandVisualReference::TYPE_LOGO,
        ]);
        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $vec,
            'type' => BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
        ]);

        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $centroid,
            'model' => 'test',
        ]);

        $brandModel = $this->brand->brandModel ?: BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
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
        ]);
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $imagery = $result['breakdown_payload']['imagery'] ?? null;
        $this->assertSame('scored', $imagery['status'] ?? null);
        $this->assertSame(100, $imagery['score'] ?? 0, 'Asset equal to centroid should score 100');
        $this->assertSame('Visual similarity to brand centroid', $imagery['reason'] ?? null);
    }

    /**
     * Centroid similarity: score differs when centroid (average of refs) differs from single reference.
     */
    public function test_centroid_similarity_differs_from_single_reference(): void
    {
        $asset = $this->createImageAsset();

        // Ref1: [1,0,0,...], Ref2: [0,1,0,...] -> centroid = [0.5, 0.5, 0, ...]
        $dim = 64;
        $ref1 = array_fill(0, $dim, 0);
        $ref1[0] = 1;
        $ref2 = array_fill(0, $dim, 0);
        $ref2[1] = 1;

        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $ref1,
            'type' => BrandVisualReference::TYPE_LOGO,
        ]);
        BrandVisualReference::create([
            'brand_id' => $this->brand->id,
            'asset_id' => $asset->id,
            'embedding_vector' => $ref2,
            'type' => BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
        ]);

        // Asset matches ref1 only (not centroid)
        AssetEmbedding::create([
            'asset_id' => $asset->id,
            'embedding_vector' => $ref1,
            'model' => 'test',
        ]);

        $brandModel = $this->brand->brandModel ?: BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
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
        ]);
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $service = app(BrandComplianceService::class);
        $resultTwoRefs = $service->scoreAsset($asset, $this->brand);

        // Single ref: centroid = ref1, asset = ref1 -> score 100
        BrandVisualReference::where('brand_id', $this->brand->id)
            ->where('type', BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE)
            ->delete();
        $resultOneRef = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($resultTwoRefs);
        $this->assertNotNull($resultOneRef);
        $scoreTwoRefs = $resultTwoRefs['breakdown_payload']['imagery']['score'] ?? -1;
        $scoreOneRef = $resultOneRef['breakdown_payload']['imagery']['score'] ?? -1;
        $this->assertNotEquals($scoreOneRef, $scoreTwoRefs, 'Centroid of 2 refs should differ from single ref');
    }
}
