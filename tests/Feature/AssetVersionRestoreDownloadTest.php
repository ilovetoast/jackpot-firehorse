<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadSource;
use App\Enums\StorageBucketStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AssetVersionRestoreService;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Asset version restore + download integrity.
 *
 * CRITICAL: After restoring a version, direct downloads and bucket/ZIP downloads
 * must serve the RESTORED file (correct path, filename, type), not the previous version.
 *
 * Tests:
 * - Restore syncs asset compatibility fields (storage_root_path, original_filename, mime_type, size_bytes, width, height)
 * - Single-asset download uses correct path and filename after restore
 * - Bucket download (ZIP) uses correct asset path after restore
 * - Restore v1 then v2: each restore updates asset correctly
 *
 * Environment note: Uses test DB (DB_DATABASE=testing). Staging uses tenant buckets;
 * local/testing may use shared bucket. Test validates path/filename logic regardless.
 */
class AssetVersionRestoreDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mockS3ForRestore();

        $this->tenant = Tenant::create(['name' => 'RestoreTest', 'slug' => 'restore-test', 'manual_plan_override' => 'pro']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Brand', 'slug' => 'brand']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'restore-test@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'brand_manager']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'restore-test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
    }

    protected function createAssetWithVersions(): Asset
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

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'title' => 'Test Asset',
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1500,
            'width' => 800,
            'height' => 600,
            'storage_root_path' => "assets/placeholder/v1/original.jpg",
            'metadata' => [],
            'published_at' => now(),
        ]);

        $v1Path = "assets/{$asset->id}/v1/original.jpg";
        $asset->update(['storage_root_path' => $v1Path]);

        AssetVersion::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $v1Path,
            'file_size' => 1500,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'checksum' => 'checksum-v1',
            'pipeline_status' => 'complete',
            'is_current' => false,
        ]);

        $v2Path = "assets/{$asset->id}/v2/original.png";
        $asset->update([
            'storage_root_path' => $v2Path,
            'original_filename' => 'photo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 3200,
            'width' => 1200,
            'height' => 900,
        ]);

        AssetVersion::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 2,
            'file_path' => $v2Path,
            'file_size' => 3200,
            'mime_type' => 'image/png',
            'width' => 1200,
            'height' => 900,
            'checksum' => 'checksum-v2',
            'pipeline_status' => 'complete',
            'is_current' => true,
        ]);

        return $asset->fresh();
    }

    protected function mockS3ForRestore(): void
    {
        $s3Client = Mockery::mock(S3Client::class);
        $s3Client->shouldReceive('copyObject')->andReturn(new Result());
        $this->app->instance(S3Client::class, $s3Client);
    }

    public function test_restore_syncs_asset_compatibility_fields(): void
    {
        $asset = $this->createAssetWithVersions();
        $v1 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();
        $v2 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 2)->first();

        $this->assertSame('photo.png', $asset->original_filename);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame(3200, $asset->size_bytes);
        $this->assertSame(1200, $asset->width);
        $this->assertSame(900, $asset->height);
        $this->assertStringContainsString('v2/original.png', $asset->storage_root_path);

        $service = app(AssetVersionRestoreService::class);
        $newVersion = $service->restore($asset, $v1, true, false, (string) $this->user->id);

        $asset->refresh();

        $expectedPath = "assets/{$asset->id}/v3/original.jpg";
        $this->assertSame($expectedPath, $asset->storage_root_path, 'storage_root_path must point to restored v3 path');
        $this->assertSame('photo.jpg', $asset->original_filename, 'original_filename must match restored file type');
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame(1500, $asset->size_bytes);
        $this->assertSame(800, $asset->width);
        $this->assertSame(600, $asset->height);

        $this->assertSame(3, $newVersion->version_number);
        $this->assertTrue($newVersion->is_current);
    }

    public function test_single_download_uses_restored_path_and_filename(): void
    {
        $asset = $this->createAssetWithVersions();
        $v1 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();

        $this->mockS3ForRestore();
        app(AssetVersionRestoreService::class)->restore($asset, $v1, true, false, (string) $this->user->id);

        $asset->refresh();
        $expectedPath = "assets/{$asset->id}/v3/original.jpg";

        \Illuminate\Support\Facades\Session::put('tenant_id', $this->tenant->id);
        \Illuminate\Support\Facades\Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->post(route('assets.download.single', ['asset' => $asset->id]));
        $response->assertRedirect();

        $download = Download::where('source', DownloadSource::SINGLE_ASSET->value)->latest()->first();
        $this->assertNotNull($download);
        $this->assertSame($expectedPath, $download->direct_asset_path, 'direct_asset_path must point to restored v3 file');
        $this->assertSame('photo.jpg', $download->assets()->first()?->original_filename, 'Download must use restored filename');
    }

    public function test_bucket_download_uses_restored_asset_path(): void
    {
        $asset = $this->createAssetWithVersions();
        $v1 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();

        app(AssetVersionRestoreService::class)->restore($asset, $v1, true, false, (string) $this->user->id);

        $asset->refresh();
        $expectedPath = "assets/{$asset->id}/v3/original.jpg";

        \Illuminate\Support\Facades\Session::put('tenant_id', $this->tenant->id);
        \Illuminate\Support\Facades\Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $bucketService = app(\App\Services\DownloadBucketService::class);
        $bucketService->add($asset->id);

        $response = $this->post(route('downloads.store'), [
            'source' => 'grid',
            'access_mode' => 'company',
        ]);
        $response->assertRedirect();

        $download = Download::whereNull('direct_asset_path')->latest()->first();
        $this->assertNotNull($download);
        $attachedAsset = $download->assets()->first();
        $this->assertNotNull($attachedAsset);
        $this->assertSame($expectedPath, $attachedAsset->storage_root_path, 'ZIP build will use this path - must be restored v3');
        $this->assertSame('photo.jpg', $attachedAsset->original_filename);
    }

    public function test_restore_then_restore_back_updates_correctly(): void
    {
        $asset = $this->createAssetWithVersions();
        $v1 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 1)->first();
        $v2 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 2)->first();

        $service = app(AssetVersionRestoreService::class);

        $service->restore($asset, $v1, true, false, (string) $this->user->id);
        $asset->refresh();
        $this->assertSame('photo.jpg', $asset->original_filename);
        $this->assertStringContainsString('v3/original.jpg', $asset->storage_root_path);

        $v3 = AssetVersion::where('asset_id', $asset->id)->where('version_number', 3)->first();
        $service->restore($asset, $v2, true, false, (string) $this->user->id);
        $asset->refresh();

        $expectedPath = "assets/{$asset->id}/v4/original.png";
        $this->assertSame($expectedPath, $asset->storage_root_path);
        $this->assertSame('photo.png', $asset->original_filename);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame(3200, $asset->size_bytes);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
