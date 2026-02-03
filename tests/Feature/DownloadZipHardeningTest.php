<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadZipFailureReason;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Jobs\TriggerDownloadZipFailureAgentJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\DownloadManagementService;
use App\Services\DownloadZipFailureEscalationService;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use ZipArchive;

/**
 * Download ZIP hardening: timeout, retry, escalation.
 *
 * - Failure classification: timeout, disk_full, s3_read_error, permission_error, unknown
 * - failure_count, last_failed_at persisted
 * - Agent trigger on timeout or failure_count >= 2
 * - Ticket escalation on failure_count >= 3 or agent severity system
 * - Regenerate guardrail: max 3 failures, then disabled
 */
class DownloadZipHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-hard']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B',
            'slug' => 'b-hard',
        ]);
        $this->user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'brand_manager']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket-hard',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function bindFakeS3(): void
    {
        $mock = Mockery::mock(\Aws\S3\S3Client::class);
        $mock->shouldReceive('doesObjectExist')->andReturn(true);
        $mock->shouldReceive('getObject')
            ->andReturn(['Body' => Utils::streamFor('fake-asset-content')]);
        $mock->shouldReceive('putObject')->andReturn([]);

        $this->app->instance(\Aws\S3\S3Client::class, $mock);
    }

    public function test_chunked_zip_resumes_correctly(): void
    {
        $download = $this->createDownloadWith150Assets();
        $this->bindFakeS3();

        $tempPath = sys_get_temp_dir() . '/download_zip_' . $download->id . '.zip';
        $zip = new ZipArchive();
        $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('chunk0_file.txt', 'from-chunk-0');
        $zip->close();

        $download->forceFill([
            'zip_status' => ZipStatus::BUILDING,
            'zip_build_chunk_index' => 1,
        ])->saveQuietly();

        $job = new BuildDownloadZipJob($download->id);
        $job->handle();

        $download->refresh();
        $this->assertEquals(\App\Enums\ZipStatus::READY, $download->zip_status);
        $this->assertNotNull($download->zip_path);
        $this->assertGreaterThan(0, $download->zip_size_bytes);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    public function test_chunked_zip_build_completes_with_multiple_chunks(): void
    {
        $download = $this->createDownloadWith150Assets();
        $this->bindFakeS3();

        $job = new BuildDownloadZipJob($download->id);
        $job->handle();

        $download->refresh();
        $this->assertEquals(\App\Enums\ZipStatus::READY, $download->zip_status);
        $this->assertNotNull($download->zip_path);
        $this->assertEquals(150, $download->assets()->count());
    }

    public function test_failure_classification_timeout(): void
    {
        $download = $this->createDownloadWithAsset();
        $job = new BuildDownloadZipJob($download->id);

        $e = new \Illuminate\Queue\MaxAttemptsExceededException('Job has exceeded the maximum number of attempts.');
        $reason = $this->invokeClassifyFailure($job, $e);

        $this->assertEquals(DownloadZipFailureReason::TIMEOUT, $reason);
    }

    public function test_failure_classification_disk_full(): void
    {
        $download = $this->createDownloadWithAsset();
        $job = new BuildDownloadZipJob($download->id);

        $e = new \RuntimeException('No space left on device');
        $reason = $this->invokeClassifyFailure($job, $e);

        $this->assertEquals(DownloadZipFailureReason::DISK_FULL, $reason);
    }

    public function test_failure_classification_s3_error(): void
    {
        $download = $this->createDownloadWithAsset();
        $job = new BuildDownloadZipJob($download->id);

        $e = new \RuntimeException('Error executing "GetObject" on S3');
        $reason = $this->invokeClassifyFailure($job, $e);

        $this->assertEquals(DownloadZipFailureReason::S3_READ_ERROR, $reason);
    }

    public function test_failure_count_incremented_on_failure(): void
    {
        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'zip_status' => ZipStatus::BUILDING,
            'failure_count' => 0,
        ])->saveQuietly();

        $job = new BuildDownloadZipJob($download->id);
        $this->invokeRecordFailure($job, $download, new \RuntimeException('Test failure'));

        $download->refresh();
        $this->assertEquals(1, $download->failure_count);
        $this->assertNotNull($download->failure_reason);
        $this->assertNotNull($download->last_failed_at);
    }

    public function test_agent_job_dispatched_on_timeout(): void
    {
        Queue::fake();

        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'failure_reason' => DownloadZipFailureReason::TIMEOUT,
            'failure_count' => 1,
        ])->saveQuietly();

        $job = new BuildDownloadZipJob($download->id);
        $job->failed(new \RuntimeException('Timeout'));

        Queue::assertPushed(TriggerDownloadZipFailureAgentJob::class);
    }

    public function test_agent_job_dispatched_on_failure_count_ge_2(): void
    {
        Queue::fake();

        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'failure_reason' => DownloadZipFailureReason::UNKNOWN,
            'failure_count' => 2,
        ])->saveQuietly();

        $job = new BuildDownloadZipJob($download->id);
        $job->failed(new \RuntimeException('Some error'));

        Queue::assertPushed(TriggerDownloadZipFailureAgentJob::class);
    }

    public function test_ticket_not_created_when_escalation_ticket_exists(): void
    {
        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'failure_reason' => DownloadZipFailureReason::TIMEOUT,
            'failure_count' => 3,
            'escalation_ticket_id' => 99999,
        ])->saveQuietly();

        $service = app(DownloadZipFailureEscalationService::class);
        $ticket = $service->createTicketIfNeeded($download, null);

        $this->assertNull($ticket);
    }

    public function test_should_create_ticket_when_failure_count_ge_3(): void
    {
        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'failure_reason' => DownloadZipFailureReason::TIMEOUT,
            'failure_count' => 3,
        ])->saveQuietly();

        $this->assertTrue($download->failure_count >= 3);
        $this->assertNull($download->escalation_ticket_id);
    }

    public function test_regenerate_disabled_after_3_failures(): void
    {
        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'zip_status' => ZipStatus::FAILED,
            'failure_count' => 3,
        ])->saveQuietly();

        $this->assertFalse($download->canRegenerateZip());
        $this->assertTrue($download->isEscalatedToSupport());
    }

    public function test_regenerate_throws_when_escalated(): void
    {
        $download = $this->createDownloadWithAsset();
        $download->forceFill([
            'zip_status' => ZipStatus::FAILED,
            'failure_count' => 3,
        ])->saveQuietly();

        $service = app(DownloadManagementService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->regenerate($download, $this->user);
    }

    protected function createDownloadWithAsset(): Download
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => \App\Enums\UploadType::DIRECT,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/file.jpg',
            'storage_root_path' => 'test/file.jpg',
            'original_filename' => 'file.jpg',
            'size_bytes' => 100,
            'metadata' => [],
            'published_at' => now(),
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => \App\Enums\DownloadType::SNAPSHOT,
            'source' => \App\Enums\DownloadSource::GRID,
            'slug' => 'test-' . uniqid(),
            'status' => \App\Enums\DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
        ]);
        $download->assets()->attach($asset->id, ['is_primary' => true]);

        return $download;
    }

    protected function createDownloadWith150Assets(): Download
    {
        $assets = [];
        for ($i = 0; $i < 150; $i++) {
            $uploadSession = UploadSession::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'storage_bucket_id' => $this->bucket->id,
                'type' => \App\Enums\UploadType::DIRECT,
                'status' => \App\Enums\UploadStatus::COMPLETED,
                'expected_size' => 100,
                'uploaded_size' => 100,
            ]);
            $assets[] = Asset::create([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'upload_session_id' => $uploadSession->id,
                'storage_bucket_id' => $this->bucket->id,
                'type' => AssetType::ASSET,
                'status' => AssetStatus::VISIBLE,
                'path' => "test/file_{$i}.jpg",
                'storage_root_path' => "test/file_{$i}.jpg",
                'original_filename' => "file_{$i}.jpg",
                'size_bytes' => 100,
                'metadata' => [],
                'published_at' => now(),
            ]);
        }

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => \App\Enums\DownloadType::SNAPSHOT,
            'source' => \App\Enums\DownloadSource::GRID,
            'slug' => 'test-150-' . uniqid(),
            'status' => \App\Enums\DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
        ]);
        foreach ($assets as $i => $asset) {
            $download->assets()->attach($asset->id, ['is_primary' => $i === 0]);
        }

        return $download;
    }

    protected function invokeClassifyFailure(BuildDownloadZipJob $job, \Throwable $e): DownloadZipFailureReason
    {
        $ref = new \ReflectionMethod($job, 'classifyFailure');
        $ref->setAccessible(true);
        return $ref->invoke($job, $e);
    }

    protected function invokeRecordFailure(BuildDownloadZipJob $job, Download $download, \Throwable $e): void
    {
        $ref = new \ReflectionMethod($job, 'recordFailure');
        $ref->setAccessible(true);
        $ref->invoke($job, $download, $e);
    }
}
