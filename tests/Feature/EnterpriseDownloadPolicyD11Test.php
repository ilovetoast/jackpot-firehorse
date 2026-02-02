<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\DownloadStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Enums\ZipStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase D11 — Enterprise Download Policy (Tier 1: Enforcement)
 *
 * Tests:
 * - Enterprise tenant cannot download single asset (policy disables single-asset downloads)
 * - Public download without password is rejected at creation (Enterprise)
 * - Non-expiring download rejected for enterprise
 * - Expiration overridden to forced days (Enterprise)
 * - Non-enterprise tenants unaffected
 * - Delivery blocked when public download lacks password (Enterprise)
 */
class EnterpriseDownloadPolicyD11Test extends TestCase
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
            'type' => UploadType::DIRECT,
            'status' => UploadStatus::COMPLETED,
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

    public function test_enterprise_tenant_cannot_download_single_asset(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->postJson(route('assets.download.single', ['asset' => $this->asset->id]));

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Your organization requires downloads to be packaged.']);
        $this->assertNull(Download::query()->where('source', DownloadSource::SINGLE_ASSET->value)->latest()->first());
    }

    public function test_public_download_without_password_is_rejected_for_enterprise(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);
        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        $response = $this->postJson(route('downloads.store'), [
            'source' => 'grid',
            'access_mode' => 'public',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Your organization requires a password for public downloads.']);
    }

    public function test_non_expiring_download_rejected_for_enterprise(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);
        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        $response = $this->postJson(route('downloads.store'), [
            'source' => 'grid',
            'expires_at' => 'never',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Your organization requires an expiration date.']);
    }

    public function test_expiration_overridden_to_forced_days_for_enterprise(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);
        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);

        Queue::fake();
        $response = $this->postJson(route('downloads.store'), [
            'source' => 'grid',
            'expires_at' => now()->addDays(90)->toIso8601String(),
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $download = Download::find($response->json('download_id'));
        $this->assertNotNull($download);
        $expectedMin = now()->addDays(29)->startOfDay();
        $expectedMax = now()->addDays(31)->endOfDay();
        $this->assertTrue(
            $download->expires_at->between($expectedMin, $expectedMax),
            'Expiration should be overridden to ~30 days (force_expiration_days)'
        );
    }

    public function test_non_enterprise_tenants_unaffected(): void
    {
        $this->tenant->update(['manual_plan_override' => 'pro']);
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        // Single-asset download allowed for non-enterprise (returns redirect to signed URL)
        $singleResponse = $this->post(route('assets.download.single', ['asset' => $this->asset->id]));
        $singleResponse->assertRedirect();
        $this->assertNotNull(Download::query()->where('source', DownloadSource::SINGLE_ASSET->value)->latest()->first());

        // Create packaged download without password allowed for non-enterprise
        $this->postJson(route('download-bucket.add'), ['asset_id' => $this->asset->id]);
        Queue::fake();
        $storeResponse = $this->postJson(route('downloads.store'), ['source' => 'grid']);
        $storeResponse->assertOk();
        $this->assertNotNull(Download::find($storeResponse->json('download_id')));
    }

    public function test_delivery_blocked_when_public_download_lacks_password_for_enterprise(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);
        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'test-slug-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test/download.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
            'password_hash' => null,
        ]);
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        $response = $this->get(route('downloads.public.file', ['download' => $download->id]));

        $response->assertStatus(403);
        $response->assertSee('delivery requirements', false);
    }
}
