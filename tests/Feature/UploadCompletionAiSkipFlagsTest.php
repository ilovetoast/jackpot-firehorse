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
use App\Services\AiUsageService;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * C9.2: Upload-time AI skip flags must be explicit on the asset before ProcessAssetJob runs.
 */
class UploadCompletionAiSkipFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected UploadCompletionService $completionService;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected User $user;

    protected StorageBucket $bucket;

    protected UploadSession $uploadSession;

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
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-ai-skip',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-ai-skip',
        ]);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography-ai-skip',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'ai-skip-test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket-ai-skip',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::UPLOADING,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    public function test_explicit_false_skip_flags_are_persisted_for_pipeline(): void
    {
        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test',
            null,
            $this->category->id,
            null,
            $this->user->id,
            false,
            false,
        );

        $asset->refresh();
        $meta = $asset->metadata ?? [];

        $this->assertArrayHasKey('_skip_ai_tagging', $meta);
        $this->assertArrayHasKey('_skip_ai_metadata', $meta);
        $this->assertFalse($meta['_skip_ai_tagging']);
        $this->assertFalse($meta['_skip_ai_metadata']);
    }

    public function test_user_opt_out_persists_true_skip_flags(): void
    {
        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test',
            null,
            $this->category->id,
            null,
            $this->user->id,
            true,
            true,
        );

        $asset->refresh();
        $meta = $asset->metadata ?? [];

        $this->assertTrue($meta['_skip_ai_tagging']);
        $this->assertTrue($meta['_skip_ai_metadata']);
    }

    public function test_monthly_quota_exceeded_forces_skip_even_when_user_wants_ai(): void
    {
        $this->mock(AiUsageService::class, function ($mock) {
            $mock->shouldReceive('canUseFeature')->andReturn(false);
        });

        $asset = $this->completionService->complete(
            $this->uploadSession,
            'asset',
            'test.jpg',
            'Test',
            null,
            $this->category->id,
            null,
            $this->user->id,
            false,
            false,
        );

        $asset->refresh();
        $meta = $asset->metadata ?? [];

        $this->assertTrue($meta['_skip_ai_tagging']);
        $this->assertTrue($meta['_skip_ai_metadata']);
        $this->assertSame('monthly_quota_exceeded', $meta['_ai_tagging_skipped_reason'] ?? null);
        $this->assertSame('monthly_quota_exceeded', $meta['_ai_metadata_skipped_reason'] ?? null);
    }
}
