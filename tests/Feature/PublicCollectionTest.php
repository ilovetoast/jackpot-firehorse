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

/**
 * Public collections (C8). No auth. Only is_public = true, resolved by slug.
 */
class PublicCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function createAsset(array $overrides = []): Asset
    {
        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        return Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'test/path.jpg',
            'size_bytes' => 1024,
        ], $overrides));
    }

    public function test_public_collection_loads_without_auth(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Press Kit',
            'slug' => 'press-kit',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $response = $this->get('/b/' . $this->brand->slug . '/collections/press-kit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Collection')
            ->has('collection')
            ->where('collection.name', 'Press Kit')
            ->where('collection.brand_name', 'B')
            ->has('assets')
        );
    }

    public function test_private_collection_returns_404(): void
    {
        Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Private',
            'slug' => 'private-collection',
            'visibility' => 'private',
            'is_public' => false,
        ]);

        $response = $this->get('/b/' . $this->brand->slug . '/collections/private-collection');

        $response->assertStatus(404);
    }

    public function test_assets_returned_are_brand_scoped_only(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Public',
            'slug' => 'public-collection',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $asset = $this->createAsset(['title' => 'Test Asset', 'published_at' => now()]);
        $collection->assets()->attach($asset->id);

        $response = $this->get('/b/' . $this->brand->slug . '/collections/public-collection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Collection')
            ->has('assets', 1)
            ->where('assets.0.id', $asset->id)
            ->where('assets.0.title', 'Test Asset')
        );
    }

    public function test_asset_from_another_brand_never_appears(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $otherBrand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'other']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Public',
            'slug' => 'public-collection',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $assetSameBrand = $this->createAsset(['title' => 'Same Brand', 'original_filename' => 'same.jpg', 'storage_root_path' => 'same/path.jpg', 'published_at' => now()]);
        $uploadOther = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $assetOtherBrand = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'user_id' => null,
            'upload_session_id' => $uploadOther->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Other Brand',
            'original_filename' => 'other.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'other/path.jpg',
            'size_bytes' => 1024,
            'published_at' => now(),
        ]);
        $collection->assets()->attach($assetSameBrand->id);
        // Maliciously attach other brand asset to pivot (simulating bad data)
        \DB::table('asset_collections')->insert([
            'collection_id' => $collection->id,
            'asset_id' => $assetOtherBrand->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/b/' . $this->brand->slug . '/collections/public-collection');

        $response->assertStatus(200);
        // Only same-brand asset must appear (queryPublic filters by collection brand)
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Collection')
            ->has('assets', 1)
            ->where('assets.0.id', $assetSameBrand->id)
        );
    }

    public function test_non_public_collection_cannot_be_accessed_even_if_user_logged_in(): void
    {
        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Restricted',
            'slug' => 'restricted-collection',
            'visibility' => 'restricted',
            'is_public' => false,
        ]);

        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->get('/b/' . $this->brand->slug . '/collections/restricted-collection');

        $response->assertStatus(404);
    }
}
