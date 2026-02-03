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
 * Phase D2 — Download Management & Access Control
 *
 * Tests:
 * - Plan gates enforced backend-side
 * - Revoked downloads are inaccessible
 * - Managers can manage, non-managers cannot
 * - Collection-only users cannot manage
 */
class DownloadD2Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected User $contributor;
    protected StorageBucket $bucket;
    protected Asset $asset;
    protected Download $download;

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

        $this->contributor = User::create([
            'email' => 'contrib@example.com',
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
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'test-slug',
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

    public function test_revoked_download_returns_410(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $this->download->update([
            'revoked_at' => now(),
            'revoked_by_user_id' => $this->user->id,
        ]);

        $response = $this->get(route('downloads.public', ['download' => $this->download->id]));
        $response->assertStatus(410);
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Public')
            ->where('state', 'revoked')
            ->where('message', 'This download has been revoked.')
        );
    }

    public function test_contributor_cannot_revoke_download(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->contributor);

        $response = $this->postJson(route('downloads.revoke', ['download' => $this->download->id]));
        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You cannot manage downloads.']);
    }

    public function test_manager_can_revoke_download_when_plan_allows(): void
    {
        // Pro/Enterprise plans allow revoke; free/starter do not
        // Default tenant has no subscription -> free plan
        $this->tenant->update(['manual_plan_override' => 'pro']);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->postJson(route('downloads.revoke', ['download' => $this->download->id]));
        $response->assertOk();

        $this->download->refresh();
        $this->assertNotNull($this->download->revoked_at);
    }

    public function test_downloads_index_returns_can_manage_and_features(): void
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
            ->has('can_manage')
            ->has('download_features')
        );
    }

    /**
     * Company-level download: logged-in creator (user 1) can access via public link when opening
     * the Download button URL. Public route has no ResolveTenant, so tenant is resolved from
     * the download when the user is authenticated and belongs to the download's tenant.
     */
    public function test_company_download_accessible_by_logged_in_creator_via_public_link(): void
    {
        $companyDownload = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'company-link-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::NONE,
            'expires_at' => now()->addDays(30),
            'access_mode' => DownloadAccessMode::COMPANY,
            'allow_reshare' => true,
        ]);
        $companyDownload->assets()->attach($this->asset->id, ['is_primary' => true]);

        // Simulate clicking Download button: user is logged in but public route has no tenant in context
        $this->actingAs($this->user);
        Session::forget(['tenant_id', 'brand_id']);

        $response = $this->get(route('downloads.public', ['download' => $companyDownload->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Public')
            ->where('state', 'processing')
            ->where('message', "We're preparing your download. Please try again in a moment.")
        );
        // Must not be access_denied
        $response->assertInertia(fn ($page) => $page->where('state', '!=', 'access_denied'));
    }
}
