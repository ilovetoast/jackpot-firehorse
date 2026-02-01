<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Jobs\BuildDownloadZipJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Phase D9.2 — ZIP build timing & observability.
 *
 * ZIP build timing is observability only. It must never affect permissions,
 * access, or UX state directly.
 *
 * Tests:
 * - ZIP build records started + completed timestamps; duration derived
 * - Failed ZIP sets failed_at; completed_at remains null
 * - Duration is derived (no stored column); helper returns correct value
 * - Idempotency: re-running job does not overwrite started_at
 */
class DownloadZipTimingD92Test extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-d92']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B',
            'slug' => 'b-d92',
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
            'name' => 'test-bucket-d92',
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
            'metadata' => [],
            'published_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Bind a mock S3 client that allows ZIP build to succeed (getObject returns content, putObject accepts).
     */
    protected function bindFakeS3(): void
    {
        $mock = Mockery::mock(S3Client::class);
        $mock->shouldReceive('doesObjectExist')
            ->andReturn(true);
        $mock->shouldReceive('getObject')
            ->andReturn(['Body' => Utils::streamFor('fake-asset-content')]);
        $mock->shouldReceive('putObject')
            ->andReturn([]);

        $this->app->instance(S3Client::class, $mock);
    }

    public function test_zip_build_records_started_and_completed_timestamps(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'timing-d92-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        $this->bindFakeS3();

        $job = new BuildDownloadZipJob($download->id);
        $job->handle();

        $download->refresh();
        $this->assertNotNull($download->zip_build_started_at);
        $this->assertNotNull($download->zip_build_completed_at);
        $this->assertNull($download->zip_build_failed_at);
        $this->assertNotNull($download->zipBuildDurationMs());
        $this->assertGreaterThanOrEqual(0, $download->zipBuildDurationMs());
    }

    public function test_failed_zip_sets_failed_timestamp(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'fail-d92-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        // Mock S3 so build fails (e.g. getObject throws or returns nothing usable)
        $mock = Mockery::mock(S3Client::class);
        $mock->shouldReceive('doesObjectExist')->andReturn(true);
        $mock->shouldReceive('getObject')
            ->andThrow(new \RuntimeException('S3 error for test'));

        $this->app->instance(S3Client::class, $mock);

        $job = new BuildDownloadZipJob($download->id);
        $thrown = null;
        try {
            $job->handle();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Job must throw on S3 failure so that zip_build_failed_at is set in catch.');

        $download->refresh();
        $this->assertNotNull($download->zip_build_started_at);
        $this->assertNull($download->zip_build_completed_at);
        $this->assertNotNull($download->zip_build_failed_at);
    }

    public function test_duration_is_derived_not_stored(): void
    {
        $this->assertFalse(
            Schema::hasColumn('downloads', 'zip_build_duration_ms'),
            'zip_build_duration_ms must not exist; duration is derived only.'
        );

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'derived-d92-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'zip_size_bytes' => 100,
            'zip_build_started_at' => now()->subSeconds(5),
            'zip_build_completed_at' => now(),
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);

        $duration = $download->zipBuildDurationMs();
        $this->assertNotNull($duration);
        $this->assertGreaterThanOrEqual(4900, $duration); // ~5 seconds in ms
        $this->assertLessThanOrEqual(6000, $duration);
    }

    public function test_idempotency_re_run_does_not_overwrite_started_at(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'idem-d92-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        $this->bindFakeS3();

        $job = new BuildDownloadZipJob($download->id);
        $job->handle();

        $download->refresh();
        $firstStartedAt = $download->zip_build_started_at;
        $this->assertNotNull($firstStartedAt);

        // Invalidate ZIP so job will run again (living/snapshot with INVALIDATED)
        $download->zip_status = ZipStatus::INVALIDATED;
        $download->zip_build_completed_at = null;
        $download->zip_path = null;
        $download->zip_size_bytes = null;
        $download->save();

        $job2 = new BuildDownloadZipJob($download->id);
        $job2->handle();

        $download->refresh();
        $this->assertNotNull($download->zip_build_started_at);
        $this->assertTrue(
            $download->zip_build_started_at->eq($firstStartedAt),
            'Re-run must not overwrite zip_build_started_at (idempotency).'
        );
    }
}
