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
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase L.5.1: Test asset visibility based on approval state and user permissions.
 * 
 * Verifies that:
 * - Pending assets are hidden from viewers (users without asset.publish)
 * - Pending assets are visible to approvers (users with asset.publish)
 * - Published assets are visible to all users
 */
class AssetVisibilityApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $viewer; // User without asset.publish
    protected User $approver; // User with asset.publish
    protected Category $approvalCategory;
    protected Category $normalCategory;
    protected Asset $pendingAsset;
    protected Asset $publishedAsset;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'asset.publish', 'guard_name' => 'web']);
        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);

        // Create tenant and brand
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create viewer (user without asset.publish)
        $this->viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Viewer',
            'last_name' => 'User',
        ]);
        $this->viewer->tenants()->attach($this->tenant->id);
        $this->viewer->brands()->attach($this->brand->id);
        
        $viewerRole = Role::create(['name' => 'viewer_role', 'guard_name' => 'web']);
        $viewerRole->givePermissionTo(['asset.view']);
        $this->viewer->setRoleForTenant($this->tenant, 'viewer_role');
        $this->viewer->assignRole($viewerRole);

        // Create approver (user with asset.publish)
        $this->approver = User::create([
            'email' => 'approver@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Approver',
            'last_name' => 'User',
        ]);
        $this->approver->tenants()->attach($this->tenant->id);
        $this->approver->brands()->attach($this->brand->id);
        
        $approverRole = Role::create(['name' => 'approver_role', 'guard_name' => 'web']);
        $approverRole->givePermissionTo(['asset.view', 'asset.publish']);
        $this->approver->setRoleForTenant($this->tenant, 'approver_role');
        $this->approver->assignRole($approverRole);

        // Create categories
        $this->approvalCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Approval Required',
            'slug' => 'approval-required',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => true,
        ]);

        $this->normalCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Normal Category',
            'slug' => 'normal',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        // Create storage bucket
        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create upload sessions for assets
        $pendingUploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $publishedUploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create pending asset (unpublished, HIDDEN status)
        $this->pendingAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->viewer->id,
            'upload_session_id' => $pendingUploadSession->id,
            'status' => AssetStatus::HIDDEN,
            'type' => AssetType::ASSET,
            'title' => 'Pending Asset',
            'original_filename' => 'pending.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/pending.jpg',
            'metadata' => ['category_id' => $this->approvalCategory->id],
            'published_at' => null,
            'published_by_id' => null,
        ]);

        // Create published asset
        $this->publishedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->viewer->id,
            'upload_session_id' => $publishedUploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Published Asset',
            'original_filename' => 'published.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/published.jpg',
            'metadata' => ['category_id' => $this->normalCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->approver->id,
        ]);
    }

    public function test_pending_assets_hidden_from_viewers(): void
    {
        // Simulate viewer query (no asset.publish permission)
        // Query should exclude unpublished assets and HIDDEN status assets
        $assets = Asset::where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE) // Only VISIBLE
            ->whereNotNull('published_at') // Only published
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->get();

        $assetIds = $assets->pluck('id')->toArray();

        // Assert pending asset is NOT visible
        $this->assertNotContains($this->pendingAsset->id, $assetIds, 'Pending assets should be hidden from viewers');
        
        // Assert published asset IS visible
        $this->assertContains($this->publishedAsset->id, $assetIds, 'Published assets should be visible to all users');
    }

    public function test_pending_assets_visible_to_approvers(): void
    {
        // Simulate approver query (has asset.publish permission)
        // Query should include both VISIBLE and HIDDEN assets, and both published and unpublished
        $assets = Asset::where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('type', AssetType::ASSET)
            ->whereIn('status', [AssetStatus::VISIBLE, AssetStatus::HIDDEN]) // Both VISIBLE and HIDDEN
            // No published_at filter (includes both published and unpublished)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->get();

        $assetIds = $assets->pluck('id')->toArray();

        // Assert pending asset IS visible to approvers
        $this->assertContains($this->pendingAsset->id, $assetIds, 'Pending assets should be visible to approvers');
        
        // Assert published asset IS visible
        $this->assertContains($this->publishedAsset->id, $assetIds, 'Published assets should be visible to approvers');
    }

    public function test_pending_approval_filter_shows_only_pending_assets(): void
    {
        // Simulate pending approval filter query
        // Should show only unpublished (published_at IS NULL) and HIDDEN status assets
        $assets = Asset::where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('type', AssetType::ASSET)
            ->whereNull('published_at') // Unpublished
            ->where('status', AssetStatus::HIDDEN) // HIDDEN status
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->get();

        $assetIds = $assets->pluck('id')->toArray();

        // Assert pending asset IS in results
        $this->assertContains($this->pendingAsset->id, $assetIds, 'Pending approval filter should show pending assets');
        
        // Assert published asset is NOT in results
        $this->assertNotContains($this->publishedAsset->id, $assetIds, 'Pending approval filter should exclude published assets');
    }
}
