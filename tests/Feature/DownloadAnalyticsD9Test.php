<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\EventType;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\DownloadAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * D9 — Download Analytics (Internal)
 *
 * Tests:
 * - Admin can fetch analytics
 * - Contributor cannot fetch analytics (403)
 * - Public download analytics return unique_users = null
 * - Single-asset download included in asset breakdown
 * - Analytics endpoint does not mutate downloads
 */
class DownloadAnalyticsD9Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $admin;
    protected User $contributor;
    protected StorageBucket $bucket;
    protected Asset $asset;
    protected Download $download;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-d9']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B',
            'slug' => 'b-d9',
        ]);
        $this->admin = User::create([
            'email' => 'admin-d9@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->admin->brands()->attach($this->brand->id, ['role' => 'brand_manager']);

        $this->contributor = User::create([
            'email' => 'contrib-d9@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Contrib',
            'last_name' => 'User',
        ]);
        $this->contributor->tenants()->attach($this->tenant->id, ['role' => 'contributor']);
        $this->contributor->brands()->attach($this->brand->id, ['role' => 'contributor']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
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

        $this->download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->admin->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::GRID->value,
            'slug' => 'test-slug-d9',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test/download.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $this->download->assets()->attach($this->asset->id, ['is_primary' => true]);
    }

    public function test_admin_can_fetch_analytics(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->admin);

        ActivityEvent::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'actor_type' => 'guest',
            'actor_id' => null,
            'event_type' => EventType::DOWNLOAD_ACCESS_GRANTED,
            'subject_type' => Download::class,
            'subject_id' => (string) $this->download->id,
            'metadata' => ['ip_hash' => 'abc', 'context' => 'zip'],
            'created_at' => now(),
        ]);

        $response = $this->getJson(route('downloads.analytics', ['download' => $this->download->id]));
        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => [
                'total_downloads',
                'unique_users',
                'first_downloaded_at',
                'last_downloaded_at',
                'source_breakdown' => ['zip', 'single_asset'],
            ],
            'recent_activity',
            'asset_breakdown',
        ]);
        $this->assertSame(1, $response->json('summary.total_downloads'));
    }

    public function test_contributor_cannot_fetch_analytics(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->contributor);

        $response = $this->getJson(route('downloads.analytics', ['download' => $this->download->id]));
        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You cannot view analytics for this download.']);
    }

    public function test_public_download_analytics_return_unique_users_null(): void
    {
        $this->assertSame(DownloadAccessMode::PUBLIC, $this->download->access_mode);

        $service = app(DownloadAnalyticsService::class);
        $summary = $service->summaryForDownload($this->download);

        $this->assertNull($summary['unique_users']);
    }

    public function test_single_asset_download_included_in_asset_breakdown(): void
    {
        $singleDownload = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->admin->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::SINGLE_ASSET->value,
            'slug' => 'single-asset-d9',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
            'direct_asset_path' => 'path/to/asset',
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $singleDownload->assets()->attach($this->asset->id, ['is_primary' => true]);

        $service = app(DownloadAnalyticsService::class);
        $breakdown = $service->assetBreakdownForDownload($singleDownload);

        $this->assertCount(1, $breakdown);
        $this->assertSame($this->asset->id, $breakdown[0]['asset_id']);
        $this->assertSame('file.jpg', $breakdown[0]['name']);
    }

    public function test_analytics_endpoint_does_not_mutate_downloads(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->admin);

        $slugBefore = $this->download->slug;
        $versionBefore = $this->download->version;

        $this->getJson(route('downloads.analytics', ['download' => $this->download->id]));
        $this->getJson(route('downloads.analytics', ['download' => $this->download->id]));

        $this->download->refresh();
        $this->assertSame($slugBefore, $this->download->slug);
        $this->assertSame($versionBefore, $this->download->version);
    }
}
