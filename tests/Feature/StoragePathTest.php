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
 * Phase 5 + 6: Guardrail test for canonical storage path structure.
 *
 * All shared bucket assets MUST follow:
 *   tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}
 *
 * Fail test if stored path does not start with tenants/{tenant_uuid}/assets/{asset_uuid}/v1/
 */
class StoragePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function createS3Mock(int $fileSize = 1024, string $contentType = 'image/jpeg'): S3Client
    {
        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('doesObjectExist')->andReturn(true);

        $headResult = Mockery::mock(Result::class);
        $headResult->shouldReceive('get')->with('ContentLength')->andReturn($fileSize);
        $headResult->shouldReceive('get')->with('ContentType')->andReturn($contentType);
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

    public function test_upload_asset_stored_path_starts_with_canonical_structure(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'manual_plan_override' => 'pro']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Brand', 'slug' => 'brand']);
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        app()->instance('tenant', $tenant);
        app()->instance('brand', $brand);

        $s3Client = $this->createS3Mock(1024);
        $completionService = new UploadCompletionService($s3Client);

        $uploadSession = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = $completionService->complete(
            $uploadSession,
            'asset',
            'photo.jpg',
            'Test Photo',
            null,
            null,
            [],
            $user->id
        );

        $this->assertNotNull($asset);
        $this->assertNotNull($tenant->uuid, 'Tenant must have uuid for canonical path');

        $expectedPrefix = "tenants/{$tenant->uuid}/assets/{$asset->id}/v1/";
        $this->assertStringStartsWith(
            $expectedPrefix,
            $asset->storage_root_path,
            "Stored path must start with {$expectedPrefix}. Got: {$asset->storage_root_path}"
        );

        $v1 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();
        $this->assertNotNull($v1);
        $this->assertStringStartsWith(
            $expectedPrefix,
            $v1->file_path,
            "Version file_path must start with {$expectedPrefix}. Got: {$v1->file_path}"
        );
    }
}
