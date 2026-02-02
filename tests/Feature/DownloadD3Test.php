<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\StorageBucketStatus;
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
 * Phase D3 — Download Creation UX
 *
 * Tests:
 * - Free plan uses defaults (no name, 30-day expiration, public access)
 * - Enterprise can set custom name and access scope
 * - Free plan cannot set non-expiring download
 */
class DownloadD3Test extends TestCase
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
            'published_at' => now(),
        ]);
    }

    public function test_download_creation_uses_defaults_for_free_plan(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        Queue::fake();
        $response = $this->postJson(route('downloads.store'), ['source' => 'grid']);
        $response->assertOk();

        $downloadId = $response->json('download_id');
        $download = Download::find($downloadId);
        $this->assertNotNull($download);
        $this->assertNull($download->title);
        $this->assertNotNull($download->expires_at);
        $this->assertSame(DownloadAccessMode::PUBLIC->value, $download->access_mode->value);
    }

    public function test_enterprise_user_can_set_custom_name_and_access_scope(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        Queue::fake();
        $response = $this->postJson(route('downloads.store'), [
            'source' => 'grid',
            'name' => 'My Custom Download',
            'access_mode' => 'company',
        ]);
        $response->assertOk();

        $downloadId = $response->json('download_id');
        $download = Download::find($downloadId);
        $this->assertNotNull($download);
        $this->assertSame('My Custom Download', $download->title);
        $this->assertSame(DownloadAccessMode::COMPANY->value, $download->access_mode->value);
    }

    public function test_free_plan_cannot_set_non_expiring_download(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        $response = $this->postJson(route('downloads.store'), [
            'source' => 'grid',
            'expires_at' => 'never',
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Upgrade to create non-expiring downloads.']);
    }
}
