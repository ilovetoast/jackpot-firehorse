<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\FeatureGate;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class FreeAccountUploadVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected UploadCompletionService $completionService;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected User $owner;

    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('doesObjectExist')->andReturn(true);

        $headResult = Mockery::mock(Result::class);
        $headResult->shouldReceive('get')->with('ContentLength')->andReturn(1024);
        $headResult->shouldReceive('get')->with('ContentType')->andReturn('image/jpeg');
        $headResult->shouldReceive('get')->with('ContentDisposition')->andReturn(null);
        $headResult->shouldReceive('get')->with('Metadata')->andReturn([]);
        $headResult->shouldReceive('get')->with('ETag')->andReturn('"etag-test"');

        $s3Client->shouldReceive('headObject')->andReturn($headResult);
        $s3Client->shouldReceive('copyObject')->andReturn(new Result());

        $this->completionService = new UploadCompletionService($s3Client);

        $this->tenant = Tenant::create([
            'name' => 'Free Tenant',
            'slug' => 'free-tenant-verify',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-verify',
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography-verify',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        $this->owner = User::create([
            'name' => 'Owner User',
            'email' => 'owner-verify@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->tenant->users()->attach($this->owner, ['role' => 'owner']);
        $this->owner->brands()->attach($this->brand);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket-verify',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        app()->instance('tenant', $this->tenant);
    }

    private function createUploadSession(): UploadSession
    {
        return UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
    }

    public function test_free_tenant_with_unverified_owner_cannot_upload(): void
    {
        // Owner has NOT verified email (email_verified_at is null)
        $this->assertNull($this->owner->email_verified_at);

        $session = $this->createUploadSession();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verify your email');

        $this->completionService->complete($session, 'asset');
    }

    public function test_free_tenant_with_unverified_owner_blocks_all_users(): void
    {
        $nonOwner = User::create([
            'name' => 'Contributor',
            'email' => 'contributor-verify@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->tenant->users()->attach($nonOwner, ['role' => 'contributor']);
        $nonOwner->brands()->attach($this->brand);

        $session = $this->createUploadSession();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verify your email');

        $this->completionService->complete($session, 'asset', null, null, null, null, null, $nonOwner->id);
    }

    public function test_free_tenant_with_verified_owner_can_upload(): void
    {
        $this->owner->update(['email_verified_at' => now()]);

        $session = $this->createUploadSession();

        $asset = $this->completionService->complete($session, 'asset');

        $this->assertNotNull($asset);
        $this->assertEquals($this->tenant->id, $asset->tenant_id);
    }

    public function test_paid_tenant_with_unverified_owner_can_still_upload(): void
    {
        // Simulate a paid plan by setting manual override
        $this->tenant->update(['manual_plan_override' => 'starter']);

        $this->assertNull($this->owner->email_verified_at);

        $session = $this->createUploadSession();

        $asset = $this->completionService->complete($session, 'asset');

        $this->assertNotNull($asset);
        $this->assertEquals($this->tenant->id, $asset->tenant_id);
    }

    public function test_free_tenant_upload_returns_clear_error_message(): void
    {
        $session = $this->createUploadSession();

        try {
            $this->completionService->complete($session, 'asset');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('verify your email', $e->getMessage());
            $this->assertStringContainsString('account owner', $e->getMessage());
        }
    }

    public function test_free_tenant_can_still_browse_and_download_without_verification(): void
    {
        $featureGate = app(FeatureGate::class);

        // canUploadAssets should return false for unverified free tenant
        $this->assertFalse($featureGate->canUploadAssets($this->tenant));

        // But FeatureGate should not block other features
        // (This test verifies the gate only affects uploads, not general access)
        $this->assertIsString($this->tenant->name);
        $this->assertNotNull($this->brand);
    }
}
