<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Test is_published Flag
 * 
 * Goal: Verify that published assets expose is_published: true
 * for both Assets and Deliverables.
 * 
 * This ensures the UI can reliably determine publication state
 * without inferring from approval, lifecycle enums, or fallbacks.
 */
class IsPublishedFlagTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create test brand
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create test user
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        // Create storage bucket
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    /**
     * Test: Published Asset exposes is_published: true
     */
    public function test_published_asset_exposes_is_published_true(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create a published asset
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Published Asset',
            'original_filename' => 'published.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/published.jpg',
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        // Test Assets index endpoint
        $response = $this->get('/app/assets');
        $response->assertStatus(200);
        $data = $response->inertiaPage();
        
        $assetData = collect($data['props']['assets'] ?? [])
            ->firstWhere('id', $asset->id);
        
        $this->assertNotNull($assetData, 'Asset should appear in index');
        $this->assertTrue(
            $assetData['is_published'] === true,
            'Published asset must expose is_published: true'
        );
        $this->assertNotNull(
            $assetData['published_at'],
            'Published asset must have published_at timestamp'
        );
    }

    /**
     * Test: Unpublished Asset exposes is_published: false
     */
    public function test_unpublished_asset_exposes_is_published_false(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create an unpublished asset
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Unpublished Asset',
            'original_filename' => 'unpublished.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/unpublished.jpg',
            'published_at' => null, // Unpublished
            'published_by_id' => null,
        ]);

        // Test Assets index endpoint with unpublished filter
        $response = $this->get('/app/assets?lifecycle=unpublished');
        $response->assertStatus(200);
        $data = $response->inertiaPage();
        
        $assetData = collect($data['props']['assets'] ?? [])
            ->firstWhere('id', $asset->id);
        
        $this->assertNotNull($assetData, 'Unpublished asset should appear in unpublished filter');
        $this->assertTrue(
            $assetData['is_published'] === false,
            'Unpublished asset must expose is_published: false'
        );
        $this->assertNull(
            $assetData['published_at'],
            'Unpublished asset must have null published_at'
        );
    }

    /**
     * Test: Published Deliverable exposes is_published: true
     */
    public function test_published_deliverable_exposes_is_published_true(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create a published deliverable
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $deliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Published Deliverable',
            'original_filename' => 'published-deliverable.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/published-deliverable.jpg',
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        // Test Deliverables index endpoint
        $response = $this->get('/app/deliverables');
        $response->assertStatus(200);
        $data = $response->inertiaPage();
        
        $deliverableData = collect($data['props']['assets'] ?? [])
            ->firstWhere('id', $deliverable->id);
        
        $this->assertNotNull($deliverableData, 'Published deliverable should appear in index');
        $this->assertTrue(
            $deliverableData['is_published'] === true,
            'Published deliverable must expose is_published: true'
        );
        $this->assertNotNull(
            $deliverableData['published_at'],
            'Published deliverable must have published_at timestamp'
        );
    }

    /**
     * Test: Unpublished Deliverable exposes is_published: false
     */
    public function test_unpublished_deliverable_exposes_is_published_false(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Create an unpublished deliverable
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $deliverable = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::DELIVERABLE,
            'title' => 'Unpublished Deliverable',
            'original_filename' => 'unpublished-deliverable.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_bucket_id' => $this->bucket->id,
            'storage_root_path' => 'assets/test/unpublished-deliverable.jpg',
            'published_at' => null, // Unpublished
            'published_by_id' => null,
        ]);

        // Test Deliverables index endpoint with unpublished filter
        $response = $this->get('/app/deliverables?lifecycle=unpublished');
        $response->assertStatus(200);
        $data = $response->inertiaPage();
        
        $deliverableData = collect($data['props']['assets'] ?? [])
            ->firstWhere('id', $deliverable->id);
        
        $this->assertNotNull($deliverableData, 'Unpublished deliverable should appear in unpublished filter');
        $this->assertTrue(
            $deliverableData['is_published'] === false,
            'Unpublished deliverable must expose is_published: false'
        );
        $this->assertNull(
            $deliverableData['published_at'],
            'Unpublished deliverable must have null published_at'
        );
    }
}
