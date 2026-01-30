<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C9.1: Collection persistence tests.
 * 
 * Ensures asset → collection pivot persistence works correctly:
 * - Assets are correctly added to collections
 * - Asset removal from collections persists
 * - Unauthorized collection assignment is rejected
 * - State persists after refresh
 */
class CollectionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected StorageBucket $bucket;
    protected User $adminUser;
    protected User $contributorUser;
    protected User $viewerUser;
    protected Collection $collection1;
    protected Collection $collection2;
    protected Asset $asset;

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

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

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

        $this->collection1 = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Collection 1',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $this->collection2 = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Collection 2',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'test/path.jpg',
            'size_bytes' => 1024,
        ]);
    }

    public function test_asset_can_be_added_to_collection(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [$this->collection1->id],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($this->asset->collections()->where('collections.id', $this->collection1->id)->exists());
    }

    public function test_asset_can_be_removed_from_collection(): void
    {
        // Add asset to collection first
        $this->asset->collections()->attach($this->collection1->id);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [],
            ]);

        $response->assertStatus(200);
        $this->assertFalse($this->asset->collections()->where('collections.id', $this->collection1->id)->exists());
    }

    public function test_asset_collections_sync_adds_and_removes_correctly(): void
    {
        // Start with asset in collection1
        $this->asset->collections()->attach($this->collection1->id);

        // Sync to collection2 only (should remove from collection1, add to collection2)
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [$this->collection2->id],
            ]);

        $response->assertStatus(200);
        $this->assertFalse($this->asset->collections()->where('collections.id', $this->collection1->id)->exists());
        $this->assertTrue($this->asset->collections()->where('collections.id', $this->collection2->id)->exists());
    }

    public function test_asset_can_be_in_multiple_collections(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [$this->collection1->id, $this->collection2->id],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($this->asset->collections()->where('collections.id', $this->collection1->id)->exists());
        $this->assertTrue($this->asset->collections()->where('collections.id', $this->collection2->id)->exists());
    }

    public function test_unauthorized_collection_assignment_is_rejected(): void
    {
        // Viewer cannot add assets to collections
        $response = $this->actingAs($this->viewerUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [$this->collection1->id],
            ]);

        // Middleware may redirect (302) or policy may return 422/403
        $this->assertTrue(in_array($response->status(), [302, 403, 422], true));
        $this->assertFalse($this->asset->collections()->where('collections.id', $this->collection1->id)->exists());
    }

    public function test_collection_state_persists_after_refresh(): void
    {
        // Add asset to collection
        $this->asset->collections()->attach($this->collection1->id);

        // Fetch collections for asset (simulating refresh)
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/assets/{$this->asset->id}/collections");

        $response->assertStatus(200);
        $collections = $response->json('collections');
        $this->assertCount(1, $collections);
        $this->assertEquals($this->collection1->id, $collections[0]['id']);
    }

    public function test_empty_array_removes_all_collections(): void
    {
        // Add asset to both collections
        $this->asset->collections()->attach([$this->collection1->id, $this->collection2->id]);

        // Sync to empty array
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [],
            ]);

        $response->assertStatus(200);
        $this->assertCount(0, $this->asset->collections);
    }

    public function test_partial_update_only_changes_specified_collections(): void
    {
        // Start with asset in collection1
        $this->asset->collections()->attach($this->collection1->id);

        // Add collection2 (should keep collection1)
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->putJson("/app/assets/{$this->asset->id}/collections", [
                'collection_ids' => [$this->collection1->id, $this->collection2->id],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($this->asset->collections()->where('collections.id', $this->collection1->id)->exists());
        $this->assertTrue($this->asset->collections()->where('collections.id', $this->collection2->id)->exists());
    }
}
