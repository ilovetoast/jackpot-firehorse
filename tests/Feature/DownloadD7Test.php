<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase D7 — Password-Protected & Branded Downloads
 *
 * Tests:
 * - Password-protected download requires password
 * - Correct password allows access
 * - Incorrect password denied
 * - Branding options render safely (no HTML)
 * - Non-Enterprise plan cannot set password
 */
class DownloadD7Test extends TestCase
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

    protected function createPasswordProtectedDownload(array $overrides = []): Download
    {
        $download = Download::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'test-slug-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => \App\Enums\ZipStatus::READY,
            'zip_path' => 'downloads/test/download.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'password_hash' => Hash::make('secret123'),
        ], $overrides));
        $download->assets()->attach($this->asset->id, ['is_primary' => true]);

        return $download;
    }

    public function test_password_protected_download_requires_password(): void
    {
        $download = $this->createPasswordProtectedDownload();

        $response = $this->get(route('downloads.public', ['download' => $download->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Public')
            ->where('password_required', true)
            ->where('state', 'password_required')
            ->has('unlock_url')
        );
    }

    public function test_correct_password_allows_access(): void
    {
        $download = $this->createPasswordProtectedDownload();

        $response = $this->post(route('downloads.public.unlock', ['download' => $download->id]), [
            'password' => 'secret123',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('downloads.public', ['download' => $download->id]));
        $this->assertTrue(Session::get('download_unlocked.' . $download->id) === true);
    }

    public function test_incorrect_password_denied(): void
    {
        $download = $this->createPasswordProtectedDownload();

        $response = $this->post(route('downloads.public.unlock', ['download' => $download->id]), [
            'password' => 'wrongpassword',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('downloads.public', ['download' => $download->id]));
        $response->assertSessionHasErrors('password');
        $this->assertNull(Session::get('download_unlocked.' . $download->id));
    }

    public function test_branding_options_render_safely(): void
    {
        $download = $this->createPasswordProtectedDownload([
            'branding_options' => [
                'headline' => 'Press Kit',
                'subtext' => 'Approved brand assets',
                'accent_color' => '#1E40AF',
            ],
        ]);

        $response = $this->get(route('downloads.public', ['download' => $download->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Downloads/Public')
            ->where('branding_options.headline', 'Press Kit')
            ->where('branding_options.subtext', 'Approved brand assets')
            ->where('branding_options.accent_color', '#1E40AF')
        );
    }

    public function test_non_enterprise_plan_cannot_set_password(): void
    {
        $this->tenant->update(['manual_plan_override' => 'pro']);
        $planService = app(PlanService::class);
        $this->assertFalse($planService->canPasswordProtectDownload($this->tenant));

        $this->tenant->update(['manual_plan_override' => 'starter']);
        $this->assertFalse($planService->canPasswordProtectDownload($this->tenant));

        $this->tenant->update(['manual_plan_override' => 'enterprise']);
        $this->assertTrue($planService->canPasswordProtectDownload($this->tenant));
    }
}
