<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\AssetMetadataIndexEntry;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AssetSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmbeddedMetadataSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_search_matches_embedded_metadata_index(): void
    {
        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-em']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-em']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Cat',
            'slug' => 'cat-em',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);
        $user = User::create([
            'email' => 'em@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'E',
            'last_name' => 'M',
        ]);
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id);
        $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.view');
        $user->setRoleForTenant($tenant, 'member');
        $user->assignRole($role);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'em-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Plain',
            'original_filename' => 'plain.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'x/y.jpg',
            'metadata' => ['category_id' => $category->id],
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);

        AssetMetadataIndexEntry::create([
            'asset_id' => $asset->id,
            'namespace' => 'exif',
            'key' => 'Make',
            'normalized_key' => 'camera_make',
            'value_type' => 'string',
            'value_string' => 'Zebracam',
            'search_text' => 'zebracam',
            'is_filterable' => false,
            'is_visible' => true,
            'source_priority' => 100,
        ]);

        $query = Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id);

        app(AssetSearchService::class)->applyScopedSearch($query, 'zebracam');

        $this->assertSame(1, $query->count());
    }

    public function test_tenant_isolation_on_metadata_index(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid()]);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid()]);
        $brandA = Brand::create(['tenant_id' => $tenantA->id, 'name' => 'BA', 'slug' => 'ba-'.uniqid()]);
        $brandB = Brand::create(['tenant_id' => $tenantB->id, 'name' => 'BB', 'slug' => 'bb-'.uniqid()]);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenantA->id,
            'name' => 'b1',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        StorageBucket::create([
            'tenant_id' => $tenantB->id,
            'name' => 'b2',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $assetA = $this->createMinimalAsset($tenantA, $brandA, $bucket);
        $assetB = $this->createMinimalAsset($tenantB, $brandB, StorageBucket::where('tenant_id', $tenantB->id)->first());

        AssetMetadataIndexEntry::create([
            'asset_id' => $assetB->id,
            'namespace' => 'exif',
            'key' => 'Make',
            'normalized_key' => 'camera_make',
            'value_type' => 'string',
            'value_string' => 'SecretBrand',
            'search_text' => 'secretbrand',
            'is_filterable' => false,
            'is_visible' => true,
            'source_priority' => 100,
        ]);

        $query = Asset::query()
            ->where('tenant_id', $tenantA->id)
            ->where('brand_id', $brandA->id);

        app(AssetSearchService::class)->applyScopedSearch($query, 'secretbrand');

        $this->assertSame(0, $query->count());
    }

    protected function createMinimalAsset(Tenant $tenant, Brand $brand, StorageBucket $bucket): Asset
    {
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);
        $user = User::create([
            'email' => uniqid().'@x.com',
            'password' => bcrypt('p'),
            'first_name' => 'U',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenant->id);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 't',
            'original_filename' => 'f.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'p/f.jpg',
            'metadata' => [],
        ]);
    }
}
