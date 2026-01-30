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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * C9.1: Real-world collection assignment test.
 * 
 * Simulates actual upload flow to catch issues like:
 * - Brand mismatch between upload session and active brand
 * - Permission checks failing silently
 * - Collection IDs not being sent in manifest
 * - Approval settings blocking assignment
 */
class CollectionUploadRealWorldTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected StorageBucket $bucket;
    protected User $adminUser;
    protected Collection $testCollection;

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

        $this->testCollection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'test',
            'visibility' => 'brand',
            'is_public' => false,
        ]);
    }

    public function test_collection_assigned_during_upload_matches_real_world_flow(): void
    {
        // Create upload session (simulating real upload)
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id, // C9.1: Upload session has brand_id
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset first (simulating what finalize does - idempotency path)
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

        // Verify asset has no collections initially
        $this->assertCount(0, $asset->collections, 'Asset should start with no collections');

        // Simulate finalize with collection_ids (idempotency path - asset already exists)
        // C9.1: This simulates the real-world scenario where user selects "test" collection
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/assets/upload/finalize', [
                'manifest' => [
                    [
                        'upload_key' => "temp/uploads/{$uploadSession->id}/original",
                        'expected_size' => 1024,
                        'category_id' => null, // Null for existing assets (idempotency)
                        'metadata' => [],
                        'title' => 'Test Asset',
                        'resolved_filename' => 'test.jpg',
                        'collection_ids' => [$this->testCollection->id], // C9.1: Collection ID provided (like "test" collection)
                    ],
                ],
            ]);

        $response->assertStatus(200);
        
        // Refresh to get latest collections
        $asset->refresh();
        $asset->load('collections');
        
        // C9.1: Verify collection is attached (this test catches the bug)
        $this->assertTrue(
            $asset->collections()->where('collections.id', $this->testCollection->id)->exists(),
            "Collection 'test' (ID: {$this->testCollection->id}) should be attached to asset {$asset->id}. This test catches collection assignment failures during upload."
        );
        $this->assertCount(1, $asset->collections, "Asset should have exactly 1 collection after finalize");
    }

    public function test_collection_assignment_fails_when_brand_mismatch(): void
    {
        // Create another brand
        $otherBrand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'other']);
        
        // Create collection in other brand
        $otherCollection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Other Brand Collection',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset first (idempotency path)
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

        // Try to assign collection from different brand
        $response = $this->actingAs($this->adminUser)
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
                        'collection_ids' => [$otherCollection->id],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        
        $asset->refresh();
        $asset->load('collections');
        
        // Collection should NOT be attached (brand mismatch)
        $this->assertFalse(
            $asset->collections()->where('collections.id', $otherCollection->id)->exists(),
            "Collection from different brand should not be attached"
        );
    }
}
