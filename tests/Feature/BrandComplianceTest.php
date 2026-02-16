<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\ScoreAssetComplianceJob;
use App\Models\Asset;
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
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Brand Compliance Stabilization + Color Intelligence tests.
 *
 * PART 10: Color match, mismatch, no dominant color, rescore endpoint, sorting, unscored filter.
 */
class BrandComplianceTest extends TestCase
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
        $this->tenant = Tenant::create(['name' => 'Compliance Tenant', 'slug' => 'compliance-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Compliance Brand',
            'slug' => 'compliance-brand',
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
            'email' => 'compliance@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Compliance',
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
            'name' => 'compliance-bucket',
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

        return Asset::create(array_merge([
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
    }

    protected function enableBrandDnaWithColorPalette(array $allowedPalette): BrandModelVersion
    {
        $brandModel = $this->brand->brandModel;
        if (!$brandModel) {
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
        if (!$fieldId) {
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

    /**
     * Color match success: dominant color in allowed palette → full color score.
     */
    public function test_color_match_success_using_dominant_colors(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388'], ['hex' => '#ffffff']]);

        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [
            ['hex' => '#003388', 'coverage' => 0.5],
            ['hex' => '#ff0000', 'coverage' => 0.3],
        ]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $this->assertSame(100, $result['color_score']);
        $this->assertSame(100, $result['overall_score']);
        $this->assertSame('scored', $result['breakdown_payload']['color']['status']);
        $this->assertDatabaseHas('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'evaluation_status' => 'evaluated',
        ]);
    }

    /**
     * Color match: accepts both #hex and hex format (case insensitive).
     */
    public function test_color_match_accepts_hex_with_or_without_hash(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '003388'], ['hex' => '#FFFFFF']]);

        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [
            ['hex' => '#003388', 'coverage' => 0.6],
            ['hex' => 'ffffff', 'coverage' => 0.2],
        ]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $this->assertSame(100, $result['color_score']);
    }

    /**
     * Color mismatch: dominant colors not in allowed palette → 0 color score.
     */
    public function test_color_mismatch_returns_zero_score(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388'], ['hex' => '#ffffff']]);

        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [
            ['hex' => '#ff0000', 'coverage' => 0.5],
            ['hex' => '#00ff00', 'coverage' => 0.3],
        ]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['color_score']);
        $this->assertSame(0, $result['overall_score']);
        $this->assertSame('scored', $result['breakdown_payload']['color']['status']);
    }

    /**
     * No dominant color → evaluation_status incomplete, overall_score null.
     */
    public function test_no_dominant_color_returns_null_overall_score(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);

        $asset = $this->createAsset();
        // No dominant colors set

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNull($result);
        $this->assertDatabaseHas('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'evaluation_status' => 'incomplete',
        ]);
        $row = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNull($row->overall_score);
    }

    /**
     * Rescore endpoint dispatches ScoreAssetComplianceJob.
     */
    public function test_rescore_endpoint_dispatches_job(): void
    {
        Queue::fake();

        $asset = $this->createAsset();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/rescore");

        $response->assertStatus(200);
        $response->assertJson(['status' => 'queued']);
        Queue::assertPushed(ScoreAssetComplianceJob::class, fn ($job) => $job->assetId === $asset->id);
    }

    /**
     * Rescore endpoint returns 404 for asset from different brand.
     */
    public function test_rescore_endpoint_validates_tenant_and_brand(): void
    {
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);
        $asset = $this->createAsset(['brand_id' => $otherBrand->id]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/assets/{$asset->id}/rescore");

        $response->assertStatus(404);
    }

    /**
     * Sorting by compliance_score works on Deliverables.
     * Verifies sort params are accepted and Asset model scopes apply correct order.
     */
    public function test_sorting_by_compliance_score_works(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388'], ['hex' => '#ffffff']]);

        $assetLow = $this->createAsset();
        $this->setAssetDominantColors($assetLow, [['hex' => '#ff0000', 'coverage' => 1]]);

        $assetHigh = $this->createAsset();
        $this->setAssetDominantColors($assetHigh, [['hex' => '#003388', 'coverage' => 1]]);

        app(BrandComplianceService::class)->scoreAsset($assetLow, $this->brand);
        app(BrandComplianceService::class)->scoreAsset($assetHigh, $this->brand);

        // Verify sort params are accepted: endpoint returns 200 with compliance_high
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/deliverables?category={$this->category->slug}&sort=compliance_high&sort_direction=desc");

        $response->assertStatus(200);
        $props = $response->inertiaPage()['props'] ?? [];
        $this->assertSame('compliance_high', $props['sort'] ?? null);
        $this->assertSame('desc', $props['sort_direction'] ?? null);

        // Verify Asset query with compliance sort returns high-scoring asset before low-scoring
        $assetSortService = app(\App\Services\AssetSortService::class);
        $query = Asset::query()
            ->select('assets.*')
            ->where('assets.tenant_id', $this->tenant->id)
            ->where('assets.brand_id', $this->brand->id)
            ->where('assets.type', AssetType::DELIVERABLE)
            ->whereIn('assets.id', [$assetLow->id, $assetHigh->id]);
        $assetSortService->applySort($query, 'compliance_high', 'desc');
        $ordered = $query->get();
        $ids = $ordered->pluck('id')->values()->toArray();
        $this->assertCount(2, $ids, 'Should return both assets');
        $this->assertSame($assetHigh->id, $ids[0], 'High-scoring asset should be first when sort=compliance_high desc');
        $this->assertSame($assetLow->id, $ids[1], 'Low-scoring asset should be second');
    }

    /**
     * Unscored filter returns assets without compliance row.
     */
    public function test_unscored_filter_returns_assets_without_compliance(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);

        $assetScored = $this->createAsset();
        $this->setAssetDominantColors($assetScored, [['hex' => '#003388', 'coverage' => 1]]);
        app(BrandComplianceService::class)->scoreAsset($assetScored, $this->brand);

        $assetUnscored = $this->createAsset();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get("/app/deliverables?category={$this->category->slug}&compliance_filter=unscored");

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertContains($assetUnscored->id, $ids, 'Unscored asset should be in unscored filter results');
        $this->assertNotContains($assetScored->id, $ids, 'Scored asset should NOT be in unscored filter results');
    }

    /**
     * evaluation_status = not_applicable when no scoring rules configured.
     */
    public function test_evaluation_status_not_applicable_when_no_rules(): void
    {
        $brandModel = $this->brand->brandModel;
        if (!$brandModel) {
            $brandModel = BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        }
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'scoring_rules' => [
                    'allowed_color_palette' => [],
                    'allowed_fonts' => [],
                    'tone_keywords' => [],
                    'photography_attributes' => [],
                ],
                'scoring_config' => ['color_weight' => 0.25, 'typography_weight' => 0.25, 'tone_weight' => 0.25, 'imagery_weight' => 0.25],
            ],
            'status' => 'active',
        ]);
        $brandModel->update(['is_enabled' => true, 'active_version_id' => $version->id]);

        $asset = $this->createAsset();
        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNull($result);
        $this->assertDatabaseHas('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'evaluation_status' => 'not_applicable',
        ]);
        $row = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNull($row->overall_score);
    }

    /**
     * evaluation_status = incomplete when rules exist but metadata missing.
     */
    public function test_evaluation_status_incomplete_when_metadata_missing(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);
        $asset = $this->createAsset();
        // No dominant colors

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNull($result);
        $this->assertDatabaseHas('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'evaluation_status' => 'incomplete',
        ]);
        $row = BrandComplianceScore::where('asset_id', $asset->id)->where('brand_id', $this->brand->id)->first();
        $this->assertNull($row->overall_score);
    }

    /**
     * evaluation_status = evaluated when at least one dimension scored.
     */
    public function test_evaluation_status_evaluated_when_scored(): void
    {
        $this->enableBrandDnaWithColorPalette([['hex' => '#003388']]);
        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [['hex' => '#003388', 'coverage' => 1]]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNotNull($result);
        $this->assertNotNull($result['overall_score']);
        $this->assertDatabaseHas('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
            'evaluation_status' => 'evaluated',
        ]);
    }

    /**
     * No compliance row when Brand DNA disabled.
     */
    public function test_no_row_written_when_brand_dna_disabled(): void
    {
        $brandModel = $this->brand->brandModel;
        if (!$brandModel) {
            $brandModel = BrandModel::create(['brand_id' => $this->brand->id, 'is_enabled' => false]);
        }
        $version = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'scoring_rules' => ['allowed_color_palette' => [['hex' => '#003388']]],
                'scoring_config' => ['color_weight' => 1, 'typography_weight' => 0, 'tone_weight' => 0, 'imagery_weight' => 0],
            ],
            'status' => 'active',
        ]);
        $brandModel->update(['is_enabled' => false, 'active_version_id' => $version->id]);

        $asset = $this->createAsset();
        $this->setAssetDominantColors($asset, [['hex' => '#003388', 'coverage' => 1]]);

        $service = app(BrandComplianceService::class);
        $result = $service->scoreAsset($asset, $this->brand);

        $this->assertNull($result);
        $this->assertDatabaseMissing('brand_compliance_scores', [
            'asset_id' => $asset->id,
            'brand_id' => $this->brand->id,
        ]);
    }
}
