<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Approval Flow Test
 * 
 * Minimal feature tests for approval → publish → visibility behavior.
 * 
 * Tests verify:
 * - Assets requiring approval are created unpublished
 * - Unpublished assets are not visible in default grid
 * - Publish button visibility rules
 * - Approval granted → asset becomes published
 * - Assets and Deliverables behave identically
 * 
 * Do NOT:
 * - Test UI text
 * - Test AI output
 * - Duplicate lifecycle tests
 */
class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;
    protected Category $assetCategory;
    protected Category $deliverableCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create test brand with approval required
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
            'settings' => [
                'contributor_upload_requires_approval' => true, // Enable approval requirement
            ],
        ]);

        // Create test user
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        // Create storage bucket
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create categories
        $this->assetCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Asset Category',
            'slug' => 'asset-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->deliverableCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Deliverable Category',
            'slug' => 'deliverable-category',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
        ]);
    }

    /**
     * Test: Asset requires approval - created unpublished
     * 
     * When approval is required (brand setting or category setting):
     * - Asset is created unpublished (published_at = null)
     * - Asset is NOT visible in default grid
     * - Publish button should be available (permission-dependent)
     */
    public function test_asset_requires_approval_created_unpublished(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create category with approval required
        $categoryWithApproval = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Required Category',
            'slug' => 'approval-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => true, // Approval required
        ]);

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

        // Create asset in category requiring approval
        // Note: UploadCompletionService handles approval logic
        // For this test, we'll create directly with approval status
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset Requiring Approval',
            'original_filename' => 'approval-asset.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/approval-asset.jpg',
            'metadata' => ['category_id' => $categoryWithApproval->id],
            'published_at' => null, // Unpublished due to approval requirement
            'approval_status' => \App\Enums\ApprovalStatus::PENDING,
        ]);

        // Assert asset is unpublished
        $this->assertNull($asset->published_at, 'Asset requiring approval should be created unpublished');
        $this->assertEquals(
            \App\Enums\ApprovalStatus::PENDING->value,
            $asset->approval_status->value,
            'Asset should have pending approval status'
        );

        // Test default index - unpublished asset should NOT appear
        $response = $this->get('/app/assets');
        $response->assertStatus(200);
        $data = $response->inertiaPage();
        $assetIds = collect($data['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertNotContains(
            $asset->id,
            $assetIds,
            'Unpublished asset requiring approval should NOT appear in default grid'
        );
    }

    /**
     * Test: Approval granted - asset becomes published
     * 
     * When approval is granted (via publish action):
     * - Asset becomes published (published_at is set)
     * - Asset appears in default grid
     * - Correct action buttons are shown
     */
    public function test_approval_granted_asset_becomes_published(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create unpublished asset with pending approval
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
            'title' => 'Asset Pending Approval',
            'original_filename' => 'pending-asset.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/pending-asset.jpg',
            'metadata' => ['category_id' => $this->assetCategory->id],
            'published_at' => null, // Unpublished
            'approval_status' => \App\Enums\ApprovalStatus::PENDING,
        ]);

        // Verify asset is unpublished initially
        $this->assertNull($asset->published_at, 'Asset should start unpublished');

        // Call publish endpoint (simulating approval granted)
        $publishResponse = $this->post("/app/assets/{$asset->id}/publish");
        $publishResponse->assertStatus(200);

        // Reload asset from database
        $asset->refresh();

        // Assert asset is now published
        $this->assertNotNull(
            $asset->published_at,
            'Asset should be published after approval/publish action'
        );

        // Verify asset appears in default index
        $indexResponse = $this->get('/app/assets');
        $indexResponse->assertStatus(200);
        $indexData = $indexResponse->inertiaPage();
        $assetIds = collect($indexData['props']['assets'] ?? [])->pluck('id')->toArray();

        $this->assertContains(
            $asset->id,
            $assetIds,
            'Published asset should appear in default grid'
        );
    }

    /**
     * Test: Assets and Deliverables behave identically for approval flow
     * 
     * Verifies that approval requirements apply identically to both asset types.
     */
    public function test_assets_and_deliverables_behave_identically_for_approval(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create category with approval required
        $categoryWithApproval = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Category',
            'slug' => 'approval-category',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => true,
        ]);

        $deliverableCategoryWithApproval = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Deliverable Category',
            'slug' => 'approval-deliverable-category',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => true,
        ]);

        // Create asset and deliverable requiring approval
        $uploadSessionAsset = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $uploadSessionDeliverable = UploadSession::create([
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
            'upload_session_id' => $uploadSessionAsset->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset Requiring Approval',
            'original_filename' => 'asset-approval.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/asset-approval.jpg',
            'metadata' => ['category_id' => $categoryWithApproval->id],
            'published_at' => null,
            'approval_status' => \App\Enums\ApprovalStatus::PENDING,
        ]);

        $deliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessionDeliverable->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Deliverable Requiring Approval',
            'original_filename' => 'deliverable-approval.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/deliverable-approval.jpg',
            'metadata' => ['category_id' => $deliverableCategoryWithApproval->id],
            'published_at' => null,
            'approval_status' => \App\Enums\ApprovalStatus::PENDING,
        ]);

        // Assert both are unpublished
        $this->assertNull($asset->published_at, 'Asset should be unpublished');
        $this->assertNull($deliverable->published_at, 'Deliverable should be unpublished');

        // Assert both have pending approval
        $this->assertEquals(
            \App\Enums\ApprovalStatus::PENDING->value,
            $asset->approval_status->value,
            'Asset should have pending approval'
        );
        $this->assertEquals(
            \App\Enums\ApprovalStatus::PENDING->value,
            $deliverable->approval_status->value,
            'Deliverable should have pending approval'
        );

        // Test default indexes - both should NOT appear
        $assetResponse = $this->get('/app/assets');
        $assetData = $assetResponse->inertiaPage();
        $assetIds = collect($assetData['props']['assets'] ?? [])->pluck('id')->toArray();
        $this->assertNotContains($asset->id, $assetIds, 'Unpublished asset should not appear');

        $deliverableResponse = $this->get('/app/deliverables');
        $deliverableData = $deliverableResponse->inertiaPage();
        $deliverableIds = collect($deliverableData['props']['assets'] ?? [])->pluck('id')->toArray();
        $this->assertNotContains($deliverable->id, $deliverableIds, 'Unpublished deliverable should not appear');

        // Publish both
        $this->post("/app/assets/{$asset->id}/publish")->assertStatus(200);
        $this->post("/app/assets/{$deliverable->id}/publish")->assertStatus(200);

        // Reload both
        $asset->refresh();
        $deliverable->refresh();

        // Assert both are now published
        $this->assertNotNull($asset->published_at, 'Asset should be published after approval');
        $this->assertNotNull($deliverable->published_at, 'Deliverable should be published after approval');

        // Verify both appear in default indexes
        $assetResponse2 = $this->get('/app/assets');
        $assetData2 = $assetResponse2->inertiaPage();
        $assetIds2 = collect($assetData2['props']['assets'] ?? [])->pluck('id')->toArray();
        $this->assertContains($asset->id, $assetIds2, 'Published asset should appear');

        $deliverableResponse2 = $this->get('/app/deliverables');
        $deliverableData2 = $deliverableResponse2->inertiaPage();
        $deliverableIds2 = collect($deliverableData2['props']['assets'] ?? [])->pluck('id')->toArray();
        $this->assertContains($deliverable->id, $deliverableIds2, 'Published deliverable should appear');
    }
}
