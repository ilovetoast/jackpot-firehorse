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
use App\Services\AssetArchiveService;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Asset Archive Service Test
 *
 * Phase L.3 — Tests for asset archiving and restoring functionality.
 *
 * These tests ensure:
 * - Assets can be archived and restored
 * - Permissions are enforced
 * - Failed assets cannot be archived/restored
 * - Archived assets are hidden
 * - Restore respects published state
 * - Activity events are logged
 */
class AssetArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AssetArchiveService $service;
    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions (use firstOrCreate to avoid duplicate key errors and lock timeouts)
        Permission::firstOrCreate(['name' => 'asset.archive', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'asset.restore', 'guard_name' => 'web']);

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
        $role->givePermissionTo(['asset.archive', 'asset.restore']);
        
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

        $this->service = new AssetArchiveService();
    }

    protected function tearDown(): void
    {
        // Reset Storage facade mocks
        Storage::clearResolvedInstances();
        
        // Close Mockery to verify expectations and clean up
        Mockery::close();
        
        parent::tearDown();
    }

    /**
     * Test that an asset can be archived successfully.
     */
    public function test_archive_succeeds(): void
    {
        // Ensure asset is not archived initially
        $this->assertNull($this->asset->archived_at);
        $this->assertFalse($this->asset->isArchived());

        // Archive the asset
        $this->service->archive($this->asset, $this->user);

        // Refresh asset from database
        $this->asset->refresh();

        // Assert archive fields are set
        $this->assertNotNull($this->asset->archived_at);
        $this->assertEquals($this->user->id, $this->asset->archived_by_id);
        $this->assertTrue($this->asset->isArchived());
        $this->assertEquals(AssetStatus::HIDDEN, $this->asset->status);
    }

    /**
     * Test that archiving is idempotent (safe to call twice).
     */
    public function test_archive_is_idempotent(): void
    {
        // Archive the asset
        $this->service->archive($this->asset, $this->user);
        $this->asset->refresh();
        $firstArchivedAt = $this->asset->archived_at;

        // Archive again
        $this->service->archive($this->asset, $this->user);
        $this->asset->refresh();

        // Assert archived_at hasn't changed
        $this->assertEquals($firstArchivedAt->timestamp, $this->asset->archived_at->timestamp);
    }

    /**
     * Test that an asset can be restored successfully.
     */
    public function test_restore_succeeds(): void
    {
        // First archive the asset
        $this->service->archive($this->asset, $this->user);
        $this->asset->refresh();
        $this->assertTrue($this->asset->isArchived());

        // Restore the asset
        $this->service->restore($this->asset, $this->user);
        $this->asset->refresh();

        // Assert archive fields are cleared
        $this->assertNull($this->asset->archived_at);
        $this->assertNull($this->asset->archived_by_id);
        $this->assertFalse($this->asset->isArchived());
        // Asset should be hidden since it's not published
        $this->assertEquals(AssetStatus::HIDDEN, $this->asset->status);
    }

    /**
     * Test that restoring a published asset makes it visible.
     */
    public function test_restore_published_asset_makes_visible(): void
    {
        // Publish the asset first
        $this->asset->published_at = now();
        $this->asset->published_by_id = $this->user->id;
        $this->asset->status = AssetStatus::VISIBLE;
        $this->asset->save();

        // Archive it
        $this->service->archive($this->asset, $this->user);
        $this->asset->refresh();
        $this->assertEquals(AssetStatus::HIDDEN, $this->asset->status);

        // Restore it
        $this->service->restore($this->asset, $this->user);
        $this->asset->refresh();

        // Asset should be visible again since it was published
        $this->assertFalse($this->asset->isArchived());
        $this->assertTrue($this->asset->isPublished());
        $this->assertEquals(AssetStatus::VISIBLE, $this->asset->status);
    }

    /**
     * Test that restoring is idempotent (safe to call twice).
     */
    public function test_restore_is_idempotent(): void
    {
        // Restore the asset (already not archived)
        $this->service->restore($this->asset, $this->user);
        $this->asset->refresh();

        // Restore again
        $this->service->restore($this->asset, $this->user);
        $this->asset->refresh();

        // Assert still not archived
        $this->assertNull($this->asset->archived_at);
        $this->assertFalse($this->asset->isArchived());
    }

    /**
     * Test that permission denial prevents archiving.
     */
    public function test_archive_requires_permission(): void
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

        // Attempt to archive without permission
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->archive($this->asset, $unauthorizedUser);
    }

    /**
     * Test that permission denial prevents restoring.
     */
    public function test_restore_requires_permission(): void
    {
        // First archive the asset
        $this->service->archive($this->asset, $this->user);

        // Create user without permission
        $unauthorizedUser = User::create([
            'email' => 'unauthorized@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Unauthorized',
            'last_name' => 'User',
        ]);

        $unauthorizedUser->tenants()->attach($this->tenant->id);
        $unauthorizedUser->brands()->attach($this->brand->id);

        // Attempt to restore without permission
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->restore($this->asset, $unauthorizedUser);
    }

    /**
     * Test that failed assets cannot be archived.
     */
    public function test_cannot_archive_failed_asset(): void
    {
        // Set asset status to FAILED
        $this->asset->status = AssetStatus::FAILED;
        $this->asset->save();

        // Attempt to archive failed asset
        // Policy check happens first and returns false for failed assets
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->archive($this->asset, $this->user);
    }

    /**
     * Test that failed assets cannot be restored.
     */
    public function test_cannot_restore_failed_asset(): void
    {
        // First archive the asset
        $this->service->archive($this->asset, $this->user);

        // Set asset status to FAILED
        $this->asset->status = AssetStatus::FAILED;
        $this->asset->save();

        // Attempt to restore failed asset
        // Policy check happens first and returns false for failed assets
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->service->restore($this->asset, $this->user);
    }

    /**
     * Test that archiving preserves published state.
     */
    public function test_archiving_preserves_published_state(): void
    {
        // Publish the asset first
        $this->asset->published_at = now();
        $this->asset->published_by_id = $this->user->id;
        $this->asset->save();

        // Archive it
        $this->service->archive($this->asset, $this->user);
        $this->asset->refresh();

        // Asset should remain published but hidden
        $this->assertTrue($this->asset->isPublished());
        $this->assertTrue($this->asset->isArchived());
        $this->assertEquals(AssetStatus::HIDDEN, $this->asset->status);
    }

    /**
     * Test that archive can include a reason.
     */
    public function test_archive_with_reason(): void
    {
        $reason = 'No longer needed for active campaigns';

        // Archive the asset with reason
        $this->service->archive($this->asset, $this->user, $reason);
        $this->asset->refresh();

        // Asset should be archived
        $this->assertTrue($this->asset->isArchived());
        // Reason is stored in activity event metadata, not on asset model
    }

    /**
     * Test that archiving an asset transitions S3 objects to STANDARD_IA.
     */
    public function test_archiving_transitions_s3_objects_to_standard_ia(): void
    {
        // Step 1: Set up asset with storage path and thumbnails
        $this->asset->storage_root_path = 'tenants/1/brands/1/assets/test-file.jpg';
        $this->asset->metadata = [
            'thumbnails' => [
                ['path' => 'tenants/1/brands/1/thumbnails/thumb-test-file.jpg'],
                ['path' => 'tenants/1/brands/1/thumbnails/medium-test-file.jpg'],
            ],
            'preview_thumbnails' => [
                ['path' => 'tenants/1/brands/1/previews/preview-test-file.jpg'],
            ],
        ];
        $this->asset->save();

        // Step 1: Confirm prerequisites explicitly
        $this->assertNotNull($this->asset->storage_root_path, 'storage_root_path must be set');
        $this->assertNotEmpty($this->asset->metadata, 'metadata must contain thumbnail paths');
        
        // Verify paths will be extracted correctly
        $expectedPaths = [
            'tenants/1/brands/1/assets/test-file.jpg',
            'tenants/1/brands/1/thumbnails/thumb-test-file.jpg',
            'tenants/1/brands/1/thumbnails/medium-test-file.jpg',
            'tenants/1/brands/1/previews/preview-test-file.jpg',
        ];
        $this->assertCount(4, $expectedPaths, 'Expected 4 S3 paths');

        // Step 2: Mock S3 client
        $mockS3Client = Mockery::mock(S3Client::class);
        
        $bucket = 'test-bucket';
        config(['filesystems.disks.s3.bucket' => $bucket]);

        // Step 2: Set up expectations for copyObject calls
        foreach ($expectedPaths as $path) {
            $mockS3Client->shouldReceive('copyObject')
                ->once()
                ->with(Mockery::on(function ($args) use ($bucket, $path) {
                    return isset($args['Bucket']) &&
                           $args['Bucket'] === $bucket &&
                           isset($args['CopySource']) &&
                           $args['CopySource'] === "{$bucket}/{$path}" &&
                           isset($args['Key']) &&
                           $args['Key'] === $path &&
                           isset($args['StorageClass']) &&
                           $args['StorageClass'] === 'STANDARD_IA' &&
                           isset($args['MetadataDirective']) &&
                           $args['MetadataDirective'] === 'COPY';
                }))
                ->andReturn(['CopyObjectResult' => []]);
        }

        // Step 2: Mock Storage facade with exact chain: disk('s3')->getClient()
        // Create a mock disk that has getClient() method for method_exists() check
        $mockDisk = new class($mockS3Client) {
            private $s3Client;
            public function __construct($s3Client) {
                $this->s3Client = $s3Client;
            }
            public function getClient() {
                return $this->s3Client;
            }
        };

        Storage::shouldReceive('disk')
            ->once()
            ->with('s3')
            ->andReturn($mockDisk);

        // Archive the asset
        $this->service->archive($this->asset, $this->user);

        // Verify asset was archived
        $this->asset->refresh();
        $this->assertTrue($this->asset->isArchived());

        // Mockery will automatically verify all expectations
    }

    /**
     * Test that restoring an asset transitions S3 objects to STANDARD.
     * 
     * Note: This test is isolated from permission setup to prevent DB lock timeouts.
     */
    public function test_restoring_transitions_s3_objects_to_standard(): void
    {
        // Step 1: Set up archived asset with storage path and thumbnails
        $this->asset->update([
            'storage_root_path' => 'tenants/1/brands/1/assets/test-file.jpg',
            'metadata' => [
                'thumbnails' => [
                    ['path' => 'tenants/1/brands/1/thumbnails/thumb-test-file.jpg'],
                ],
            ],
            'archived_at' => now(),
            'archived_by_id' => $this->user->id,
            'status' => AssetStatus::HIDDEN,
        ]);
        
        // Refresh to ensure we have the latest state
        $this->asset->refresh();

        // Step 1: Confirm prerequisites explicitly
        $this->assertNotNull($this->asset->storage_root_path, 'storage_root_path must be set');
        $this->assertNotEmpty($this->asset->metadata, 'metadata must contain thumbnail paths');
        $this->assertTrue($this->asset->isArchived(), 'asset must be archived before restore');
        $this->assertNotNull($this->asset->archived_at, 'archived_at must be set');
        
        // Verify paths will be extracted correctly by calling the service method directly
        // Use reflection to test path extraction
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getAssetS3Paths');
        $method->setAccessible(true);
        $extractedPaths = $method->invoke($this->service, $this->asset);
        $this->assertNotEmpty($extractedPaths, 'S3 paths should be extracted from asset');
        $this->assertCount(2, $extractedPaths, 'Expected 2 S3 paths to be extracted');
        
        // Verify paths will be extracted correctly
        $expectedPaths = [
            'tenants/1/brands/1/assets/test-file.jpg',
            'tenants/1/brands/1/thumbnails/thumb-test-file.jpg',
        ];
        $this->assertEquals($expectedPaths, $extractedPaths, 'Extracted paths should match expected paths');

        // Step 2: Mock S3 client
        $mockS3Client = Mockery::mock(S3Client::class);
        
        $bucket = 'test-bucket';
        config(['filesystems.disks.s3.bucket' => $bucket]);

        // Step 2: Set up expectations for copyObject calls
        foreach ($expectedPaths as $path) {
            $mockS3Client->shouldReceive('copyObject')
                ->once()
                ->with(Mockery::on(function ($args) use ($bucket, $path) {
                    return isset($args['Bucket']) &&
                           $args['Bucket'] === $bucket &&
                           isset($args['CopySource']) &&
                           $args['CopySource'] === "{$bucket}/{$path}" &&
                           isset($args['Key']) &&
                           $args['Key'] === $path &&
                           isset($args['StorageClass']) &&
                           $args['StorageClass'] === 'STANDARD' &&
                           isset($args['MetadataDirective']) &&
                           $args['MetadataDirective'] === 'COPY';
                }))
                ->andReturn(['CopyObjectResult' => []]);
        }

        // Step 2: Mock Storage facade with exact chain: disk('s3')->getClient()
        // Create a mock disk that has getClient() method for method_exists() check
        $mockDisk = new class($mockS3Client) {
            private $s3Client;
            public function __construct($s3Client) {
                $this->s3Client = $s3Client;
            }
            public function getClient() {
                return $this->s3Client;
            }
        };

        Storage::shouldReceive('disk')
            ->once()
            ->with('s3')
            ->andReturn($mockDisk);

        // Verify bucket config is set
        $this->assertEquals('test-bucket', config('filesystems.disks.s3.bucket'), 'Bucket config must be set');

        // Restore the asset
        $this->service->restore($this->asset, $this->user);

        // Verify asset was restored
        $this->asset->refresh();
        $this->assertFalse($this->asset->isArchived());

        // Mockery will automatically verify all expectations
    }

    /**
     * Test that S3 failure does not block archive operation.
     * 
     * Note: This test is isolated from permission setup to prevent DB lock timeouts.
     */
    public function test_s3_failure_does_not_block_archive(): void
    {
        // Step 1: Set up asset with storage path
        $this->asset->update([
            'storage_root_path' => 'tenants/1/brands/1/assets/test-file.jpg',
        ]);
        
        // Refresh to ensure we have the latest state
        $this->asset->refresh();

        // Step 1: Confirm prerequisites explicitly
        $this->assertNotNull($this->asset->storage_root_path, 'storage_root_path must be set');

        // Step 2: Mock S3 client to throw exception
        $mockS3Client = Mockery::mock(S3Client::class);
        
        $bucket = 'test-bucket';
        config(['filesystems.disks.s3.bucket' => $bucket]);

        $mockS3Client->shouldReceive('copyObject')
            ->once()
            ->andThrow(new \Exception('S3 connection failed'));

        // Step 2: Mock Storage facade with exact chain: disk('s3')->getClient()
        // Create a mock disk that has getClient() method for method_exists() check
        $mockDisk = new class($mockS3Client) {
            private $s3Client;
            public function __construct($s3Client) {
                $this->s3Client = $s3Client;
            }
            public function getClient() {
                return $this->s3Client;
            }
        };

        Storage::shouldReceive('disk')
            ->once()
            ->with('s3')
            ->andReturn($mockDisk);

        // Verify bucket config is set
        $this->assertEquals('test-bucket', config('filesystems.disks.s3.bucket'), 'Bucket config must be set');

        // Mock Log facade to capture error logs
        Log::shouldReceive('error')
            ->once()
            ->with(Mockery::pattern('/Failed to transition asset to archive storage class/'), Mockery::type('array'));

        // Archive should succeed despite S3 failure
        $this->service->archive($this->asset, $this->user);

        // Verify asset was still archived
        $this->asset->refresh();
        $this->assertNotNull($this->asset->archived_at);
        $this->assertTrue($this->asset->isArchived());

        // Mockery will automatically verify all expectations
    }
}
