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
use App\Services\BrandLibraryCategoryCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression: grouped counts used Asset models with SQL alias `category_id`, which is overridden
 * by the Asset categoryId accessor (metadata-only), so per-category sidebar counts were always empty.
 */
class BrandLibraryCategoryCountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_counts_returns_non_zero_by_category_when_assets_exist(): void
    {
        foreach (['asset.publish', 'asset.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $tenant = Tenant::create(['name' => 'Count Tenant', 'slug' => 'count-tenant']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Count Brand', 'slug' => 'count-brand']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Graphics',
            'slug' => 'graphics',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'count-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'brand_manager']);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::whereIn('name', ['asset.publish', 'asset.view'])->get());
        $user->assignRole($role);

        Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Sidebar count asset',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/'.$tenant->id.'/assets/'.\Illuminate\Support\Str::uuid().'/v1/a.jpg',
            'size_bytes' => 1024,
            'metadata' => ['category_id' => $category->id],
            'published_at' => now(),
            'archived_at' => null,
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'intake_state' => 'normal',
        ]);

        $service = app(BrandLibraryCategoryCountService::class);
        $result = $service->getCounts(
            $tenant,
            $brand,
            $user,
            [$category->id],
            [$category->id],
            null,
            false,
            AssetType::ASSET,
            true,
            false
        );

        $this->assertSame(1, $result['total']);
        $this->assertArrayHasKey($category->id, $result['by_category']);
        $this->assertSame(1, $result['by_category'][$category->id]);
    }
}
