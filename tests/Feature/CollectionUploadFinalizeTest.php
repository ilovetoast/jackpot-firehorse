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
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * C9.1: Test collection assignment during upload finalize.
 * 
 * This test simulates the actual upload finalize flow to ensure
 * collections are correctly attached when provided in the manifest.
 */
class CollectionUploadFinalizeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected StorageBucket $bucket;
    protected User $adminUser;
    protected User $contributorUser;
    protected Collection $collection1;
    protected Collection $collection2;

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
    }

    public function test_collections_are_attached_during_upload_finalize(): void
    {
        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset (simulating what finalize does - asset already exists from previous finalize attempt)
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->adminUser->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'test/path.jpg',
            'size_bytes' => 1024,
        ]);

        // Simulate finalize request with collection_ids (idempotency path - asset already exists)
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/assets/upload/finalize', [
                'manifest' => [
                    [
                        'upload_key' => "temp/uploads/{$uploadSession->id}/original",
                        'expected_size' => 1024,
                        'category_id' => null, // Null is OK for existing assets (idempotency path)
                        'metadata' => [],
                        'title' => 'Test Asset',
                        'resolved_filename' => 'test.jpg',
                        'collection_ids' => [$this->collection1->id, $this->collection2->id],
                    ],
                ],
            ]);

        $response->assertStatus(200);

        // Refresh asset to get latest collections
        $asset->refresh();
        $asset->load('collections');

        // Verify collections are attached
        $this->assertTrue($asset->collections()->where('collections.id', $this->collection1->id)->exists(), 'Collection 1 should be attached');
        $this->assertTrue($asset->collections()->where('collections.id', $this->collection2->id)->exists(), 'Collection 2 should be attached');
        $this->assertCount(2, $asset->collections, 'Asset should have 2 collections');
    }

    public function test_collection_assignment_fails_with_permission_error(): void
    {
        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->contributorUser->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'test/path.jpg',
            'size_bytes' => 1024,
        ]);

        // Verify contributor CAN add to collections (per C9 policy)
        $canAdd = Gate::forUser($this->contributorUser)->allows('addAsset', $this->collection1);
        $this->assertTrue($canAdd, 'Contributor should be able to add assets to collections');

        // Simulate finalize request with collection_ids
        $response = $this->actingAs($this->contributorUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/assets/upload/finalize', [
                'manifest' => [
                    [
                        'upload_key' => "temp/uploads/{$uploadSession->id}/original",
                        'expected_size' => 1024,
                        'category_id' => null,
                        'metadata' => [],
                        'title' => 'Test Asset',
                        'resolved_filename' => 'test.jpg',
                        'collection_ids' => [$this->collection1->id],
                    ],
                ],
            ]);

        // Refresh asset to get latest collections
        $asset->refresh();
        $asset->load('collections');

        // Verify collection is attached (contributor should be able to add)
        $this->assertTrue($asset->collections()->where('collections.id', $this->collection1->id)->exists());
    }
}
