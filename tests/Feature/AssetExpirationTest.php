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
use Tests\TestCase;

/**
 * Phase M: Test asset expiration (time-based access control).
 * 
 * Verifies that:
 * - Expired assets are hidden by default
 * - Restoring expiration restores visibility
 * - Archived assets still override expiration
 * - isExpired() helper works correctly
 */
class AssetExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Category $category;
    protected StorageBucket $bucket;
    protected UploadSession $uploadSession;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant and brand
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create user
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);

        // Create category
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'asset_type' => AssetType::ASSET,
        ]);

        // Create storage bucket
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create upload session
        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'total_size' => 1000,
            'expected_size' => 1000,
            'uploaded_size' => 1000,
        ]);
    }

    /**
     * Test that expired assets are hidden by default.
     */
    public function test_expired_assets_are_hidden_by_default(): void
    {
        // Create upload sessions for each asset (upload_session_id has unique constraint)
        $expiredSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'total_size' => 1000,
            'expected_size' => 1000,
            'uploaded_size' => 1000,
        ]);

        $activeSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'total_size' => 1000,
            'expected_size' => 1000,
            'uploaded_size' => 1000,
        ]);

        // Create an expired asset
        $expiredAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $expiredSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Expired Asset',
            'original_filename' => 'expired.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now()->subDays(10),
            'expires_at' => now()->subDays(1), // Expired yesterday
        ]);

        // Create a non-expired asset
        $activeAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $activeSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Active Asset',
            'original_filename' => 'active.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now()->subDays(10),
            'expires_at' => now()->addDays(1), // Expires tomorrow
        ]);

        // Query assets (simulating AssetController::index)
        $assets = Asset::where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->whereNull('deleted_at')
            ->get();

        // Expired asset should not be in results
        $this->assertFalse($assets->contains($expiredAsset));
        
        // Active asset should be in results
        $this->assertTrue($assets->contains($activeAsset));
    }

    /**
     * Test that restoring expiration restores visibility.
     */
    public function test_restoring_expiration_restores_visibility(): void
    {
        // Create an expired asset
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now()->subDays(10),
            'expires_at' => now()->subDays(1), // Expired
        ]);

        // Verify it's expired
        $this->assertTrue($asset->isExpired());

        // Restore expiration (set to future date)
        $asset->expires_at = now()->addDays(1);
        $asset->save();

        // Verify it's no longer expired
        $this->assertFalse($asset->isExpired());

        // Query assets - should now be visible
        $assets = Asset::where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNotNull('published_at')
            ->whereNull('archived_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->whereNull('deleted_at')
            ->get();

        $this->assertTrue($assets->contains($asset));
    }

    /**
     * Test that archived assets still override expiration.
     */
    public function test_archived_assets_override_expiration(): void
    {
        // Create an archived asset (even if not expired, it should be hidden)
        $archivedAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $this->uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Archived Asset',
            'original_filename' => 'archived.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'published_at' => now()->subDays(10),
            'archived_at' => now()->subDays(5),
            'expires_at' => now()->addDays(1), // Not expired, but archived
        ]);

        // Query assets (default view excludes archived)
        $assets = Asset::where('tenant_id', $this->tenant->id)
            ->where('brand_id', $this->brand->id)
            ->where('type', AssetType::ASSET)
            ->where('status', AssetStatus::VISIBLE)
            ->whereNotNull('published_at')
            ->whereNull('archived_at') // Archived assets excluded
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->whereNull('deleted_at')
            ->get();

        // Archived asset should not be in results (archived takes precedence)
        $this->assertFalse($assets->contains($archivedAsset));
    }

    /**
     * Test isExpired() helper method.
     */
    public function test_is_expired_helper(): void
    {
        // Create upload sessions for each asset (upload_session_id has unique constraint)
        $noExpSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'total_size' => 1000,
            'expected_size' => 1000,
            'uploaded_size' => 1000,
        ]);

        $futureExpSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'total_size' => 1000,
            'expected_size' => 1000,
            'uploaded_size' => 1000,
        ]);

        $pastExpSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'total_size' => 1000,
            'expected_size' => 1000,
            'uploaded_size' => 1000,
        ]);

        // Asset without expiration
        $noExpiration = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $noExpSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'No Expiration',
            'original_filename' => 'no-exp.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'expires_at' => null,
        ]);

        $this->assertFalse($noExpiration->isExpired());

        // Asset with future expiration
        $futureExpiration = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $futureExpSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Future Expiration',
            'original_filename' => 'future-exp.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'expires_at' => now()->addDays(1),
        ]);

        $this->assertFalse($futureExpiration->isExpired());

        // Asset with past expiration
        $pastExpiration = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $pastExpSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Past Expiration',
            'original_filename' => 'past-exp.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
            'storage_root_path' => 'test/path',
            'metadata' => ['category_id' => $this->category->id],
            'expires_at' => now()->subDays(1),
        ]);

        $this->assertTrue($pastExpiration->isExpired());
    }
}
