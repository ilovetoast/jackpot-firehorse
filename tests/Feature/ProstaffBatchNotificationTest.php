<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\ProcessProstaffUploadBatchJob;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Notification;
use App\Models\ProstaffUploadBatch;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\FeatureGate;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\UploadCompletionService;
use Aws\Result;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProstaffBatchNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected Category $category;

    protected StorageBucket $bucket;

    protected UploadCompletionService $completionService;

    protected User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-06 10:02:00', 'UTC'));

        $this->mock(FeatureGate::class, function ($mock) {
            $mock->shouldReceive('notificationsEnabled')->andReturn(true);
            $mock->shouldReceive('approvalsEnabled')->andReturn(true);
            $mock->shouldReceive('allows')->andReturn(true);
        });

        $this->completionService = new UploadCompletionService($this->createS3Mock());

        $this->tenant = Tenant::create([
            'name' => 'T Prostaff Batch',
            'slug' => 't-prostaff-batch',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand Batch',
            'slug' => 'brand-batch',
            'settings' => [
                'contributor_upload_requires_approval' => false,
            ],
        ]);

        $this->enableCreatorModuleForTenant($this->tenant);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Batch Cat',
            'slug' => 'batch-cat',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket-batch',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->approver = User::create([
            'email' => 'approver-batch@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Ap',
            'last_name' => 'Prover',
        ]);
        $this->approver->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->approver->brands()->attach($this->brand->id, [
            'role' => 'brand_manager',
            'requires_approval' => false,
            'removed_at' => null,
        ]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
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
        $headResult->shouldReceive('get')->with('ETag')->andReturn('"etag-test"');

        $s3Client->shouldReceive('headObject')->andReturn($headResult);
        $s3Client->shouldReceive('copyObject')->andReturn(new Result);

        return $s3Client;
    }

    protected function createProstaffUser(): User
    {
        $user = User::create([
            'email' => 'prostaff-batch-'.uniqid('', true).'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Pro',
            'last_name' => 'Staff',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        app(AssignProstaffMember::class)->assign($user, $this->brand, []);

        return $user->fresh();
    }

    protected function completeOneUpload(User $user): void
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

        $this->completionService->complete(
            $uploadSession,
            'asset',
            'shot.jpg',
            'Shot',
            'temp/uploads/'.$uploadSession->id.'/original',
            $this->category->id,
            ['fields' => ['caption' => 'x']],
            $user->id
        );
    }

    public function test_multiple_uploads_in_same_window_use_one_batch_row(): void
    {
        Queue::fake();

        $user = $this->createProstaffUser();
        $this->completeOneUpload($user);
        $this->completeOneUpload($user);

        $this->assertSame(1, ProstaffUploadBatch::query()->count());
        $batch = ProstaffUploadBatch::query()->first();
        $this->assertSame(2, $batch->upload_count);
        Queue::assertPushed(ProcessProstaffUploadBatchJob::class, 2);
    }

    public function test_batch_count_matches_uploads(): void
    {
        Queue::fake();

        $user = $this->createProstaffUser();
        $this->completeOneUpload($user);
        $this->completeOneUpload($user);
        $this->completeOneUpload($user);

        $this->assertSame(3, ProstaffUploadBatch::query()->first()->upload_count);
    }

    public function test_job_sends_notifications_and_sets_processed_at(): void
    {
        $user = $this->createProstaffUser();
        $this->completeOneUpload($user);
        $this->completeOneUpload($user);

        $batch = ProstaffUploadBatch::query()->first();
        $this->assertNull($batch->processed_at);

        Carbon::setTestNow(Carbon::parse('2026-04-06 10:15:00', 'UTC'));

        $job = new ProcessProstaffUploadBatchJob($batch->batch_key);
        $this->app->call([$job, 'handle']);

        $batch->refresh();
        $this->assertNotNull($batch->processed_at);
        $this->assertNotNull($batch->notifications_sent_at);

        $this->assertGreaterThanOrEqual(
            1,
            Notification::query()
                ->where('user_id', $this->approver->id)
                ->where('type', 'prostaff.upload.batch')
                ->count()
        );
    }

    public function test_max_batch_duration_forces_send_before_quiet_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 10:00:00', 'UTC'));

        $user = $this->createProstaffUser();
        $this->completeOneUpload($user);

        $batch = ProstaffUploadBatch::query()->first();
        $batch->update([
            'last_activity_at' => Carbon::parse('2026-04-06 10:34:00', 'UTC'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-06 10:35:00', 'UTC'));

        $job = new ProcessProstaffUploadBatchJob($batch->batch_key);
        $this->app->call([$job, 'handle']);

        $batch->refresh();
        $this->assertNotNull($batch->notifications_sent_at);
        $this->assertNotNull($batch->processed_at);
    }

    public function test_running_job_again_does_not_resend_after_processed(): void
    {
        $user = $this->createProstaffUser();
        $this->completeOneUpload($user);

        $batch = ProstaffUploadBatch::query()->first();

        Carbon::setTestNow(Carbon::parse('2026-04-06 10:15:00', 'UTC'));

        $job = new ProcessProstaffUploadBatchJob($batch->batch_key);
        $this->app->call([$job, 'handle']);

        $countAfterFirst = Notification::query()
            ->where('user_id', $this->approver->id)
            ->where('type', 'prostaff.upload.batch')
            ->count();

        $this->app->call([$job, 'handle']);

        $countAfterSecond = Notification::query()
            ->where('user_id', $this->approver->id)
            ->where('type', 'prostaff.upload.batch')
            ->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}
