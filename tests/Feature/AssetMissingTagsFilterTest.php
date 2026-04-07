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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssetMissingTagsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_tags_query_returns_only_assets_without_asset_tags(): void
    {
        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Tag Filter Tenant', 'slug' => 'tag-filter-tenant']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tag Filter Brand',
            'slug' => 'tag-filter-brand',
        ]);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Photos',
            'slug' => 'photos-missing-tags',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $user = User::create([
            'email' => 'tag-filter@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Tag',
            'last_name' => 'Filter',
        ]);
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id);
        $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.view');
        $user->setRoleForTenant($tenant, 'member');
        $user->assignRole($role);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'tag-filter-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $sessions = [];
        for ($i = 0; $i < 2; $i++) {
            $sessions[] = UploadSession::create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'storage_bucket_id' => $bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]);
        }

        $untagged = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $sessions[0]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'No tags',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/a.jpg',
            'metadata' => ['category_id' => $category->id],
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);

        $tagged = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $sessions[1]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Has tag',
            'original_filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/b.jpg',
            'metadata' => ['category_id' => $category->id],
            'published_at' => now(),
            'published_by_id' => $user->id,
        ]);

        DB::table('asset_tags')->insert([
            'asset_id' => $tagged->id,
            'tag' => 'hero',
            'source' => 'user',
            'confidence' => null,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get('/app/assets?category=photos-missing-tags&missing_tags=1');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertSame([$untagged->id], $ids);
    }
}
