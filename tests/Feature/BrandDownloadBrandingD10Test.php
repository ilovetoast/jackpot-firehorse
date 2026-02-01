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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase D10 — Brand-Level Download Page Branding.
 *
 * - Brand settings save & validation (logo_asset_id, color_role, no raw URL/hex)
 * - Invalid asset ownership rejected
 * - Public page resolves brand visuals (logo URL, accent from palette)
 * - Download copy overrides brand defaults
 * - Legacy branding fallback (read-only)
 */
class BrandDownloadBrandingD10Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;
    protected Category $logosCategory;
    protected Asset $logoAsset;

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

        $this->logosCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Logos',
            'slug' => 'logos',
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

        $this->logoAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/logo.png',
            'storage_root_path' => 'test/logo.png',
            'original_filename' => 'logo.png',
            'size_bytes' => 100,
            'metadata' => ['category_id' => $this->logosCategory->id],
            'published_at' => now(),
        ]);
    }

    public function test_brand_settings_save_and_validation(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->put(route('brands.update', $this->brand), [
            'name' => $this->brand->name,
            'slug' => $this->brand->slug,
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => $this->logoAsset->id,
                'color_role' => 'accent',
                'default_headline' => 'Press Kit',
                'default_subtext' => 'Approved assets',
                'background_asset_ids' => [],
            ],
        ]);

        $response->assertRedirect();
        $this->brand->refresh();
        $settings = $this->brand->download_landing_settings ?? [];
        $this->assertTrue($settings['enabled'] ?? false);
        $this->assertSame($this->logoAsset->id, $settings['logo_asset_id'] ?? null);
        $this->assertSame('accent', $settings['color_role'] ?? null);
        $this->assertSame('Press Kit', $settings['default_headline'] ?? null);
        $this->assertSame('Approved assets', $settings['default_subtext'] ?? null);
    }

    public function test_invalid_asset_ownership_rejected(): void
    {
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other',
            'slug' => 'other',
            'is_default' => false,
        ]);
        $otherCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => UploadType::DIRECT,
            'status' => UploadStatus::COMPLETED,
            'expected_size' => 100,
            'uploaded_size' => 100,
        ]);
        $otherAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $otherBrand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'path' => 'test/other.png',
            'storage_root_path' => 'test/other.png',
            'original_filename' => 'other.png',
            'size_bytes' => 100,
            'metadata' => ['category_id' => $otherCategory->id],
            'published_at' => now(),
        ]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);
        $this->actingAs($this->user);

        $response = $this->put(route('brands.update', $this->brand), [
            'name' => $this->brand->name,
            'slug' => $this->brand->slug,
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => $otherAsset->id,
                'color_role' => 'primary',
                'default_headline' => '',
                'default_subtext' => '',
                'background_asset_ids' => [],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->brand->refresh();
        $settings = $this->brand->download_landing_settings ?? [];
        $this->assertNotSame($otherAsset->id, $settings['logo_asset_id'] ?? null);
    }

    public function test_public_page_resolves_brand_visuals(): void
    {
        $this->brand->update([
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => $this->logoAsset->id,
                'color_role' => 'primary',
                'default_headline' => 'Brand Headline',
                'default_subtext' => 'Brand subtext',
                'background_asset_ids' => [],
            ],
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd10-public-' . uniqid(),
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
        $response->assertInertia(fn ($page) => $page->component('Downloads/Public')->has('branding_options'));
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertNotEmpty($branding['logo_url'] ?? null, 'Public page should resolve logo URL from brand logo_asset_id');
        $this->assertSame('#1E40AF', $branding['accent_color'] ?? null, 'Public page should resolve accent from brand primary_color via color_role');
        $this->assertSame('Brand Headline', $branding['headline'] ?? null);
        $this->assertSame('Brand subtext', $branding['subtext'] ?? null);
    }

    public function test_download_copy_overrides_brand_defaults(): void
    {
        $this->brand->update([
            'download_landing_settings' => [
                'enabled' => true,
                'logo_asset_id' => null,
                'color_role' => 'primary',
                'default_headline' => 'Brand Default Headline',
                'default_subtext' => 'Brand default subtext',
                'background_asset_ids' => [],
            ],
        ]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd10-copy-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
            'landing_copy' => ['headline' => 'Download Override Headline', 'subtext' => 'Download override subtext'],
        ]);

        $response = $this->get(route('downloads.public', $download));

        $response->assertOk();
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertSame('Download Override Headline', $branding['headline'] ?? null);
        $this->assertSame('Download override subtext', $branding['subtext'] ?? null);
    }

    public function test_legacy_branding_fallback(): void
    {
        $this->brand->update(['download_landing_settings' => null]);

        $download = Download::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'created_by_user_id' => $this->user->id,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'd10-legacy-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
            'uses_landing_page' => true,
            'branding_options' => [
                'logo_url' => 'https://example.com/legacy-logo.png',
                'accent_color' => '#1E3A8A',
                'headline' => 'Legacy Headline',
                'subtext' => 'Legacy subtext',
            ],
        ]);

        $response = $this->get(route('downloads.public', $download));

        $response->assertOk();
        $branding = $response->inertiaPage()['props']['branding_options'] ?? [];
        $this->assertSame('https://example.com/legacy-logo.png', $branding['logo_url'] ?? null, 'Legacy logo_url should be used when brand has no download_landing_settings');
        $this->assertSame('#1E3A8A', $branding['accent_color'] ?? null, 'Legacy accent_color should be used when brand has no color_role');
        $this->assertSame('Legacy Headline', $branding['headline'] ?? null);
        $this->assertSame('Legacy subtext', $branding['subtext'] ?? null);
    }
}
