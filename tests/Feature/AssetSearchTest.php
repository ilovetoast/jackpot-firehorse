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
 * Asset scoped search: ?q= filters by filename, title, tags, collection.
 * Search must respect viewable categories and return only matching assets.
 */
class AssetSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected Asset $assetScreenshot;
    protected Asset $assetOther;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        $this->tenant = Tenant::create(['name' => 'Search Tenant', 'slug' => 'search-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Search Brand',
            'slug' => 'search-brand',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);
        $this->user = User::create([
            'email' => 'search@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Search',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'member', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.view');
        $this->user->setRoleForTenant($this->tenant, 'member');
        $this->user->assignRole($role);

        $bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'search-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $session1 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $session2 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->assetScreenshot = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session1->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Screenshot 2026 02 09 145118',
            'original_filename' => 'Screenshot 2026 02 09 145118.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/screen1.png',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        $this->assetOther = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session2->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Augusta Bourbon',
            'original_filename' => 'Screenshot 2026 02 09 114750.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/bourbon.jpg',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
    }

    public function test_search_q_screen_matches_filename_and_title(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?q=screen');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $this->assertGreaterThanOrEqual(1, count($assets), 'Search q=screen should return at least one asset (filename/title contains "screen")');
        $hasMatch = collect($assets)->contains(fn ($a) => stripos($a['title'] ?? '', 'screen') !== false || stripos($a['original_filename'] ?? '', 'screen') !== false);
        $this->assertTrue($hasMatch, 'At least one returned asset should have "screen" in title or original_filename');
    }

    public function test_search_q_bourbon_matches_one_asset(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?q=bourbon');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $bourbonAssets = collect($assets)->filter(fn ($a) => stripos($a['title'] ?? '', 'bourbon') !== false || stripos($a['original_filename'] ?? '', 'bourbon') !== false);
        $this->assertGreaterThanOrEqual(1, $bourbonAssets->count(), 'Search q=bourbon should return the Augusta Bourbon asset');
        $this->assertLessThanOrEqual(1, count($assets), 'Search q=bourbon should not return the Screenshot-only asset');
    }

    public function test_search_empty_q_returns_all_viewable_assets(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $this->assertCount(2, $assets);
    }
}
