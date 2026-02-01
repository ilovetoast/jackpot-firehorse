<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Jobs\CleanupExpiredDownloadsJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\DownloadMetricsService;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase D5 — Download Metrics & Expiration Cleanup
 *
 * Tests:
 * - Expired download artifact is deleted
 * - Cleanup job is idempotent
 * - Missing artifact does not throw
 * - Metrics are recorded after deletion
 * - Cleanup verification flags failures correctly
 *
 * Uses fake storage (mocked S3 client) via app binding.
 */
class DownloadD5Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B',
            'slug' => 'b',
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
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => \App\Enums\UploadType::DIRECT,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);

        $this->asset = Asset::create([
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
            'metadata' => ['file_size' => 100],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create an expired download with artifact (zip_path set, zip_deleted_at null).
     */
    protected function createExpiredDownloadWithArtifact(array $overrides = []): Download
    {
        $defaults = [
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'exp-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test/artifact.zip',
            'zip_size_bytes' => 1024,
            'expires_at' => now()->subDay(),
            'hard_delete_at' => now()->addDays(6),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ];
        $download = Download::create(array_merge($defaults, $overrides));
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        return $download;
    }

    public function test_expired_download_artifact_is_deleted(): void
    {
        $download = $this->createExpiredDownloadWithArtifact();

        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')
            ->with('test-bucket', 'downloads/test/artifact.zip')
            ->andReturn(true, false);
        $mockS3->shouldReceive('deleteObject')
            ->once()
            ->with(Mockery::on(function ($arg) {
                return isset($arg['Bucket'], $arg['Key'])
                    && $arg['Bucket'] === 'test-bucket'
                    && $arg['Key'] === 'downloads/test/artifact.zip';
            }));
        $this->app->instance('download.cleanup.s3', $mockS3);

        $job = new CleanupExpiredDownloadsJob();
        $job->handle();

        $download->refresh();
        $this->assertNotNull($download->zip_deleted_at);
        $this->assertNotNull($download->cleanup_verified_at);
        $this->assertNull($download->cleanup_failed_at);
    }

    public function test_cleanup_job_is_idempotent(): void
    {
        $download = $this->createExpiredDownloadWithArtifact();

        $callCount = 0;
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return $callCount <= 1;
            });
        $mockS3->shouldReceive('deleteObject')->once();
        $this->app->instance('download.cleanup.s3', $mockS3);

        $job = new CleanupExpiredDownloadsJob();
        $job->handle();

        $download->refresh();
        $this->assertNotNull($download->zip_deleted_at);

        $job->handle();
        $download->refresh();
        $this->assertNotNull($download->zip_deleted_at);
    }

    public function test_missing_artifact_does_not_throw(): void
    {
        $download = $this->createExpiredDownloadWithArtifact();

        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')->andReturn(false);
        $mockS3->shouldNotReceive('deleteObject');
        $this->app->instance('download.cleanup.s3', $mockS3);

        $job = new CleanupExpiredDownloadsJob();
        $job->handle();

        $download->refresh();
        $this->assertNotNull($download->zip_deleted_at);
        $this->assertNotNull($download->cleanup_verified_at);
    }

    public function test_metrics_are_recorded_after_deletion(): void
    {
        $download = $this->createExpiredDownloadWithArtifact();

        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')->andReturn(true, false);
        $mockS3->shouldReceive('deleteObject')->once();
        $this->app->instance('download.cleanup.s3', $mockS3);

        $job = new CleanupExpiredDownloadsJob();
        $job->handle();

        $download->refresh();
        $this->assertNotNull($download->zip_deleted_at);
        $this->assertNotNull($download->cleanup_verified_at);
        $this->assertNotNull($download->storageDurationSeconds());
        $this->assertSame(1024, $download->totalBytes());
    }

    public function test_cleanup_verification_flags_failures_correctly(): void
    {
        $download = $this->createExpiredDownloadWithArtifact();

        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')->andReturn(true);
        $mockS3->shouldReceive('deleteObject')->once();
        $this->app->instance('download.cleanup.s3', $mockS3);

        $job = new CleanupExpiredDownloadsJob();
        $job->handle();

        $download->refresh();
        $this->assertNotNull($download->cleanup_failed_at);
    }

    public function test_metrics_service_aggregations(): void
    {
        $this->createExpiredDownloadWithArtifact(['zip_size_bytes' => 500]);
        $this->createExpiredDownloadWithArtifact(['zip_size_bytes' => 1500, 'slug' => 'exp-two']);

        $service = new DownloadMetricsService();
        $this->assertSame(2000, $service->totalBytesStoredPerTenant($this->tenant->id));

        $avg = $service->averageZipSize($this->tenant->id);
        $this->assertGreaterThanOrEqual(0, $avg);
    }
}
