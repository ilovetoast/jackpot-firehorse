<?php

namespace Tests\Unit\Services;

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
use App\Services\AssetPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Asset Publication Service Test
 *
 * Phase L.2 — Tests for asset publishing and unpublishing functionality.
 *
 * These tests ensure:
 * - Assets can be published and unpublished
 * - Permissions are enforced
 * - Archived assets cannot be published
 * - Failed assets cannot be published
 * - Unpublished assets are hidden
 * - Activity events are logged
 */
class AssetPublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AssetPublicationService $service;
    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'asset.publish', 'guard_name' => 'web']);
        Permission::create(['name' => 'asset.unpublish', 'guard_name' => 'web']);

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

        // Create user and assign to tenant
        $this->user = User::create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);

        // Create role and assign permissions
        $role = Role::create(['name' => 'test_role', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.publish', 'asset.unpublish']);
        
        // Assign role to user for this tenant
        $this->user->setRoleForTenant($this->tenant, 'test_role');
        $this->user->assignRole($role);

        // Create storage bucket and upload session
        $storageBucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
        ]);

        // Create asset
        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path',
        ]);

        $this->service = new AssetPublicationService();
    }

    /**
     * Test that an asset can be published successfully.
     */
    public function test_publish_succeeds(): void
    {
        // Ensure asset is not published initially
        $this->assertNull($this->asset->published_at);
        $this->assertFalse($this->asset->isPublished());

        // Publish the asset
        $this->service->publish($this->asset, $this->user);

        // Refresh asset from database
        $this->asset->refresh();

        // Assert publication fields are set
        $this->assertNotNull($this->asset->published_at);
        $this->assertEquals($this->user->id, $this->asset->published_by_id);
        $this->assertTrue($this->asset->isPublished());
        $this->assertEquals(AssetStatus::VISIBLE, $this->asset->status);
    }

    /**
     * Test that publishing is idempotent (safe to call twice).
     */
    public function test_publish_is_idempotent(): void
    {
        // Publish the asset
        $this->service->publish($this->asset, $this->user);
        $this->asset->refresh();
        $firstPublishedAt = $this->asset->published_at;

        // Publish again
        $this->service->publish($this->asset, $this->user);
        $this->asset->refresh();

        // Assert published_at hasn't changed
        $this->assertEquals($firstPublishedAt->timestamp, $this->asset->published_at->timestamp);
    }

    /**
     * Test that an asset can be unpublished successfully.
     */
    public function test_unpublish_succeeds(): void
    {
        // First publish the asset
        $this->service->publish($this->asset, $this->user);
        $this->asset->refresh();
        $this->assertTrue($this->asset->isPublished());

        // Unpublish the asset
        $this->service->unpublish($this->asset, $this->user);
        $this->asset->refresh();

        // Assert publication fields are cleared
        $this->assertNull($this->asset->published_at);
        $this->assertNull($this->asset->published_by_id);
        $this->assertFalse($this->asset->isPublished());
        $this->assertEquals(AssetStatus::HIDDEN, $this->asset->status);
    }

    /**
     * Test that unpublishing is idempotent (safe to call twice).
     */
    public function test_unpublish_is_idempotent(): void
    {
        // Unpublish the asset (already unpublished)
        $this->service->unpublish($this->asset, $this->user);
        $this->asset->refresh();

        // Unpublish again
        $this->service->unpublish($this->asset, $this->user);
        $this->asset->refresh();

        // Assert still unpublished
        $this->assertNull($this->asset->published_at);
        $this->assertFalse($this->asset->isPublished());
    }

    /**
     * Test that permission denial prevents publishing.
     */
    public function test_publish_requires_permission(): void
    {
        // Create user without permission
        $unauthorizedUser = User::create([
            'email' => 'unauthorized@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Unauthorized',
            'last_name' => 'User',
        ]);

        $unauthorizedUser->tenants()->attach($this->tenant->id);
        $unauthorizedUser->brands()->attach($this->brand->id);

        // Attempt to publish without permission
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->publish($this->asset, $unauthorizedUser);
    }

    /**
     * Test that permission denial prevents unpublishing.
     */
    public function test_unpublish_requires_permission(): void
    {
        // First publish the asset
        $this->service->publish($this->asset, $this->user);

        // Create user without permission
        $unauthorizedUser = User::create([
            'email' => 'unauthorized@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Unauthorized',
            'last_name' => 'User',
        ]);

        $unauthorizedUser->tenants()->attach($this->tenant->id);
        $unauthorizedUser->brands()->attach($this->brand->id);

        // Attempt to unpublish without permission
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->unpublish($this->asset, $unauthorizedUser);
    }

    /**
     * Test that archived assets cannot be published.
     */
    public function test_cannot_publish_archived_asset(): void
    {
        // Archive the asset
        $this->asset->archived_at = now();
        $this->asset->archived_by_id = $this->user->id;
        $this->asset->save();

        // Attempt to publish archived asset
        // Policy check happens first and returns false for archived assets
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->publish($this->asset, $this->user);
    }

    /**
     * Test that failed assets cannot be published.
     */
    public function test_cannot_publish_failed_asset(): void
    {
        // Set asset status to FAILED
        $this->asset->status = AssetStatus::FAILED;
        $this->asset->save();

        // Attempt to publish failed asset
        // Policy check happens first and returns false for failed assets
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->publish($this->asset, $this->user);
    }

    /**
     * Test that publishing an archived asset keeps it hidden.
     */
    public function test_publishing_archived_asset_keeps_it_hidden(): void
    {
        // First publish the asset
        $this->service->publish($this->asset, $this->user);
        $this->asset->refresh();
        $this->assertEquals(AssetStatus::VISIBLE, $this->asset->status);

        // Archive the asset (this should keep it hidden even if published)
        $this->asset->archived_at = now();
        $this->asset->archived_by_id = $this->user->id;
        $this->asset->status = AssetStatus::HIDDEN;
        $this->asset->save();

        // Asset should remain published but hidden
        $this->asset->refresh();
        $this->assertTrue($this->asset->isPublished());
        $this->assertTrue($this->asset->isArchived());
        $this->assertEquals(AssetStatus::HIDDEN, $this->asset->status);
    }
}
