<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Download;
use App\Support\AssetVariant;
use App\Support\CdnUrl;
use App\Support\DeliveryContext;
use RuntimeException;

/**
 * Unified asset delivery service.
 *
 * All asset URLs must flow through this service.
 * - AUTHENTICATED: Plain CDN URL (signed cookies apply)
 * - PUBLIC_COLLECTION: CloudFront signed URL with public_collection_ttl
 * - PUBLIC_DOWNLOAD: CloudFront signed URL with DownloadExpirationPolicy
 *
 * Never exposes raw S3 URLs. Never bypasses CDN.
 */
class AssetDeliveryService
{
    public function __construct(
        protected AssetVariantPathResolver $pathResolver,
        protected CloudFrontSignedUrlService $signedUrlService
    ) {
    }

    /**
     * Get delivery URL for an asset variant in the given context.
     *
     * @param Asset $asset
     * @param string $variant AssetVariant enum value (e.g. AssetVariant::THUMB_LARGE->value)
     * @param string $context DeliveryContext enum value (e.g. DeliveryContext::PUBLIC_COLLECTION->value)
     * @param array $options Optional (e.g. ['page' => 1] for PDF_PAGE)
     * @return string CDN URL (plain or signed depending on context)
     *
     * @throws RuntimeException If path cannot be resolved
     */
    public function url(Asset $asset, string $variant, string $context, array $options = []): string
    {
        $path = $this->pathResolver->resolve($asset, $variant, $options);
        if ($path === '') {
            // VIDEO_PREVIEW and PDF_PAGE: return placeholder when file does not exist (do not throw)
            $variantEnum = AssetVariant::tryFrom($variant);
            if ($variantEnum && in_array($variantEnum, [AssetVariant::VIDEO_PREVIEW, AssetVariant::PDF_PAGE], true)) {
                return config('assets.delivery.placeholder_url', '');
            }

            return '';
        }

        $cdnUrl = CdnUrl::url($path);

        // Local/testing: Skip CloudFront signing; CdnUrl returns presigned S3 URL in local
        if (app()->environment(['local', 'testing'])) {
            \Log::info('SIGNED URL GENERATED', [
                'url' => $cdnUrl,
            ]);
            return $cdnUrl;
        }

        $contextEnum = DeliveryContext::tryFrom($context) ?? DeliveryContext::AUTHENTICATED;

        return match ($contextEnum) {
            DeliveryContext::AUTHENTICATED => $this->urlForAuthenticatedContext($cdnUrl, $variant, $options),
            DeliveryContext::PUBLIC_COLLECTION => $this->signForPublicCollection($cdnUrl, $asset, $variant),
            DeliveryContext::PUBLIC_DOWNLOAD => $this->signForPublicDownload($cdnUrl, $asset, array_merge($options, ['variant' => $variant])),
        };
    }

    /**
     * Get CDN URL for a rendered PDF page.
     *
     * In AUTHENTICATED context we always use a signed URL so PDF pages work even though
     * CloudFront signed cookies are scoped to /tenants/{uuid}/* and PDF pages live under /assets/.
     */
    public function getPdfPageUrl(
        Asset $asset,
        int $page,
        string $context = 'authenticated'
    ): string {
        $options = ['page' => max(1, $page)];
        if ((DeliveryContext::tryFrom($context) ?? DeliveryContext::AUTHENTICATED) === DeliveryContext::AUTHENTICATED) {
            $options['signed'] = true;
        }

        return $this->url(
            $asset,
            AssetVariant::PDF_PAGE->value,
            $context,
            $options
        );
    }

    /**
     * AUTHENTICATED context URL behavior.
     *
     * For PDF pages we optionally issue short-lived signed URLs to reduce
     * frequent regeneration while keeping stale exposure low.
     */
    protected function urlForAuthenticatedContext(string $cdnUrl, string $variant, array $options): string
    {
        $variantEnum = AssetVariant::tryFrom($variant);
        $requiresSignedPdfUrl = $variantEnum === AssetVariant::PDF_PAGE
            && (($options['signed'] ?? false) === true);

        if (! $requiresSignedPdfUrl) {
            return $cdnUrl;
        }

        $isPublic = (($options['pdf_page_access'] ?? 'admin') === 'public');
        $ttl = $this->signedUrlService->getPdfPageTtl($isPublic);
        $expiresAt = time() + $ttl;

        return $this->signedUrlService->sign($cdnUrl, $expiresAt);
    }

    /**
     * Sign URL for public collection context.
     */
    protected function signForPublicCollection(string $cdnUrl, Asset $asset, string $variant): string
    {
        $variantEnum = AssetVariant::tryFrom($variant);
        $ttl = $variantEnum === AssetVariant::PDF_PAGE
            ? $this->signedUrlService->getPdfPagePublicTtl()
            : $this->signedUrlService->getPublicCollectionTtl();
        $expiresAt = time() + $ttl;
        $signed = $this->signedUrlService->sign($cdnUrl, $expiresAt);

        \Illuminate\Support\Facades\Log::channel('single')->info('[CDN] Signed URL generated (public_collection)', [
            'asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'variant' => $variant,
            'expires_at' => $expiresAt,
            'expires_at_iso' => date('c', $expiresAt),
        ]);

        return $signed;
    }

    /**
     * Sign URL for public download context.
     */
    protected function signForPublicDownload(string $cdnUrl, Asset $asset, array $options): string
    {
        $download = $options['download'] ?? null;
        $tenant = $asset->tenant ?? $options['tenant'] ?? null;

        $ttl = $this->signedUrlService->getPublicDownloadTtl(
            $download instanceof Download ? $download : null,
            $tenant
        );
        $expiresAt = time() + $ttl;
        $signed = $this->signedUrlService->sign($cdnUrl, $expiresAt);

        \Illuminate\Support\Facades\Log::channel('single')->info('[CDN] Signed URL generated (public_download)', [
            'asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'variant' => $options['variant'] ?? 'unknown',
            'expires_at' => $expiresAt,
            'expires_at_iso' => date('c', $expiresAt),
        ]);

        return $signed;
    }

    /**
     * Get signed CDN URL for a raw storage path (e.g. ZIP file).
     * Used for public download file delivery when path is not asset variant.
     *
     * @param string $path Storage path (S3 key)
     * @param string $context DeliveryContext (PUBLIC_DOWNLOAD)
     * @param array $options ['download' => Download, 'tenant' => Tenant] for TTL
     */
    public function urlForPath(string $path, string $context, array $options = []): string
    {
        if ($path === '') {
            return '';
        }

        $cdnUrl = CdnUrl::url($path);

        if (app()->environment(['local', 'testing'])) {
            return $cdnUrl;
        }

        $contextEnum = DeliveryContext::tryFrom($context);
        if ($contextEnum !== DeliveryContext::PUBLIC_DOWNLOAD) {
            return $cdnUrl;
        }

        $download = $options['download'] ?? null;
        $tenant = $options['tenant'] ?? null;
        $ttl = $this->signedUrlService->getPublicDownloadTtl(
            $download instanceof Download ? $download : null,
            $tenant
        );
        $expiresAt = time() + $ttl;

        return $this->signedUrlService->sign($cdnUrl, $expiresAt);
    }
}
