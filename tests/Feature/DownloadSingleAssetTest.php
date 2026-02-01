<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadSource;
use App\Enums\StorageBucketStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * UX-R2 — Single-asset download (POST /app/assets/{asset}/download).
 *
 * Tests:
 * - Eligible asset can be downloaded; Download record created with source SINGLE_ASSET
 * - Ineligible asset returns 403
 * - Manager sees user attribution on downloads index
 */
class DownloadSingleAssetTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

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

    public function test_eligible_asset_can_be_downloaded_and_record_created(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $asset = $this->createAsset(['storage_root_path' => 'test/file.jpg', 'published_at' => now()]);

        $response = $this->post(route('assets.download.single', ['asset' => $asset->id]));

        $response->assertRedirect();
        $download = Download::query()->where('source', DownloadSource::SINGLE_ASSET->value)->latest()->first();
        $this->assertNotNull($download);
        $this->assertSame($this->tenant->id, $download->tenant_id);
        $this->assertSame($this->user->id, $download->created_by_user_id);
        $this->assertSame('test/file.jpg', $download->direct_asset_path);
        $this->assertNull($download->zip_path);
        $this->assertSame(100, $download->zip_size_bytes);
        $this->assertTrue($download->assets()->where('assets.id', $asset->id)->exists());
    }

    public function test_ineligible_asset_returns_403(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $asset = $this->createAsset(['published_at' => null]);

        $response = $this->postJson(route('assets.download.single', ['asset' => $asset->id]));

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'This asset is not available for download.']);
        $this->assertNull(Download::query()->where('source', DownloadSource::SINGLE_ASSET->value)->latest()->first());
    }

    public function test_archived_asset_returns_403(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $asset = $this->createAsset(['published_at' => now(), 'archived_at' => now()]);

        $response = $this->postJson(route('assets.download.single', ['asset' => $asset->id]));

        $response->assertStatus(403);
        $this->assertNull(Download::query()->where('source', DownloadSource::SINGLE_ASSET->value)->latest()->first());
    }

    public function test_manager_sees_user_attribution_on_downloads_index(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $asset = $this->createAsset();
        $this->post(route('assets.download.single', ['asset' => $asset->id]));

        $response = $this->get(route('downloads.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Index')
            ->has('downloads')
            ->where('downloads.0.source', 'single_asset')
            ->where('downloads.0.created_by.id', $this->user->id)
            ->where('downloads.0.created_by.name', $this->user->name)
        );
    }
}
