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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lifecycle filter tests for asset grid.
 *
 * Verifies unpublished, archived, and pending_approval filters work correctly.
 * CRITICAL: Unpublishing sets status=HIDDEN, so the filter must include HIDDEN assets.
 */
class LifecycleFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $logosCategory;
    protected User $user;
    protected Asset $publishedAsset;
    protected Asset $unpublishedAsset;
    protected Asset $archivedAsset;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.publish', 'guard_name' => 'web']);
        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'metadata.bypass_approval', 'guard_name' => 'web']);
        Permission::create(['name' => 'asset.archive', 'guard_name' => 'web']);

        $this->tenant = Tenant::create(['name' => 'Company 1', 'slug' => 'company-1']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand 1',
            'slug' => 'brand-1',
        ]);

        $this->logosCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'asset.publish', 'metadata.bypass_approval', 'asset.archive']);
        $this->user->setRoleForTenant($this->tenant, 'admin');
        $this->user->assignRole($role);

        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSessions = [
            UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
            UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
            UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]),
        ];

        // Published asset in Logos
        $this->publishedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions[0]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Published Logo',
            'original_filename' => 'published-logo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/published-logo.jpg',
            'metadata' => ['category_id' => $this->logosCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        // Unpublished asset in Logos - CRITICAL: status=HIDDEN (matches AssetPublicationService::unpublish)
        $this->unpublishedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions[1]->id,
            'status' => AssetStatus::HIDDEN,
            'type' => AssetType::ASSET,
            'title' => 'Unpublished Logo',
            'original_filename' => 'unpublished-logo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/unpublished-logo.jpg',
            'metadata' => ['category_id' => $this->logosCategory->id],
            'published_at' => null,
            'published_by_id' => null,
        ]);

        // Archived asset
        $this->archivedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSessions[2]->id,
            'status' => AssetStatus::HIDDEN,
            'type' => AssetType::ASSET,
            'title' => 'Archived Logo',
            'original_filename' => 'archived-logo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/archived-logo.jpg',
            'metadata' => ['category_id' => $this->logosCategory->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
            'archived_at' => now(),
        ]);
    }

    public function test_unpublished_filter_shows_hidden_unpublished_asset_in_logos(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&lifecycle=unpublished');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $assetIds = collect($assets)->pluck('id')->toArray();

        $this->assertContains(
            $this->unpublishedAsset->id,
            $assetIds,
            'Unpublished filter with category=logos must include unpublished asset (status=HIDDEN)'
        );
        $this->assertNotContains(
            $this->publishedAsset->id,
            $assetIds,
            'Unpublished filter must exclude published assets'
        );
    }

    public function test_default_view_excludes_unpublished_asset(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $assetIds = collect($assets)->pluck('id')->toArray();

        $this->assertNotContains(
            $this->unpublishedAsset->id,
            $assetIds,
            'Default view must exclude unpublished assets'
        );
        $this->assertContains(
            $this->publishedAsset->id,
            $assetIds,
            'Default view must include published assets'
        );
    }

    public function test_archived_filter_shows_archived_asset(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&lifecycle=archived');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $assetIds = collect($assets)->pluck('id')->toArray();

        $this->assertContains(
            $this->archivedAsset->id,
            $assetIds,
            'Archived filter must include archived assets (status can be HIDDEN)'
        );
    }
}
