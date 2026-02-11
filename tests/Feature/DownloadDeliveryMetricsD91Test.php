<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\MetricType;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Models\Asset;
use App\Models\AssetMetric;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D9.1 — Asset & Download Delivery Metrics
 *
 * Ensures asset-level download metrics are recorded only when a download is actually
 * delivered (single-asset or ZIP), not on landing page views or failed/expired/revoked access.
 */
class DownloadDeliveryMetricsD91Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-d91']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B',
            'slug' => 'b-d91',
        ]);
        $this->user = User::create([
            'email' => 'admin-d91@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'brand_manager']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function createAsset(array $overrides = []): Asset
    {
        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => \App\Enums\UploadType::DIRECT,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);

        return Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'storage_root_path' => 'test/file.jpg',
            'original_filename' => 'file.jpg',
            'size_bytes' => 100,
            'metadata' => [],
            'published_at' => now(),
        ], $overrides));
    }

    protected function downloadCountForAsset(string $assetId): int
    {
        return AssetMetric::where('asset_id', $assetId)
            ->where('metric_type', MetricType::DOWNLOAD->value)
            ->count();
    }

    public function test_single_asset_download_increments_asset_count(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $asset = $this->createAsset();
        $this->assertSame(0, $this->downloadCountForAsset($asset->id));

        $response = $this->post(route('assets.download.single', ['asset' => $asset->id]));
        $response->assertRedirect();

        // Metrics are recorded when the file is actually delivered (GET /file), not on the redirect to the ready page
        $download = Download::where('tenant_id', $this->tenant->id)->whereNotNull('direct_asset_path')->latest()->first();
        $this->assertNotNull($download);
        $mockBucket = \Mockery::mock(\App\Services\TenantBucketService::class);
        $mockBucket->shouldReceive('resolveActiveBucketOrFail')->with(\Mockery::type(\App\Models\Tenant::class))->andReturn($this->bucket);
        $mockBucket->shouldReceive('getPresignedGetUrl')->andReturn('https://example.com/signed-asset');
        $this->app->instance(\App\Services\TenantBucketService::class, $mockBucket);

        $this->get(route('downloads.public.file', ['download' => $download->id]))->assertRedirect('https://example.com/signed-asset');
        $this->assertSame(1, $this->downloadCountForAsset($asset->id));
    }

    public function test_zip_download_increments_all_asset_counts(): void
    {
        $asset1 = $this->createAsset(['storage_root_path' => 'test/a.jpg', 'original_filename' => 'a.jpg']);
        $asset2 = $this->createAsset(['storage_root_path' => 'test/b.jpg', 'original_filename' => 'b.jpg']);
        $asset3 = $this->createAsset(['storage_root_path' => 'test/c.jpg', 'original_filename' => 'c.jpg']);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::GRID->value,
            'slug' => 'zip-d91-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test/download.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach([$asset1->id => ['is_primary' => true], $asset2->id => ['is_primary' => false], $asset3->id => ['is_primary' => false]]);

        // Metrics are recorded when the file is delivered (GET /file), not when viewing the landing page
        $mockBucket = \Mockery::mock(\App\Services\TenantBucketService::class);
        $mockBucket->shouldReceive('resolveActiveBucketOrFail')->with(\Mockery::type(\App\Models\Tenant::class))->andReturn($this->bucket);
        $mockBucket->shouldReceive('getPresignedGetUrl')->andReturn('https://example.com/signed.zip');
        $this->app->instance(\App\Services\TenantBucketService::class, $mockBucket);

        $response = $this->get(route('downloads.public.file', ['download' => $download->id]));

        $response->assertRedirect('https://example.com/signed.zip');
        $this->assertSame(1, $this->downloadCountForAsset($asset1->id));
        $this->assertSame(1, $this->downloadCountForAsset($asset2->id));
        $this->assertSame(1, $this->downloadCountForAsset($asset3->id));
    }

    public function test_zip_redownload_increments_again(): void
    {
        $asset1 = $this->createAsset();
        $asset2 = $this->createAsset(['storage_root_path' => 'test/b.jpg', 'original_filename' => 'b.jpg']);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::GRID->value,
            'slug' => 'zip-redl-d91-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test/redl.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach([$asset1->id => ['is_primary' => true], $asset2->id => ['is_primary' => false]]);

        $mockBucket = \Mockery::mock(\App\Services\TenantBucketService::class);
        $mockBucket->shouldReceive('resolveActiveBucketOrFail')->with(\Mockery::type(\App\Models\Tenant::class))->andReturn($this->bucket);
        $mockBucket->shouldReceive('getPresignedGetUrl')->andReturn('https://example.com/signed.zip');

        $this->app->instance(\App\Services\TenantBucketService::class, $mockBucket);

        $this->get(route('downloads.public.file', ['download' => $download->id]));
        $this->get(route('downloads.public.file', ['download' => $download->id]));

        $this->assertSame(2, $this->downloadCountForAsset($asset1->id));
        $this->assertSame(2, $this->downloadCountForAsset($asset2->id));
    }

    public function test_landing_page_view_does_not_increment(): void
    {
        $asset = $this->createAsset();
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::GRID->value,
            'slug' => 'landing-d91-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::BUILDING,
            'zip_path' => null,
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($asset->id, ['is_primary' => true]);

        $response = $this->get(route('downloads.public', ['download' => $download->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Downloads/Public'));
        $this->assertSame(0, $this->downloadCountForAsset($asset->id));
    }

    public function test_expired_download_does_not_increment(): void
    {
        $asset = $this->createAsset();
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::GRID->value,
            'slug' => 'expired-d91-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/expired.zip',
            'expires_at' => now()->subDay(),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($asset->id, ['is_primary' => true]);

        $response = $this->getJson(route('downloads.public', ['download' => $download->id]));

        $response->assertStatus(410);
        $response->assertJsonFragment(['message' => 'This download has expired']);
        $this->assertSame(0, $this->downloadCountForAsset($asset->id));
    }

    public function test_revoked_download_does_not_increment(): void
    {
        $asset = $this->createAsset();
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => DownloadSource::GRID->value,
            'slug' => 'revoked-d91-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/revoked.zip',
            'expires_at' => now()->addDays(30),
            'revoked_at' => now(),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($asset->id, ['is_primary' => true]);

        $response = $this->getJson(route('downloads.public', ['download' => $download->id]));

        $response->assertStatus(410);
        $response->assertJsonFragment(['message' => 'This download has been revoked']);
        $this->assertSame(0, $this->downloadCountForAsset($asset->id));
    }
}
