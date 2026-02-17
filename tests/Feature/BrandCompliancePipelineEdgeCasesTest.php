<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandComplianceScore;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\BrandDNA\BrandComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Brand Compliance Pipeline Edge Cases
 *
 * Tests pipeline edge cases without mocking BrandComplianceService.
 * Simulates real job transitions and asset states.
 */
class BrandCompliancePipelineEdgeCasesTest extends TestCase
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
        $this->tenant = Tenant::create(['name' => 'Pipeline Tenant', 'slug' => 'pipeline-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pipeline Brand',
            'slug' => 'pipeline-brand',
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
            'email' => 'pipeline@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Pipeline',
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
            'name' => 'pipeline-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
    }

    protected function createAsset(array $overrides = []): Asset
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

        $asset = Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/test.jpg',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ], $overrides));
        $this->makeAssetComplete($asset);

        return $asset;
    }

    protected function makeAssetComplete(Asset $asset): void
    {
        $updates = [
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'analysis_status' => 'scoring',
            'metadata' => array_merge($asset->metadata ?? [], [
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
            ]),
        ];
        if (str_starts_with($asset->mime_type ?? '', 'image/')) {
            $updates['dominant_hue_group'] = $asset->dominant_hue_group ?? 'green';
        }
        $asset->update($updates);
    }

    protected function enableBrandDnaWithColorPalette(array $allowedPalette): BrandModelVersion
    {
        $brandModel = $this->brand->brandModel;
        if (! $brandModel) {
            $brandModel = BrandModel::create([
                'brand_id' => $this->brand->id,
                'is_enabled' => false,
            ]);
        }

        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'scoring_rules' => [
                    'allowed_color_palette' => $allowedPalette,
                ],
                'scoring_config' => [
                    'color_weight' => 1.0,
                    'typography_weight' => 0,
                    'tone_weight' => 0,
                    'imagery_weight' => 0,
                ],
            ],
            'status' => 'active',
        ]);

        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        return $version;
    }

    protected function setAssetDominantColors(Asset $asset, array $colors): void
    {
        $fieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
        if (! $fieldId) {
            $asset->update(['metadata' => array_merge($asset->metadata ?? [], ['dominant_colors' => $colors])]);
            return;
        }

        DB::table('asset_metadata')->updateOrInsert(
            [
                'asset_id' => $asset->id,
                'metadata_field_id' => $fieldId,
            ],
            [
                'value_json' => json_encode($colors),
                'approved_at' => now(),
                'approved_by' => $this->user->id,
                'source' => 'automatic',
                'confidence' => 1.0,
                'producer' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    protected function setAssetEmbedding(Asset $asset): void
    {
        $vec = array_fill(0, 384, 0.01);
        AssetEmbedding::updateOrCreate(
            ['asset_id' => $asset->id],
            ['embedding_vector' => $vec, 'model' => 'test-model']
        );
    }

    /**
     * Image asset missing dominant_hue_group → evaluation_status = pending_processing.
     */
    public function test_image_missing_hue_group_marks_incomplete(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);
        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [['hex' => '#003388', 'coverage' => 1]]);
        $this->setAssetEmbedding($asset);

        // Simulate: hue group never populated (e.g. PopulateAutomaticMetadataJob failed or not run)
        $asset->update(['dominant_hue_group' => null]);
        $asset->refresh();

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNull($result);
        $row = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('pending_processing', $row->evaluation_status);
        $this->assertNull($row->overall_score);
    }

    /**
     * Image asset missing embedding → evaluation_status = pending_processing.
     */
    public function test_missing_embedding_marks_incomplete(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);
        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [['hex' => '#003388', 'coverage' => 1]]);
        // Do NOT call setAssetEmbedding - simulate embedding job not run

        AssetEmbedding::where('asset_id', $asset->id)->delete();
        $asset->refresh();

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNull($result);
        $row = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('pending_processing', $row->evaluation_status);
        $this->assertNull($row->overall_score);
    }

    /**
     * Non-image asset bypasses image analysis gate (no dominant_colors, hue_group, embedding required).
     */
    public function test_non_image_asset_bypasses_gate(): void
    {
        $brandModel = $this->brand->brandModel;
        if (! $brandModel) {
            $brandModel = BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        }
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'scoring_rules' => [
                    'tone_keywords' => ['professional', 'quality'],
                ],
                'scoring_config' => [
                    'color_weight' => 0,
                    'typography_weight' => 0,
                    'tone_weight' => 1.0,
                    'imagery_weight' => 0,
                ],
            ],
            'status' => 'active',
        ]);
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $asset = $this->createAsset(['mime_type' => 'application/pdf']);
        // No dominant colors, no embedding, no hue group - non-image bypasses
        $asset->update(['dominant_hue_group' => null]);
        AssetEmbedding::where('asset_id', $asset->id)->delete();
        $asset->refresh();

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        // Non-image bypasses isImageAnalysisReady; may score or return not_applicable/incomplete depending on rules
        $row = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($row);
        // Should NOT be pending_processing from image gate (non-image bypassed)
        $this->assertNotSame('pending_processing', $row->evaluation_status);
    }

    /**
     * Reanalysis resets asset state; scoreAsset then marks pending_processing.
     */
    public function test_reanalysis_resets_status_and_score(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);
        $asset = $this->createAsset();
        $this->setAssetEmbedding($asset);
        $this->setAssetDominantColors($asset, [['hex' => '#003388', 'coverage' => 1]]);

        $service = app(BrandComplianceService::class);
        $service->scoreAsset($asset, $this->brand);

        $rowBefore = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($rowBefore);
        $this->assertSame('evaluated', $rowBefore->evaluation_status);
        $this->assertNotNull($rowBefore->overall_score);

        // Simulate reanalysis: reset thumbnail, delete embedding (as reanalyze endpoint does)
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'thumbnail_error' => null,
        ]);
        AssetEmbedding::where('asset_id', $asset->id)->delete();
        $asset->refresh();

        $service->scoreAsset($asset, $this->brand);

        $rowAfter = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNotNull($rowAfter);
        $this->assertSame('pending_processing', $rowAfter->evaluation_status);
        $this->assertNull($rowAfter->overall_score);
    }

    /**
     * Scoring multiple assets does not cross-pollute state.
     */
    public function test_multiple_assets_do_not_cross_state(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388'], ['hex' => '#ff0000']]);

        $assetA = $this->createAsset(['title' => 'Asset A']);
        $this->setAssetEmbedding($assetA);
        $this->setAssetDominantColors($assetA, [['hex' => '#003388', 'coverage' => 1]]);

        $assetB = $this->createAsset(['title' => 'Asset B']);
        $this->setAssetEmbedding($assetB);
        $this->setAssetDominantColors($assetB, [['hex' => '#ff0000', 'coverage' => 1]]);

        $service = app(BrandComplianceService::class);

        $resultA = $service->scoreAsset($assetA, $this->brand);
        $resultB = $service->scoreAsset($assetB, $this->brand);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);

        $rowA = BrandComplianceScore::where('asset_id', $assetA->id)->where('brand_id', $this->brand->id)->first();
        $rowB = BrandComplianceScore::where('asset_id', $assetB->id)->where('brand_id', $this->brand->id)->first();

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame($assetA->id, $rowA->asset_id);
        $this->assertSame($assetB->id, $rowB->asset_id);
        $this->assertSame('evaluated', $rowA->evaluation_status);
        $this->assertSame('evaluated', $rowB->evaluation_status);

        // Asset A (blue) should score high; Asset B (red) should score high (both in palette)
        $this->assertGreaterThan(0, $rowA->overall_score);
        $this->assertGreaterThan(0, $rowB->overall_score);
    }
}
