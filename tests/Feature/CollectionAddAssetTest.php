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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionAddAssetTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Brand $otherBrand;
    protected Collection $collection;
    protected StorageBucket $bucket;
    protected Asset $assetSameBrand;
    protected Asset $assetOtherBrand;
    protected User $adminUser;
    protected User $contributorUser;
    protected User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->otherBrand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'other']);

        $this->collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'C1',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSame = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $uploadOther = UploadSession::create([
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
            'first_name' => 'A',
            'last_name' => 'A',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->assetSameBrand = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $uploadSame->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Same',
            'original_filename' => 'same.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/same.jpg',
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);

        $this->assetOtherBrand = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->otherBrand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $uploadOther->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Other',
            'original_filename' => 'other.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/other.jpg',
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);

        $this->contributorUser = User::create([
            'email' => 'contributor@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'C',
        ]);
        $this->contributorUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributorUser->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->viewerUser = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'V',
        ]);
        $this->viewerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->viewerUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);
    }

    public function test_contributor_can_add_asset(): void
    {
        $response = $this->actingAs($this->contributorUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/collections/{$this->collection->id}/assets", [
                'asset_id' => $this->assetSameBrand->id,
            ]);

        $response->assertStatus(201);
        $response->assertJson(['attached' => true]);
        $this->assertTrue($this->collection->assets()->where('assets.id', $this->assetSameBrand->id)->exists());
    }

    public function test_viewer_cannot_add_asset(): void
    {
        $response = $this->actingAs($this->viewerUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/collections/{$this->collection->id}/assets", [
                'asset_id' => $this->assetSameBrand->id,
            ]);

        $response->assertStatus(403);
        $this->assertFalse($this->collection->assets()->where('assets.id', $this->assetSameBrand->id)->exists());
    }

    public function test_asset_from_another_brand_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/collections/{$this->collection->id}/assets", [
                'asset_id' => $this->assetOtherBrand->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('asset_id');
        $this->assertFalse($this->collection->assets()->where('assets.id', $this->assetOtherBrand->id)->exists());
    }

    public function test_duplicate_attach_is_safe_idempotent(): void
    {
        $this->collection->assets()->attach($this->assetSameBrand->id);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/collections/{$this->collection->id}/assets", [
                'asset_id' => $this->assetSameBrand->id,
            ]);

        $response->assertStatus(201);
        $response->assertJson(['attached' => true]);
        $count = $this->collection->assets()->where('assets.id', $this->assetSameBrand->id)->count();
        $this->assertSame(1, $count);
    }
}
