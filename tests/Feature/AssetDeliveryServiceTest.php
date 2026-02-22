<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AssetDeliveryService;
use App\Services\AssetVariantPathResolver;
use App\Services\CloudFrontSignedUrlService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 — Asset Delivery Service tests.
 *
 * - Public collection generates signed URL
 * - Authenticated context returns non-signed CDN URL
 * - PDF_PAGE requires page option
 * - No S3 disk URLs returned in staging/production (when CloudFront configured)
 */
class AssetDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected StorageBucket $bucket;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Test',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/' . $this->tenant->uuid . '/assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.jpg',
            'size_bytes' => 1024,
        ]);
    }

    public function test_authenticated_context_returns_plain_cdn_url(): void
    {
        config(['cloudfront.domain' => 'cdn.example.com']);
        config(['cloudfront.key_pair_id' => 'K123']);
        config(['cloudfront.private_key_path' => base_path('storage/keys/cloudfront-private.pem')]);

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $this->asset,
            AssetVariant::ORIGINAL->value,
            DeliveryContext::AUTHENTICATED->value
        );

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('cdn.example.com', $url);
        $this->assertStringNotContainsString('Expires=', $url);
        $this->assertStringNotContainsString('Signature=', $url);
        $this->assertStringNotContainsString('Key-Pair-Id=', $url);
    }

    public function test_public_collection_generates_signed_url_when_cloudfront_configured(): void
    {
        $domain = 'cdn.example.com';
        config(['cloudfront.domain' => $domain]);
        config(['cloudfront.key_pair_id' => 'K123']);
        $keyPath = base_path('storage/keys/cloudfront-private.pem');
        if (!file_exists($keyPath)) {
            $this->markTestSkipped('CloudFront private key not found; run with --dry-run or add key for full test');
        }
        config(['cloudfront.private_key_path' => $keyPath]);

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $this->asset,
            AssetVariant::THUMB_LARGE->value,
            DeliveryContext::PUBLIC_COLLECTION->value
        );

        $this->assertNotEmpty($url);
        $this->assertStringContainsString($domain, $url);
        $this->assertStringContainsString('Expires=', $url);
        $this->assertStringContainsString('Signature=', $url);
        $this->assertStringContainsString('Key-Pair-Id=', $url);
    }

    public function test_pdf_page_requires_page_option(): void
    {
        AssetPdfPage::create([
            'tenant_id' => $this->asset->tenant_id,
            'asset_id' => $this->asset->id,
            'asset_version_id' => null,
            'version_number' => 1,
            'page_number' => 3,
            'storage_path' => 'tenants/' . $this->tenant->uuid . '/assets/' . $this->asset->id . '/v1/pdf_pages/page-3.webp',
            'mime_type' => 'image/webp',
            'status' => 'completed',
            'rendered_at' => now(),
        ]);

        $resolver = app(AssetVariantPathResolver::class);
        $pathWithPage = $resolver->resolve($this->asset, AssetVariant::PDF_PAGE->value, ['page' => 3]);
        $pathDefault = $resolver->resolve($this->asset, AssetVariant::PDF_PAGE->value, []);

        $this->assertStringContainsString('/pdf_pages/page-3.webp', $pathWithPage);
        $this->assertSame('', $pathDefault);
    }

    public function test_asset_variant_requires_options(): void
    {
        $this->assertTrue(AssetVariant::PDF_PAGE->requiresOptions());
        $this->assertFalse(AssetVariant::ORIGINAL->requiresOptions());
        $this->assertFalse(AssetVariant::THUMB_SMALL->requiresOptions());
    }

    public function test_get_pdf_page_url_helper_uses_pdf_variant(): void
    {
        AssetPdfPage::create([
            'tenant_id' => $this->asset->tenant_id,
            'asset_id' => $this->asset->id,
            'asset_version_id' => null,
            'version_number' => 1,
            'page_number' => 2,
            'storage_path' => 'tenants/' . $this->tenant->uuid . '/assets/' . $this->asset->id . '/v1/pdf_pages/page-2.webp',
            'mime_type' => 'image/webp',
            'status' => 'completed',
            'rendered_at' => now(),
        ]);

        $service = app(AssetDeliveryService::class);
        $url = $service->getPdfPageUrl($this->asset, 2, DeliveryContext::AUTHENTICATED->value);

        $this->assertNotEmpty($url);
    }

    public function test_no_s3_url_returned_when_cloudfront_configured(): void
    {
        config(['cloudfront.domain' => 'cdn.example.com']);
        config(['cloudfront.key_pair_id' => 'K123']);
        config(['cloudfront.private_key_path' => base_path('storage/keys/cloudfront-private.pem')]);

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $this->asset,
            AssetVariant::ORIGINAL->value,
            DeliveryContext::AUTHENTICATED->value
        );

        $this->assertNotEmpty($url);
        $this->assertStringNotContainsString('.s3.', $url);
        $this->assertStringNotContainsString('s3.amazonaws.com', $url);
    }

    public function test_public_download_returns_signed_url_with_ttl(): void
    {
        $domain = 'cdn.example.com';
        config(['cloudfront.domain' => $domain]);
        config(['cloudfront.key_pair_id' => 'K123']);
        $keyPath = base_path('storage/keys/cloudfront-private.pem');
        if (!file_exists($keyPath)) {
            $this->markTestSkipped('CloudFront private key not found');
        }
        config(['cloudfront.private_key_path' => $keyPath]);

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $this->asset,
            AssetVariant::ORIGINAL->value,
            DeliveryContext::PUBLIC_DOWNLOAD->value,
            []
        );

        $this->assertNotEmpty($url);
        $this->assertStringContainsString($domain, $url);
        $this->assertStringContainsString('Expires=', $url);
        $this->assertStringContainsString('Signature=', $url);
    }

    public function test_video_preview_returns_placeholder_when_missing(): void
    {
        config(['cloudfront.domain' => 'cdn.example.com']);
        config(['cloudfront.key_pair_id' => 'K123']);
        config(['cloudfront.private_key_path' => base_path('storage/keys/cloudfront-private.pem')]);

        // PDF_PAGE with empty basePath - path resolver returns '' when storage_root_path is empty
        // Service returns placeholder for stub variants (VIDEO_PREVIEW, PDF_PAGE) when path is empty
        $upload2 = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 0,
            'uploaded_size' => 0,
        ]);
        $resolver = app(AssetVariantPathResolver::class);
        $assetEmpty = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => $upload2->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Empty',
            'original_filename' => 'empty.pdf',
            'mime_type' => 'application/pdf',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => '',
            'size_bytes' => 0,
        ]);
        $path = $resolver->resolve($assetEmpty, AssetVariant::PDF_PAGE->value, ['page' => 1]);
        $this->assertSame('', $path, 'Path resolver should return empty for PDF_PAGE when basePath empty');

        $service = app(AssetDeliveryService::class);
        $url = $service->url(
            $assetEmpty,
            AssetVariant::PDF_PAGE->value,
            DeliveryContext::AUTHENTICATED->value,
            ['page' => 1]
        );

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('data:image/png;base64,', $url);
    }

    public function test_asset_delivery_url_proxies_to_service(): void
    {
        config(['cloudfront.domain' => 'cdn.example.com']);
        config(['cloudfront.key_pair_id' => 'K123']);
        config(['cloudfront.private_key_path' => base_path('storage/keys/cloudfront-private.pem')]);

        $url = $this->asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED);

        $this->assertNotEmpty($url);
        $this->assertStringContainsString('cdn.example.com', $url);
        $this->assertStringNotContainsString('.s3.', $url);
    }
}
