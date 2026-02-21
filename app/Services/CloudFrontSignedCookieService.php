<?php

namespace App\Services;

use App\Models\Tenant;
use RuntimeException;

class CloudFrontSignedCookieService
{
    /**
     * Generate CloudFront signed cookies scoped to tenant path.
     *
     * Policy allows access to https://{domain}/tenants/{tenant_uuid}/* only.
     * Provides CDN-level multi-tenant isolation.
     *
     * @param string|null $clientIp When cookie_restrict_ip enabled, pass $request->ip()
     * @return array{CloudFront-Policy: string, CloudFront-Signature: string, CloudFront-Key-Pair-Id: string}
     *
     * @throws RuntimeException If tenant has no UUID or signing fails
     */
    public function generateForTenant(Tenant $tenant, ?string $clientIp = null): array
    {
        if (!$tenant->uuid) {
            throw new RuntimeException('Tenant UUID required for CDN policy.');
        }

        $domain = config('cloudfront.domain');
        $keyPairId = config('cloudfront.key_pair_id');
        $privateKeyPath = $this->resolvePrivateKeyPath();

        if (empty($domain) || empty($keyPairId)) {
            throw new RuntimeException('CloudFront domain and key_pair_id must be configured.');
        }

        if (!file_exists($privateKeyPath)) {
            throw new RuntimeException("CloudFront private key not found at: {$privateKeyPath}");
        }

        $resource = "https://{$domain}/tenants/{$tenant->uuid}/*";
        return $this->signPolicy($resource, $keyPairId, $privateKeyPath, $clientIp);
    }

    /**
     * Sign a CloudFront policy and return cookie values.
     *
     * @param string|null $clientIp When cookie_restrict_ip is true, restrict to this IP (e.g. from $request->ip())
     */
    protected function signPolicy(string $resource, string $keyPairId, string $privateKeyPath, ?string $clientIp = null): array
    {
        $expirySeconds = $this->getExpirySeconds();
        $expires = time() + $expirySeconds;

        $condition = [
            'DateLessThan' => ['AWS:EpochTime' => $expires],
        ];

        if (config('cloudfront.cookie_restrict_ip', false) && $clientIp) {
            $condition['IpAddress'] = ['AWS:SourceIp' => $clientIp . '/32'];
        }

        $policy = [
            'Statement' => [
                [
                    'Resource' => $resource,
                    'Condition' => $condition,
                ],
            ],
        ];

        $policyJson = json_encode($policy);
        $encodedPolicy = $this->urlSafeBase64Encode($policyJson);
        $signature = $this->rsaSha1Sign($policyJson, $privateKeyPath);
        $encodedSignature = $this->urlSafeBase64Encode($signature);

        return [
            'CloudFront-Policy' => $encodedPolicy,
            'CloudFront-Signature' => $encodedSignature,
            'CloudFront-Key-Pair-Id' => $keyPairId,
        ];
    }

    /**
     * Get expiry in seconds. Uses authenticated_cookie_ttl when set; otherwise env-specific defaults.
     */
    public function getExpirySeconds(): int
    {
        $ttl = config('cloudfront.authenticated_cookie_ttl') ?: config('cdn.authenticated_cookie_ttl');
        if ($ttl > 0) {
            return (int) $ttl;
        }

        return app()->environment('production')
            ? config('cloudfront.cookie_expiry_production', 14400)
            : config('cloudfront.cookie_expiry_staging', 3600);
    }

    /**
     * Sign policy with RSA SHA1 (CloudFront requirement).
     */
    protected function rsaSha1Sign(string $policy, string $privateKeyPath): string
    {
        $keyContent = file_get_contents($privateKeyPath);
        $pkey = openssl_get_privatekey($keyContent);

        if ($pkey === false) {
            throw new RuntimeException('Failed to load CloudFront private key. Check key format.');
        }

        $signature = '';
        if (! openssl_sign($policy, $signature, $pkey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Failed to sign CloudFront policy.');
        }

        return $signature;
    }

    /**
     * URL-safe base64 encoding (CloudFront requirement).
     */
    protected function urlSafeBase64Encode(string $value): string
    {
        $encoded = base64_encode($value);

        return str_replace(['+', '=', '/'], ['-', '_', '~'], $encoded);
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
     * Sign a resource for CloudFront signed URL (canned policy).
     * Shared signing logic used by CloudFrontSignedUrlService.
     *
     * @param string $resource Full CDN URL (e.g. https://domain.com/path/to/file.jpg)
     * @param int $expiresAt Unix timestamp when URL expires
     * @return array{signature: string, key_pair_id: string, expires: int}
     *
     * @throws RuntimeException If signing fails
     */
    public function signForSignedUrl(string $resource, int $expiresAt): array
    {
        $keyPairId = config('cloudfront.key_pair_id');
        $privateKeyPath = $this->resolvePrivateKeyPath();

        if (empty($keyPairId)) {
            throw new RuntimeException('CloudFront key_pair_id must be configured.');
        }

        if (!file_exists($privateKeyPath)) {
            throw new RuntimeException("CloudFront private key not found at: {$privateKeyPath}");
        }

        $policy = [
            'Statement' => [
                [
                    'Resource' => $resource,
                    'Condition' => [
                        'DateLessThan' => ['AWS:EpochTime' => $expiresAt],
                    ],
                ],
            ],
        ];

        $policyJson = json_encode($policy);
        $signature = $this->rsaSha1Sign($policyJson, $privateKeyPath);
        $encodedSignature = $this->urlSafeBase64Encode($signature);

        return [
            'signature' => $encodedSignature,
            'key_pair_id' => $keyPairId,
            'expires' => $expiresAt,
        ];
    }
}
