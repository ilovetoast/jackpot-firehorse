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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 *
 * Tests:
 * - Bucket add: only visible assets can be added
 * - Create download: validation, record creation, job dispatch
 * - Public link: 410 when expired
 * - Cleanup / metrics (smoke)
 */
class DownloadD1Test extends TestCase
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
            'email' => 'u@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'U',
            'last_name' => 'U',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin']);

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
    }

    public function test_bucket_add_adds_visible_asset(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);
        $response->assertOk();
        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('items.0', $this->asset->id);
    }

    public function test_bucket_add_rejects_asset_user_cannot_view(): void
    {
        $otherTenant = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $otherBrand = Brand::create(['tenant_id' => $otherTenant->id, 'name' => 'B2', 'slug' => 'b2']);
        $otherUpload = UploadSession::create([
            'tenant_id' => $otherTenant->id,
            'brand_id' => $otherBrand->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => \App\Enums\UploadType::DIRECT,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);
        $otherAsset = Asset::create([
            'tenant_id' => $otherTenant->id,
            'brand_id' => $otherBrand->id,
            'upload_session_id' => $otherUpload->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'other/file.jpg',
            'storage_root_path' => 'other/file.jpg',
            'original_filename' => 'other.jpg',
            'size_bytes' => 100,
            'metadata' => [],
        ]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->postJson(route('download-bucket.add'), ['asset_id' => $otherAsset->id]);
        $response->assertStatus(403);
    }

    public function test_bucket_add_batch_adds_visible_assets(): void
    {
        $uploadSession2 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => \App\Enums\UploadType::DIRECT,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);
        $asset2 = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $uploadSession2->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/file2.jpg',
            'storage_root_path' => 'test/file2.jpg',
            'original_filename' => 'file2.jpg',
            'size_bytes' => 100,
            'metadata' => [],
        ]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->postJson(route('download-bucket.add_batch'), [
            'asset_ids' => [$this->asset->id, $asset2->id],
        ]);
        $response->assertOk();
        $response->assertJsonPath('count', 2);
        $ids = $response->json('items');
        $this->assertCount(2, $ids);
        $this->assertContains($this->asset->id, $ids);
        $this->assertContains($asset2->id, $ids);
    }

    public function test_create_download_validates_bucket_not_empty(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->postJson(route('downloads.store'), ['source' => 'grid']);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Add at least one asset to the download bucket.']);
    }

    public function test_create_download_creates_record_and_dispatches_job(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        Queue::fake();
        $response = $this->postJson(route('downloads.store'), ['source' => 'grid']);
        $response->assertOk();
        $response->assertJsonStructure(['download_id', 'public_url', 'expires_at', 'asset_count']);

        $downloadId = $response->json('download_id');
        $download = Download::find($downloadId);
        $this->assertNotNull($download);
        $this->assertSame($this->tenant->id, $download->tenant_id);
        $this->assertSame($this->user->id, $download->created_by_user_id);
        $this->assertSame(DownloadStatus::READY->value, $download->status->value);
        $this->assertSame(ZipStatus::NONE->value, $download->zip_status->value);
        $this->assertSame(DownloadAccessMode::PUBLIC->value, $download->access_mode->value);
        $this->assertCount(1, $download->assets);
        $this->assertNotNull($download->expires_at);
        $this->assertNotNull($download->hard_delete_at);

        Queue::assertPushed(BuildDownloadZipJob::class, fn ($job) => $job->downloadId === $downloadId);
    }

    public function test_public_download_returns_410_when_expired(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'abc123',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/' . \Illuminate\Support\Str::uuid() . '/download.zip',
            'expires_at' => now()->subDay(),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);

        $response = $this->get(route('downloads.public', ['download' => $download->id]));
        $response->assertStatus(410);
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Public')
            ->where('state', 'expired')
            ->where('message', 'This download has expired.')
        );
    }

    public function test_downloads_index_returns_user_downloads(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->get(route('downloads.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Index')
            ->has('downloads')
            ->has('bucket_count')
        );
    }
}
