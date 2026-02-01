<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
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
 * Phase D4 — Download Status, Processing & Trust Signals
 *
 * Tests:
 * - Processing download shows processing state in index
 * - Expired download shows expired state
 * - Revoked download shows revoked state
 * - Failed download allows regenerate when plan allows (Enterprise)
 */
class DownloadD4Test extends TestCase
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

    public function test_processing_download_shows_processing_state(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'proc-slug',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::BUILDING,
            'zip_path' => null,
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->get(route('downloads.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Index')
            ->has('downloads')
            ->where('downloads.0.state', 'processing')
        );
    }

    public function test_expired_download_shows_expired_state(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'exp-slug',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/exp/download.zip',
            'expires_at' => now()->subDay(),
            'hard_delete_at' => now()->addDays(6),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->get(route('downloads.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Index')
            ->has('downloads')
            ->where('downloads.0.state', 'expired')
        );
    }

    public function test_revoked_download_shows_revoked_state(): void
    {
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'rev-slug',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/rev/download.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'revoked_at' => now(),
            'revoked_by_user_id' => $this->user->id,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->get(route('downloads.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Index')
            ->has('downloads')
            ->where('downloads.0.state', 'revoked')
        );
    }

    public function test_failed_download_allows_regenerate_when_plan_allows(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'fail-slug',
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::FAILED,
            'zip_path' => null,
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->get(route('downloads.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Index')
            ->has('downloads')
            ->where('downloads.0.state', 'failed')
            ->where('downloads.0.can_regenerate', true)
        );
    }
}
