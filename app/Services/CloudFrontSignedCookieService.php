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
     * @return array{CloudFront-Policy: string, CloudFront-Signature: string, CloudFront-Key-Pair-Id: string}
     *
     * @throws RuntimeException If tenant has no UUID or signing fails
     */
    public function generateForTenant(Tenant $tenant): array
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
        return $this->signPolicy($resource, $keyPairId, $privateKeyPath);
    }

    /**
     * Generate CloudFront signed cookies with wildcard policy (legacy).
     *
     * Policy allows access to https://{domain}/*. Kept for backwards compatibility
     * but no longer used. Use generateForTenant() for tenant-scoped access.
     *
     * @return array{CloudFront-Policy: string, CloudFront-Signature: string, CloudFront-Key-Pair-Id: string}
     *
     * @throws RuntimeException If private key is missing or invalid
     */
    public function generate(): array
    {
        $domain = config('cloudfront.domain');
        $keyPairId = config('cloudfront.key_pair_id');
        $privateKeyPath = $this->resolvePrivateKeyPath();

        if (empty($domain) || empty($keyPairId)) {
            throw new RuntimeException('CloudFront domain and key_pair_id must be configured.');
        }

        if (!file_exists($privateKeyPath)) {
            throw new RuntimeException("CloudFront private key not found at: {$privateKeyPath}");
        }

        $resource = 'https://' . $domain . '/*';
        return $this->signPolicy($resource, $keyPairId, $privateKeyPath);
    }

    /**
     * Sign a CloudFront policy and return cookie values.
     */
    protected function signPolicy(string $resource, string $keyPairId, string $privateKeyPath): array
    {
        $expirySeconds = $this->getExpirySeconds();
        $expires = time() + $expirySeconds;

        $policy = [
            'Statement' => [
                [
                    'Resource' => $resource,
                    'Condition' => [
                        'DateLessThan' => ['AWS:EpochTime' => $expires],
                    ],
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
     * Get expiry in seconds based on environment.
     */
    public function getExpirySeconds(): int
    {
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
}
