<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\CollectionAssetQueryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collection Asset Query tests (Collections C3).
 *
 * Verifies that querying assets by collection acts purely as a filtering layer
 * and never grants asset access. Collections must not grant asset access on their own.
 */
class CollectionAssetQueryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Brand $otherBrand;
    protected Collection $collection;
    protected StorageBucket $bucket;
    protected User $adminUser;
    protected User $managerUser;
    protected User $contributorUser;
    protected User $viewerUser;
    protected User $notInBrandUser;
    protected Asset $assetInCollection;
    protected Asset $assetSameBrandNotInCollection;
    protected Asset $assetOtherBrand;
    protected CollectionAssetQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        $this->collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Collection',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadInCollection = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $uploadSameBrand = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $uploadOtherBrand = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->otherBrand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->assetInCollection = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $uploadInCollection->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'In Collection',
            'original_filename' => 'in-collection.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/in-collection.jpg',
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);
        $this->collection->assets()->attach($this->assetInCollection->id);

        $this->assetSameBrandNotInCollection = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $uploadSameBrand->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Same Brand Not In Collection',
            'original_filename' => 'same-brand.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/same-brand.jpg',
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);

        $this->assetOtherBrand = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->otherBrand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $uploadOtherBrand->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Other Brand',
            'original_filename' => 'other-brand.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/other-brand.jpg',
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);
        // Do NOT add to collection - but even if linked elsewhere, must never be returned for this collection

        $this->managerUser = User::create([
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Manager',
            'last_name' => 'User',
        ]);
        $this->managerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->managerUser->brands()->attach($this->brand->id, ['role' => 'brand_manager', 'removed_at' => null]);

        $this->contributorUser = User::create([
            'email' => 'contributor@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Contributor',
            'last_name' => 'User',
        ]);
        $this->contributorUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributorUser->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->viewerUser = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Viewer',
            'last_name' => 'User',
        ]);
        $this->viewerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->viewerUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->notInBrandUser = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $this->notInBrandUser->tenants()->attach($this->tenant->id, ['role' => 'member']);

        $this->service = app(CollectionAssetQueryService::class);
    }

    public function test_brand_admin_can_query_assets_via_collection_only_from_that_brand(): void
    {
        $query = $this->service->query($this->adminUser, $this->collection);
        $assets = $query->get();

        $this->assertCount(1, $assets);
        $this->assertTrue($assets->contains($this->assetInCollection));
        $this->assertFalse($assets->contains($this->assetSameBrandNotInCollection));
        $this->assertFalse($assets->contains($this->assetOtherBrand));
        $this->assertSame($this->brand->id, $assets->first()->brand_id);
    }

    public function test_brand_manager_can_query_assets_via_collection_only_from_that_brand(): void
    {
        $query = $this->service->query($this->managerUser, $this->collection);
        $assets = $query->get();

        $this->assertCount(1, $assets);
        $this->assertTrue($assets->contains($this->assetInCollection));
        $this->assertFalse($assets->contains($this->assetSameBrandNotInCollection));
        $this->assertFalse($assets->contains($this->assetOtherBrand));
    }

    public function test_contributor_same_visibility_as_normal_asset_queries_no_elevation(): void
    {
        $query = $this->service->query($this->contributorUser, $this->collection);
        $assets = $query->get();

        $this->assertCount(1, $assets);
        $this->assertTrue($assets->contains($this->assetInCollection));
        $this->assertFalse($assets->contains($this->assetOtherBrand));
    }

    public function test_viewer_same_visibility_as_normal_asset_queries_no_elevation(): void
    {
        $query = $this->service->query($this->viewerUser, $this->collection);
        $assets = $query->get();

        $this->assertCount(1, $assets);
        $this->assertTrue($assets->contains($this->assetInCollection));
        $this->assertFalse($assets->contains($this->assetOtherBrand));
    }

    public function test_user_not_in_brand_cannot_retrieve_assets_via_collection(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->query($this->notInBrandUser, $this->collection)->get();
    }

    public function test_assets_from_other_brands_are_never_returned_even_if_linked(): void
    {
        // Artificially link other-brand asset to this collection's pivot (simulating bad data or attack)
        \DB::table('asset_collections')->insert([
            'collection_id' => $this->collection->id,
            'asset_id' => $this->assetOtherBrand->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = $this->service->query($this->adminUser, $this->collection);
        $assets = $query->get();

        // Query enforces brand_id = collection.brand_id, so other brand's asset must not appear
        $this->assertCount(1, $assets);
        $this->assertTrue($assets->contains($this->assetInCollection));
        $this->assertFalse($assets->contains($this->assetOtherBrand));
        foreach ($assets as $asset) {
            $this->assertSame($this->collection->brand_id, $asset->brand_id);
        }
    }

    public function test_query_accepts_collection_id(): void
    {
        $query = $this->service->query($this->adminUser, $this->collection->id);
        $assets = $query->get();

        $this->assertCount(1, $assets);
        $this->assertTrue($assets->contains($this->assetInCollection));
    }
}
