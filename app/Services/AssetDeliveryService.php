<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Download;
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
            DeliveryContext::AUTHENTICATED => $cdnUrl,
            DeliveryContext::PUBLIC_COLLECTION => $this->signForPublicCollection($cdnUrl),
            DeliveryContext::PUBLIC_DOWNLOAD => $this->signForPublicDownload($cdnUrl, $asset, $options),
        };
    }

    /**
     * Sign URL for public collection context.
     */
    protected function signForPublicCollection(string $cdnUrl): string
    {
        $ttl = $this->signedUrlService->getPublicCollectionTtl();
        $expiresAt = time() + $ttl;

        return $this->signedUrlService->sign($cdnUrl, $expiresAt);
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

        return $this->signedUrlService->sign($cdnUrl, $expiresAt);
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
