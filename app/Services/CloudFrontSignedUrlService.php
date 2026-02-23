<?php

namespace App\Services;

use App\Models\Download;
use App\Models\Tenant;
use Aws\CloudFront\UrlSigner;
use RuntimeException;

/**
 * CloudFront signed URL service.
 *
 * Generates CloudFront signed URLs using AWS SDK canned policy signing.
 * No dependency on cookie service; uses Aws\CloudFront\UrlSigner.
 *
 * Do NOT use for authenticated tenant access — signed cookies apply there.
 */
class CloudFrontSignedUrlService
{
    public function __construct()
    {
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

        $keyPairId = config('cloudfront.key_pair_id');
        $privateKeyPath = $this->resolvePrivateKeyPath();

        if (empty($keyPairId)) {
            throw new RuntimeException('CloudFront key_pair_id must be configured for signed URLs.');
        }

        if (! file_exists($privateKeyPath)) {
            throw new RuntimeException("CloudFront private key not found at: {$privateKeyPath}");
        }

        $signer = new UrlSigner(
            $keyPairId,
            file_get_contents($privateKeyPath)
        );

        return $signer->getSignedUrl($cdnUrl, $expiresAt);
    }

    /**
     * Resolve private key path (supports relative to base_path).
     */
    protected function resolvePrivateKeyPath(): string
    {
        $path = config('cloudfront.private_key_path');

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
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
