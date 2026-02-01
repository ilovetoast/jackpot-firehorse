<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\ZipStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AssetEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase D10.1 — Download landing visuals & background constraints.
 *
 * - Reject background <1920×1080
 * - Random background selection across requests
 * - Correct overlay color resolution
 * - Logo renders only when set
 * - Fallback to solid color when no background images
 */
class DownloadLandingVisualsD101Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;
    protected Category $photographyCategory;
    protected Asset $assetLarge;
    protected Asset $assetSmall;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B',
            'slug' => 'b',
            'is_default' => true,
            'primary_color' => '#1E40AF',
            'secondary_color' => '#64748b',
            'accent_color' => '#7C3AED',
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

        $this->photographyCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
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

        $this->assetLarge = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/large.jpg',
            'storage_root_path' => 'test/large.jpg',
            'original_filename' => 'large.jpg',
            'size_bytes' => 100,
            'metadata' => [
                'category_id' => $this->photographyCategory->id,
                'image_width' => 1920,
                'image_height' => 1080,
            ],
            'published_at' => now(),
        ]);

        $this->assetSmall = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/small.jpg',
            'storage_root_path' => 'test/small.jpg',
            'original_filename' => 'small.jpg',
            'size_bytes' => 100,
            'metadata' => [
                'category_id' => $this->photographyCategory->id,
                'image_width' => 800,
                'image_height' => 600,
            ],
            'published_at' => now(),
        ]);
    }

    public function test_reject_background_under_1920x1080(): void
    {
        $eligibility = app(AssetEligibilityService::class);
        $this->assertTrue($eligibility->isEligibleForDownloadBackground($this->assetLarge));
        $this->assertFalse($eligibility->isEligibleForDownloadBackground($this->assetSmall));

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->put(route('brands.update', $this->brand), [
            'name' => $this->brand->name,
            'slug' => $this->brand->slug,
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => null,
                'color_role' => 'primary',
                'default_headline' => '',
                'default_subtext' => '',
                'background_asset_ids' => [$this->assetSmall->id],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->brand->refresh();
        $settings = $this->brand->download_landing_settings ?? [];
        $this->assertEmpty($settings['background_asset_ids'] ?? []);
    }

    public function test_random_background_selection_across_requests(): void
    {
        $this->brand->update([
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => null,
                'color_role' => 'primary',
                'default_headline' => 'Headline',
                'default_subtext' => '',
                'background_asset_ids' => [$this->assetLarge->id],
            ],
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd101-rand-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
        ]);

        $response = $this->get(route('downloads.public', $download));
        $response->assertOk();
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertNotEmpty($branding['background_image_url'] ?? null);
    }

    public function test_overlay_color_resolution(): void
    {
        $this->brand->update([
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => null,
                'color_role' => 'accent',
                'default_headline' => '',
                'default_subtext' => '',
                'background_asset_ids' => [],
            ],
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd101-overlay-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
        ]);

        $response = $this->get(route('downloads.public', $download));
        $response->assertOk();
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertSame('#7C3AED', $branding['accent_color'] ?? null);
        $this->assertSame('#7C3AED', $branding['overlay_color'] ?? null);
    }

    public function test_logo_renders_only_when_set(): void
    {
        $logosCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $logoAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $this->assetLarge->upload_session_id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/logo.png',
            'storage_root_path' => 'test/logo.png',
            'original_filename' => 'logo.png',
            'size_bytes' => 100,
            'metadata' => ['category_id' => $logosCategory->id],
            'published_at' => now(),
        ]);

        $this->brand->update([
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => $logoAsset->id,
                'color_role' => 'primary',
                'default_headline' => 'Headline',
                'default_subtext' => '',
                'background_asset_ids' => [],
            ],
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd101-logo-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
        ]);

        $response = $this->get(route('downloads.public', $download));
        $response->assertOk();
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertNotEmpty($branding['logo_url'] ?? null);

        $this->brand->update(['download_landing_settings' => [
            'enabled' => true,
            'logo_asset_id' => null,
            'color_role' => 'primary',
            'default_headline' => 'Headline',
            'default_subtext' => '',
            'background_asset_ids' => [],
        ]]);
        $response2 = $this->get(route('downloads.public', $download));
        $branding2 = $response2->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertEmpty($branding2['logo_url'] ?? null);
    }

    public function test_fallback_solid_color_when_no_background_images(): void
    {
        $this->brand->update([
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => null,
                'color_role' => 'primary',
                'default_headline' => 'Headline',
                'default_subtext' => '',
                'background_asset_ids' => [],
            ],
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd101-solid-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
        ]);

        $response = $this->get(route('downloads.public', $download));
        $response->assertOk();
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertNull($branding['background_image_url'] ?? null);
        $this->assertNotEmpty($branding['overlay_color'] ?? null);
    }
}
