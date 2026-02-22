<?php

namespace App\Services;

use App\Models\Download;
use App\Models\Tenant;
use RuntimeException;

/**
 * CloudFront signed URL service.
 *
 * Generates CloudFront signed URLs for public contexts (no signed cookies).
 * Uses same key pair configuration as CloudFrontSignedCookieService.
 *
 * Do NOT use for authenticated tenant access — signed cookies apply there.
 */
class CloudFrontSignedUrlService
{
    public function __construct(
        protected CloudFrontSignedCookieService $cookieService
    ) {
    }

    /**
     * Generate a CloudFront signed URL for the given CDN URL.
     *
     * @param string $cdnUrl Full CDN URL (e.g. https://cdn.example.com/path/to/file.jpg)
     * @param int $expiresAt Unix timestamp when URL expires
     * @return string Signed URL with Expires, Signature, Key-Pair-Id query params
     *
     * @throws RuntimeException If signing fails or CloudFront not configured
     */
    public function sign(string $cdnUrl, int $expiresAt): string
    {
        $domain = config('cloudfront.domain');
        if (empty($domain)) {
            throw new RuntimeException('CloudFront domain must be configured for signed URLs.');
        }

        $signed = $this->cookieService->signForSignedUrl($cdnUrl, $expiresAt);

        $separator = str_contains($cdnUrl, '?') ? '&' : '?';

        // Signature is already URL-safe base64; do not double-encode
        return $cdnUrl . $separator . 'Expires=' . $signed['expires']
            . '&Signature=' . $signed['signature']
            . '&Key-Pair-Id=' . rawurlencode($signed['key_pair_id']);
    }

    /**
     * Get TTL in seconds for public collection context.
     */
    public function getPublicCollectionTtl(): int
    {
        return config('cdn.public_collection_ttl', 900);
    }

    /**
     * Get TTL in seconds for public download context.
     * Uses DownloadExpirationPolicy when a Download is provided.
     */
    public function getPublicDownloadTtl(?Download $download = null, ?Tenant $tenant = null): int
    {
        if ($download && $tenant) {
            $policy = app(DownloadExpirationPolicy::class);
            $expiresAt = $policy->calculateExpiresAt($tenant, $download->download_type);
            if ($expiresAt) {
                return (int) max(60, now()->diffInSeconds($expiresAt, false));
            }
        }

        return config('cdn.public_download_ttl', 900);
    }

    /**
     * Get TTL in seconds for admin/internal PDF page signed URLs.
     */
    public function getPdfPageAdminTtl(): int
    {
        return config('cdn.pdf_page_admin_ttl', 300);
    }

    /**
     * Get TTL in seconds for public PDF page signed URLs.
     */
    public function getPdfPagePublicTtl(): int
    {
        return config('cdn.pdf_page_public_ttl', 1800);
    }

    /**
     * Get TTL in seconds for PDF page signed URLs by audience.
     */
    public function getPdfPageTtl(bool $public): int
    {
        return $public ? $this->getPdfPagePublicTtl() : $this->getPdfPageAdminTtl();
    }
}
