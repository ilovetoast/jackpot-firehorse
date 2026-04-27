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
 * Slug is unique per (tenant, brand, asset_type). The assets index must resolve
 * ?category=slug to the library (ASSET) row, not an execution folder (DELIVERABLE) with the same slug.
 */
class AssetIndexCategorySlugResolvesAssetTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_index_filters_by_asset_category_when_slug_collides_with_deliverable_folder(): void
    {
        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'metadata.bypass_approval', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b',
        ]);

        $user = User::create([
            'email' => 'u@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'U',
            'last_name' => 'ser',
        ]);
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'metadata.bypass_approval']);
        $user->setRoleForTenant($tenant, 'admin');
        $user->assignRole($role);

        $slug = 'product-renders';

        $deliverableFolder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Product Renders (Exec)',
            'slug' => $slug,
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $assetFolder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Product Renders',
            'slug' => $slug,
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $libraryAsset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Render A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/a.jpg',
            'metadata' => ['category_id' => $assetFolder->id],
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);

        $this->assertNotSame($deliverableFolder->id, $assetFolder->id);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/assets?category='.urlencode($slug).'&format=json');

        $response->assertStatus(200);
        $json = $response->json();
        $ids = collect($json['assets'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($libraryAsset->id, $ids, 'Grid must use ASSET-type category id for this slug, not DELIVERABLE.');
    }
}
