<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase B2: Tenant Trash System
 *
 * - Tenant Admin + Brand Manager can view trash (lifecycle=deleted)
 * - Contributor and Viewer cannot view trash (403)
 * - Restore from trash (POST restore-from-trash)
 * - Force delete requires tenant admin permission
 */
class TenantTrashTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $admin;
    protected User $brandManager;
    protected User $contributor;
    protected Asset $deletedAsset;
    protected Asset $liveAsset;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['asset.publish', 'asset.view', 'metadata.bypass_approval', 'asset.archive', 'assets.delete'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Cat',
            'slug' => 'cat',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'buck',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->admin->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::whereIn('name', ['asset.publish', 'asset.view', 'metadata.bypass_approval', 'asset.archive', 'assets.delete'])->get());
        $this->admin->assignRole($adminRole);

        $this->brandManager = User::factory()->create();
        $this->brandManager->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->brandManager->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
        $bmRole = Role::firstOrCreate(['name' => 'brand_manager', 'guard_name' => 'web']);
        $bmRole->syncPermissions(Permission::whereIn('name', ['asset.publish', 'asset.view', 'metadata.bypass_approval', 'asset.archive', 'assets.delete'])->get());
        $this->brandManager->assignRole($bmRole);

        $this->contributor = User::factory()->create();
        $this->contributor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributor->brands()->attach($this->brand->id, ['role' => 'contributor']);
        $contribRole = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $contribRole->syncPermissions(Permission::whereIn('name', ['asset.view'])->get());
        $this->contributor->assignRole($contribRole);

        $metadata = ['category_id' => $this->category->id];
        $this->liveAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Live',
            'original_filename' => 'live.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/' . $this->tenant->id . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/live.jpg',
            'size_bytes' => 1024,
            'metadata' => $metadata,
            'published_at' => now(),
            'archived_at' => null,
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
        ]);
        $this->deletedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Deleted',
            'original_filename' => 'del.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/' . $this->tenant->id . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/del.jpg',
            'size_bytes' => 1024,
            'metadata' => $metadata,
            'published_at' => null,
            'archived_at' => null,
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
        ]);
        $this->deletedAsset->delete();
    }

    public function test_tenant_admin_can_view_trash(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->admin)
            ->get('/app/assets?lifecycle=deleted');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Assets/Index')
            ->where('lifecycle', 'deleted')
            ->has('assets')
            ->where('assets.0.id', $this->deletedAsset->id)
        );
    }

    public function test_brand_manager_can_view_brand_trash(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->brandManager)
            ->get('/app/assets?lifecycle=deleted');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Assets/Index')
            ->where('lifecycle', 'deleted')
            ->has('assets')
        );
    }

    public function test_contributor_cannot_view_trash(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->contributor)
            ->get('/app/assets?lifecycle=deleted');

        $response->assertForbidden();
    }

    public function test_restore_from_trash(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $response = $this->actingAs($this->admin)
            ->postJson(route('assets.restore-from-trash', ['asset' => $this->deletedAsset->id]));

        $response->assertOk();
        $response->assertJson(['message' => 'Asset restored successfully']);
        $this->deletedAsset->refresh();
        $this->assertNull($this->deletedAsset->deleted_at);
    }

    public function test_force_delete_requires_permission(): void
    {
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);

        $responseContributor = $this->actingAs($this->contributor)
            ->deleteJson(route('assets.force-delete', ['asset' => $this->deletedAsset->id]));
        $responseContributor->assertForbidden();

        $assetId = $this->deletedAsset->id;
        $responseAdmin = $this->actingAs($this->admin)
            ->deleteJson(route('assets.force-delete', ['asset' => $assetId]));
        $responseAdmin->assertOk();
        $responseAdmin->assertJson(['message' => 'Asset permanently deleted']);
        $this->assertNull(Asset::withTrashed()->find($assetId));
    }
}
