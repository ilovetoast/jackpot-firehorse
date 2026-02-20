<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Phase 2B: Behavioral expectations for asset versioning.
 *
 * Test 1 — New Asset: v1 created, is_current true, storage_root_path → v1 path
 * Test 2 — Replace Asset: v2 created, v1 still exists, v1 is_current false, storage_root_path → v2 path
 */
class AssetVersioningUploadTest extends TestCase
{
    use RefreshDatabase;

    protected UploadCompletionService $completionService;
    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $s3Client = $this->createS3Mock(1024);
        $this->completionService = new UploadCompletionService($s3Client);

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Brand', 'slug' => 'brand']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    protected function createS3Mock(int $fileSize = 1024): S3Client
    {
        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('doesObjectExist')->andReturn(true);

        $headResult = Mockery::mock(Result::class);
        $headResult->shouldReceive('get')->with('ContentLength')->andReturn($fileSize);
        $headResult->shouldReceive('get')->with('ContentType')->andReturn('image/jpeg');
        $headResult->shouldReceive('get')->with('ContentDisposition')->andReturn(null);
        $headResult->shouldReceive('get')->with('Metadata')->andReturn([]);
        $headResult->shouldReceive('get')->with('ETag')->andReturn('"etag-abc123"');

        $s3Client->shouldReceive('headObject')->andReturn($headResult);
        $s3Client->shouldReceive('copyObject')->andReturn(new Result());
        $s3Client->shouldReceive('doesObjectExist')->andReturn(true);

        return $s3Client;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test 1 — New Asset
     * v1 created, is_current true, storage_root_path → v1 path
     */
    public function test_new_asset_creates_v1_with_correct_state(): void
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = $this->completionService->complete(
            $uploadSession,
            'asset',
            'photo.jpg',
            'Test Photo',
            null,
            null,
            [],
            $this->user->id
        );

        $this->assertNotNull($asset);

        // v1 created
        $v1 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();
        $this->assertNotNull($v1, 'v1 should be created');
        $this->assertTrue($v1->is_current, 'v1 should be is_current true');

        // storage_root_path → v1 path
        $expectedPath = "assets/{$asset->id}/v1/original.jpg";
        $this->assertEquals($expectedPath, $asset->storage_root_path, 'storage_root_path should point to v1 path');
        $this->assertEquals($expectedPath, $v1->file_path, 'version file_path should match');
    }

    /**
     * Test 2 — Replace Asset
     * v2 created, v1 still exists, v1 is_current false, storage_root_path → v2 path
     */
    public function test_replace_asset_creates_v2_v1_remains_v2_is_current(): void
    {
        // Create initial upload session (for v1 asset - simulate prior upload)
        $initialSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset with v1 (simulate prior upload)
        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $initialSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Original',
            'original_filename' => 'original.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'temp/placeholder', // Will update after we have asset id
        ]);

        $v1Path = "assets/{$asset->id}/v1/original.jpg";
        $asset->update(['storage_root_path' => $v1Path]);

        AssetVersion::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $v1Path,
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'checksum' => 'checksum-v1',
            'pipeline_status' => 'complete',
            'is_current' => true,
        ]);

        // Replace upload session - use mock with 2048 to match expected_size
        $s3Client = $this->createS3Mock(2048);
        $this->completionService = new UploadCompletionService($s3Client);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'mode' => 'replace',
            'asset_id' => $asset->id,
            'expected_size' => 2048,
            'uploaded_size' => 2048,
        ]);

        $updatedAsset = $this->completionService->complete(
            $uploadSession,
            null,
            'replacement.jpg',
            null,
            null,
            null,
            ['comment' => 'Replaced file'],
            $this->user->id
        );

        $this->assertNotNull($updatedAsset);
        $this->assertEquals($asset->id, $updatedAsset->id);

        // v1 still exists, is_current false
        $v1Reloaded = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();
        $this->assertNotNull($v1Reloaded, 'v1 should still exist');
        $this->assertFalse($v1Reloaded->is_current, 'v1 should be is_current false');

        // v2 created, is_current true
        $v2 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 2)->first();
        $this->assertNotNull($v2, 'v2 should be created');
        $this->assertTrue($v2->is_current, 'v2 should be is_current true');

        // storage_root_path → v2 path
        $expectedV2Path = "assets/{$asset->id}/v2/original.jpg";
        $this->assertEquals($expectedV2Path, $updatedAsset->storage_root_path, 'storage_root_path should point to v2 path');
        $this->assertEquals($expectedV2Path, $v2->file_path, 'v2 file_path should match');
    }
}
