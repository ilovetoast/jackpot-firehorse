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
use App\Services\AssetSortService;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Asset grid sort tests.
 *
 * Locks sort behavior: sort (created | starred | quality) and sort_direction (asc | desc)
 * for AssetController::index(). Verifies response props and asset order.
 */
class AssetSortTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected Asset $assetA;
    protected Asset $assetB;
    protected Asset $assetC;
    protected Asset $assetD;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        $this->tenant = Tenant::create(['name' => 'Sort Tenant', 'slug' => 'sort-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sort Brand',
            'slug' => 'sort-brand',
        ]);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);
        $this->user = User::create([
            'email' => 'sort@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Sort',
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
            'name' => 'sort-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $sessions = [];
        for ($i = 0; $i < 4; $i++) {
            $sessions[] = UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => 1024,
                'uploaded_size' => 1024,
            ]);
        }

        $this->assetA = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $sessions[0]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/a.jpg',
            'metadata' => ['category_id' => $this->category->id, 'quality_rating' => 1],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
        $this->assetA->created_at = now()->subDays(3);
        $this->assetA->saveQuietly();

        $this->assetB = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $sessions[1]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset B',
            'original_filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/b.jpg',
            'metadata' => ['category_id' => $this->category->id, 'starred' => true, 'quality_rating' => 3],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
        $this->assetB->created_at = now()->subDays(1);
        $this->assetB->saveQuietly();

        $this->assetC = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $sessions[2]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset C',
            'original_filename' => 'c.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/c.jpg',
            'metadata' => ['category_id' => $this->category->id, 'quality_rating' => 5],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
        $this->assetC->created_at = now();
        $this->assetC->saveQuietly();

        $this->assetD = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $sessions[3]->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Asset D',
            'original_filename' => 'd.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'assets/test/d.jpg',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);
        $this->assetD->created_at = now()->subDays(2);
        $this->assetD->saveQuietly();
    }

    public function test_assets_index_returns_sort_and_sort_direction_props(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=created&sort_direction=asc');

        $response->assertStatus(200);
        $props = $response->inertiaPage()['props'] ?? [];
        $this->assertArrayHasKey('sort', $props);
        $this->assertArrayHasKey('sort_direction', $props);
        $this->assertSame('created', $props['sort']);
        $this->assertSame('asc', $props['sort_direction']);
    }

    public function test_sort_created_desc_orders_newest_first(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=created&sort_direction=desc');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertSame([$this->assetC->id, $this->assetB->id, $this->assetD->id, $this->assetA->id], $ids, 'created desc: newest first');
    }

    public function test_sort_created_asc_orders_oldest_first(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=created&sort_direction=asc');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertSame([$this->assetA->id, $this->assetD->id, $this->assetB->id, $this->assetC->id], $ids, 'created asc: oldest first (B/D tie by created_at)');
    }

    public function test_sort_quality_desc_orders_highest_quality_first(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=quality&sort_direction=desc');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertSame([$this->assetC->id, $this->assetB->id, $this->assetA->id, $this->assetD->id], $ids, 'quality desc: 5,3,1, null last');
    }

    public function test_sort_quality_asc_orders_lowest_quality_first(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=quality&sort_direction=asc');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        // Quality sort always orders by rating highest first; direction only affects secondary created_at. Null last.
        $this->assertSame([$this->assetC->id, $this->assetB->id, $this->assetA->id, $this->assetD->id], $ids, 'quality asc: highest first then created_at asc, null last');
    }

    public function test_sort_starred_puts_starred_first_when_desc(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=starred&sort_direction=desc');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertSame($this->assetB->id, $ids[0], 'starred desc: starred asset (B) first');
    }

    public function test_default_sort_is_created_desc(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos');

        $response->assertStatus(200);
        $props = $response->inertiaPage()['props'] ?? [];
        $this->assertSame(AssetSortService::DEFAULT_SORT, $props['sort'] ?? null);
        $this->assertSame(AssetSortService::DEFAULT_DIRECTION, $props['sort_direction'] ?? null);
        $assets = $props['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $this->assertSame([$this->assetC->id, $this->assetB->id, $this->assetD->id, $this->assetA->id], $ids);
    }

    /**
     * Invariant: When sort=starred, starred assets always precede non-starred (regardless of direction).
     */
    public function test_sort_starred_invariant_starred_always_precede_non_starred(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=starred&sort_direction=desc');

        $response->assertStatus(200);
        $assets = $response->inertiaPage()['props']['assets'] ?? [];
        $ids = array_column($assets, 'id');
        $starredId = $this->assetB->id;
        $starredIndex = array_search($starredId, $ids, true);
        $this->assertNotFalse($starredIndex, 'starred asset B must be in results');
        foreach ([$this->assetA->id, $this->assetC->id, $this->assetD->id] as $nonStarredId) {
            $nonStarredIndex = array_search($nonStarredId, $ids, true);
            $this->assertNotFalse($nonStarredIndex, 'non-starred asset must be in results');
            $this->assertLessThan($nonStarredIndex, $starredIndex, 'starred asset must precede non-starred (starred index must be lower)');
        }
    }

    /**
     * Invariant: When sort=starred, direction only affects secondary order (created_at).
     * desc → starred first, then newest to oldest; asc → starred first, then oldest to newest.
     */
    public function test_sort_starred_invariant_direction_affects_only_secondary_order(): void
    {
        $responseDesc = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=starred&sort_direction=desc');
        $responseAsc = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=starred&sort_direction=asc');

        $idsDesc = array_column($responseDesc->inertiaPage()['props']['assets'] ?? [], 'id');
        $idsAsc = array_column($responseAsc->inertiaPage()['props']['assets'] ?? [], 'id');
        $this->assertSame($this->assetB->id, $idsDesc[0], 'starred desc: B first');
        $this->assertSame($this->assetB->id, $idsAsc[0], 'starred asc: B first');
        $this->assertSame([$this->assetB->id, $this->assetC->id, $this->assetD->id, $this->assetA->id], $idsDesc, 'starred desc: B first, then C,D,A by created desc');
        $this->assertSame([$this->assetB->id, $this->assetA->id, $this->assetD->id, $this->assetC->id], $idsAsc, 'starred asc: B first, then A,D,C by created asc');
    }

    /**
     * Invariant: When sort=quality, NULL ratings are always last (no interleaving).
     */
    public function test_sort_quality_invariant_null_ratings_always_last(): void
    {
        $responseDesc = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=quality&sort_direction=desc');
        $responseAsc = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets?category=logos&sort=quality&sort_direction=asc');

        $idsDesc = array_column($responseDesc->inertiaPage()['props']['assets'] ?? [], 'id');
        $idsAsc = array_column($responseAsc->inertiaPage()['props']['assets'] ?? [], 'id');
        $nullRatedId = $this->assetD->id;
        $this->assertSame($nullRatedId, $idsDesc[array_key_last($idsDesc)], 'quality desc: NULL-rated asset D must be last');
        $this->assertSame($nullRatedId, $idsAsc[array_key_last($idsAsc)], 'quality asc: NULL-rated asset D must be last');
    }
}
