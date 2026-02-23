<?php

namespace App\Services;

use App\Models\Download;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
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
     * Expires must be a UNIX timestamp in SECONDS (not milliseconds). CloudFront expects
     * AWS:EpochTime in seconds. Verify Expires in the generated URL is ~now + TTL seconds.
     *
     * @param string $cdnUrl Full CDN URL (e.g. https://cdn.example.com/path/to/file.jpg)
     * @param int $expiresAt Unix timestamp in SECONDS when URL expires
     * @return string Signed URL with Expires=..., Signature=..., Key-Pair-Id=... (Expires in seconds)
     *
     * @throws RuntimeException If signing fails or CloudFront not configured
     */
    public function sign(string $cdnUrl, int $expiresAt): string
    {
        $domain = config('cloudfront.domain');
        if (empty($domain)) {
            throw new RuntimeException('CloudFront domain must be configured for signed URLs.');
        }

        $nowSeconds = now()->timestamp;
        Log::debug('[CloudFrontSignedUrl] Signing URL', [
            'now_timestamp_seconds' => $nowSeconds,
            'expires_timestamp_seconds' => $expiresAt,
            'ttl_seconds' => $expiresAt - $nowSeconds,
        ]);

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
}
