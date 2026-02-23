<?php

namespace App\Services;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Support\AssetVariant;
use App\Support\CdnUrl;
use Illuminate\Support\Facades\Log;

class AssetUrlService
{
    private const ADMIN_TTL_SECONDS = 300;

    private const PUBLIC_TTL_SECONDS = 1800;

    /**
     * In-request cache for resolved tenants.
     *
     * @var array<int, Tenant|null>
     */
    protected array $tenantCache = [];

    /**
     * In-request cache for resolved buckets.
     *
     * @var array<string, StorageBucket|null>
     */
    protected array $bucketCache = [];

    /**
     * In-request cache for object existence checks.
     *
     * @var array<string, bool>
     */
    protected array $objectExistenceCache = [];

    /**
     * In-request cache for public visibility checks.
     *
     * @var array<string, bool>
     */
    protected array $publicAssetCache = [];

    protected ?bool $metricsEnabledCache = null;

    protected array $metrics = [
        'calls' => 0,
        'admin_thumbnail_calls' => 0,
        'public_thumbnail_calls' => 0,
        'admin_download_calls' => 0,
        'public_download_calls' => 0,
        'tenant_cache_hits' => 0,
        'tenant_cache_misses' => 0,
        'bucket_cache_hits' => 0,
        'bucket_cache_misses' => 0,
        'existence_checks' => 0,
        'existence_cache_hits' => 0,
        'total_time_ms' => 0,
    ];

    public function __construct(
        protected AssetVariantPathResolver $pathResolver,
        protected CloudFrontSignedUrlService $signedUrlService,
        protected TenantBucketService $tenantBucketService
    ) {
    }

    /**
     * Generate a CloudFront signed URL for an arbitrary storage path (canned policy).
     * Used by admin routes so thumbnails load via signed URLs instead of signed cookies.
     *
     * Signing must use the exact final URL the browser will request. CloudFront requires
     * ALL query parameters to be present at signing time or it returns 403. We build the
     * full CDN URL here; for admin thumbnails we do NOT append ?v= (cache busting is
     * unnecessary — signed URLs are short-lived and unique).
     *
     * @param string $path Storage path relative to CDN root (e.g. tenants/{uuid}/assets/.../thumb.webp)
     * @param int $ttlSeconds URL validity in seconds (default 600 = 10 min)
     * @return string Full signed URL: https://cdn-domain/path?Expires=...&Signature=...&Key-Pair-Id=...
     */
    public function getSignedCloudFrontUrl(string $path, int $ttlSeconds = 600): string
    {
        $cdnUrl = CdnUrl::url($path);
        if ($cdnUrl === '') {
            throw new \InvalidArgumentException('AssetUrlService: path resulted in empty CDN URL.');
        }

        if (app()->environment('local')) {
            return $cdnUrl;
        }

        if (! $this->cloudFrontSigningConfigured()) {
            throw new \RuntimeException('CloudFront signing is not configured.');
        }

        // Sign the exact URL (no ?v= or other params for admin; add any before this call if ever needed).
        // Expires must be UNIX seconds (not milliseconds); now()->timestamp is already in seconds.
        $expiresAt = now()->addSeconds($ttlSeconds)->timestamp;
        return $this->signedUrlService->sign($cdnUrl, $expiresAt);
    }

    /**
     * Get the first available thumbnail storage path for an asset (admin context).
     * No existence check, no signing. Used with getSignedCloudFrontUrl for admin grid.
     */
    public function getAdminThumbnailPath(Asset $asset): ?string
    {
        $tenant = $this->resolveTenantForAsset($asset);
        if (! $tenant) {
            return null;
        }

        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status->value
            : (string) ($asset->thumbnail_status ?? 'pending');

        $variants = $thumbnailStatus === ThumbnailStatus::COMPLETED->value
            ? [AssetVariant::THUMB_MEDIUM, AssetVariant::THUMB_SMALL, AssetVariant::THUMB_PREVIEW]
            : [AssetVariant::THUMB_PREVIEW];

        return $this->runInTenantContext($tenant, function () use ($asset, $variants) {
            foreach ($variants as $variant) {
                if (! $this->shouldAttemptVariant($asset, $variant)) {
                    continue;
                }
                $path = $this->pathResolver->resolve($asset, $variant->value);
                if ($path !== '') {
                    return $path;
                }
            }

            return null;
        });
    }

    /**
     * Admin thumbnail URL (signed CloudFront, 5-minute TTL).
     * Cross-tenant safe: resolves tenant context from Asset::tenant_id.
     */
    public function getAdminThumbnailUrl(Asset $asset): ?string
    {
        $start = null;
        if ($this->metricsEnabled()) {
            $start = microtime(true);
        }

        try {
            Log::debug('[AssetUrlService] getAdminThumbnailUrl', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
            ]);

            $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
                ? $asset->thumbnail_status->value
                : (string) ($asset->thumbnail_status ?? 'pending');

            $variants = $thumbnailStatus === ThumbnailStatus::COMPLETED->value
                ? [AssetVariant::THUMB_MEDIUM, AssetVariant::THUMB_SMALL, AssetVariant::THUMB_PREVIEW]
                : [AssetVariant::THUMB_PREVIEW];

            // Staging: skip S3 existence check to avoid 9+ round-trips and proxy timeouts (502)
            $requireExists = ! app()->environment('staging');

            return $this->firstAvailableVariantUrl(
                $asset,
                $variants,
                self::ADMIN_TTL_SECONDS,
                $requireExists
            );
        } catch (\Throwable $e) {
            Log::error('[AssetUrlService] getAdminThumbnailUrl failed', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        } finally {
            $this->recordTimedCall($start, 'admin_thumbnail_calls');
        }
    }

    /**
     * Admin helper for explicit thumbnail style links in detail view.
     * Supported styles: thumb, medium, large.
     */
    public function getAdminThumbnailUrlForStyle(Asset $asset, string $style): ?string
    {
        $start = null;
        if ($this->metricsEnabled()) {
            $start = microtime(true);
        }

        try {
            $variant = match ($style) {
                'thumb' => AssetVariant::THUMB_SMALL,
                'medium' => AssetVariant::THUMB_MEDIUM,
                'large' => AssetVariant::THUMB_LARGE,
                default => null,
            };

            if (! $variant) {
                return null;
            }

            // Staging: skip S3 existence check to reduce latency and avoid proxy timeouts
            $requireExists = ! app()->environment('staging');

            return $this->buildVariantUrl(
                $asset,
                $variant,
                self::ADMIN_TTL_SECONDS,
                $requireExists
            );
        } catch (\Throwable $e) {
            Log::error('[AssetUrlService] getAdminThumbnailUrlForStyle failed', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'style' => $style,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            $this->recordTimedCall($start, 'admin_thumbnail_calls');
        }
    }

    /**
     * Public thumbnail URL (signed CloudFront, 30-minute TTL).
     * Only available when the asset is public via collection membership.
     */
    public function getPublicThumbnailUrl(Asset $asset): ?string
    {
        $start = null;
        if ($this->metricsEnabled()) {
            $start = microtime(true);
        }

        try {
            if (! $this->isAssetPublic($asset)) {
                return null;
            }

            $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
                ? $asset->thumbnail_status->value
                : (string) ($asset->thumbnail_status ?? 'pending');

            $variants = $thumbnailStatus === ThumbnailStatus::COMPLETED->value
                ? [AssetVariant::THUMB_LARGE, AssetVariant::THUMB_MEDIUM, AssetVariant::THUMB_SMALL, AssetVariant::THUMB_PREVIEW]
                : [AssetVariant::THUMB_PREVIEW];

            return $this->firstAvailableVariantUrl(
                $asset,
                $variants,
                self::PUBLIC_TTL_SECONDS,
                true
            );
        } finally {
            $this->recordTimedCall($start, 'public_thumbnail_calls');
        }
    }

    /**
     * Admin source download URL (signed CloudFront, 5-minute TTL).
     */
    public function getAdminDownloadUrl(Asset $asset): ?string
    {
        $start = null;
        if ($this->metricsEnabled()) {
            $start = microtime(true);
        }

        try {
            Log::debug('[AssetUrlService] getAdminDownloadUrl', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
            ]);

            return $this->buildVariantUrl(
                $asset,
                AssetVariant::ORIGINAL,
                self::ADMIN_TTL_SECONDS,
                false
            );
        } catch (\Throwable $e) {
            Log::error('[AssetUrlService] getAdminDownloadUrl failed', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        } finally {
            $this->recordTimedCall($start, 'admin_download_calls');
        }
    }

    /**
     * Public source download URL (signed CloudFront, 30-minute TTL).
     * Only available when the asset is public via collection membership.
     */
    public function getPublicDownloadUrl(Asset $asset): ?string
    {
        $start = null;
        if ($this->metricsEnabled()) {
            $start = microtime(true);
        }

        try {
            if (! $this->isAssetPublic($asset)) {
                return null;
            }

            return $this->buildVariantUrl(
                $asset,
                AssetVariant::ORIGINAL,
                self::PUBLIC_TTL_SECONDS,
                false
            );
        } finally {
            $this->recordTimedCall($start, 'public_download_calls');
        }
    }

    /**
     * Try variants in order, returning the first URL that can be generated.
     *
     * @param array<int, AssetVariant> $variants
     */
    protected function firstAvailableVariantUrl(
        Asset $asset,
        array $variants,
        int $ttlSeconds,
        bool $requireObjectExists
    ): ?string {
        $tenant = $this->resolveTenantForAsset($asset);
        if (! $tenant) {
            return null;
        }

        return $this->runInTenantContext($tenant, function () use ($asset, $variants, $ttlSeconds, $requireObjectExists, $tenant) {
            foreach ($variants as $variant) {
                $url = $this->buildVariantUrlWithinTenant(
                    $asset,
                    $variant,
                    $ttlSeconds,
                    $requireObjectExists,
                    $tenant
                );
                if ($url !== null) {
                    return $url;
                }
            }

            return null;
        });
    }

    /**
     * Build URL for a specific variant under the asset tenant context.
     */
    protected function buildVariantUrl(
        Asset $asset,
        AssetVariant $variant,
        int $ttlSeconds,
        bool $requireObjectExists
    ): ?string {
        $tenant = $this->resolveTenantForAsset($asset);
        if (! $tenant) {
            return null;
        }

        return $this->runInTenantContext($tenant, function () use ($asset, $variant, $ttlSeconds, $requireObjectExists, $tenant) {
            return $this->buildVariantUrlWithinTenant(
                $asset,
                $variant,
                $ttlSeconds,
                $requireObjectExists,
                $tenant
            );
        });
    }

    /**
     * Build URL for a specific variant (expects tenant context already bound).
     */
    protected function buildVariantUrlWithinTenant(
        Asset $asset,
        AssetVariant $variant,
        int $ttlSeconds,
        bool $requireObjectExists,
        Tenant $tenant
    ): ?string {
        if (! $this->shouldAttemptVariant($asset, $variant)) {
            return null;
        }

        $path = $this->pathResolver->resolve($asset, $variant->value);
        if ($path === '') {
            return null;
        }

        if ($requireObjectExists) {
            $bucket = $this->resolveBucketForAsset($asset, $tenant);
            if (! $bucket || ! $this->objectExists($bucket, $path)) {
                return null;
            }
        }

        return $this->buildSignedCdnUrl($path, $ttlSeconds, $asset, $variant);
    }

    /**
     * Skip variant work when metadata says the file is not expected.
     * This avoids unnecessary S3 existence calls on large admin/public grids.
     */
    protected function shouldAttemptVariant(Asset $asset, AssetVariant $variant): bool
    {
        return match ($variant) {
            AssetVariant::THUMB_PREVIEW => ! empty($asset->metadata['preview_thumbnails']['preview']['path']),
            AssetVariant::THUMB_SMALL => $asset->thumbnailPathForStyle('thumb') !== null,
            AssetVariant::THUMB_MEDIUM => $asset->thumbnailPathForStyle('medium') !== null,
            AssetVariant::THUMB_LARGE => $asset->thumbnailPathForStyle('large') !== null,
            default => true,
        };
    }

    /**
     * Resolve tenant with in-request memoization to avoid repeated DB queries.
     */
    protected function resolveTenantForAsset(Asset $asset): ?Tenant
    {
        $tenantId = (int) $asset->tenant_id;
        if ($tenantId <= 0) {
            Log::warning('[AssetUrlService] resolveTenantForAsset: asset has no tenant_id', [
                'asset_id' => $asset->id,
            ]);
            return null;
        }

        if (array_key_exists($tenantId, $this->tenantCache)) {
            if ($this->metricsEnabled()) {
                $this->metrics['tenant_cache_hits']++;
            }

            return $this->tenantCache[$tenantId];
        }

        if ($this->metricsEnabled()) {
            $this->metrics['tenant_cache_misses']++;
        }

        $tenant = ($asset->relationLoaded('tenant') && $asset->tenant)
            ? $asset->tenant
            : Tenant::find($tenantId);

        $this->tenantCache[$tenantId] = $tenant;

        if ($tenant && empty($tenant->uuid)) {
            Log::warning('[AssetUrlService] resolveTenantForAsset: tenant has no uuid (multi-tenant admin CDN path may fail)', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenantId,
            ]);
        }

        return $tenant;
    }

    /**
     * Resolve bucket for existence checks without relying on global tenant state.
     */
    protected function resolveBucketForAsset(Asset $asset, Tenant $tenant): ?StorageBucket
    {
        $cacheKey = $tenant->id . ':' . ($asset->storage_bucket_id ?: 'active');
        if (array_key_exists($cacheKey, $this->bucketCache)) {
            if ($this->metricsEnabled()) {
                $this->metrics['bucket_cache_hits']++;
            }

            return $this->bucketCache[$cacheKey];
        }

        if ($this->metricsEnabled()) {
            $this->metrics['bucket_cache_misses']++;
        }

        if ($asset->relationLoaded('storageBucket') && $asset->storageBucket) {
            $this->bucketCache[$cacheKey] = $asset->storageBucket;

            return $asset->storageBucket;
        }

        if ($asset->storage_bucket_id) {
            $bucket = StorageBucket::query()
                ->where('id', $asset->storage_bucket_id)
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($bucket) {
                $this->bucketCache[$cacheKey] = $bucket;

                return $bucket;
            }
        }

        try {
            $resolved = $this->tenantBucketService->resolveActiveBucketOrFail($tenant);
            $this->bucketCache[$cacheKey] = $resolved;

            return $resolved;
        } catch (\Throwable $e) {
            Log::warning('[AssetUrlService] Failed to resolve tenant bucket for asset URL generation', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'error' => $e->getMessage(),
            ]);

            $this->bucketCache[$cacheKey] = null;

            return null;
        }
    }

    /**
     * Verify object existence (thumbnail race guard).
     */
    protected function objectExists(StorageBucket $bucket, string $path): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        if ($this->metricsEnabled()) {
            $this->metrics['existence_checks']++;
        }

        $cacheKey = $bucket->name . ':' . ltrim($path, '/');
        if (array_key_exists($cacheKey, $this->objectExistenceCache)) {
            if ($this->metricsEnabled()) {
                $this->metrics['existence_cache_hits']++;
            }

            return $this->objectExistenceCache[$cacheKey];
        }

        try {
            $exists = (bool) $this->tenantBucketService
                ->getS3Client()
                ->doesObjectExist($bucket->name, ltrim($path, '/'));
        } catch (\Throwable $e) {
            Log::warning('[AssetUrlService] Object existence check failed', [
                'bucket' => $bucket->name,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            $exists = false;
        }

        $this->objectExistenceCache[$cacheKey] = $exists;

        return $exists;
    }

    /**
     * Build a signed CloudFront URL from storage path.
     */
    protected function buildSignedCdnUrl(string $path, int $ttlSeconds, Asset $asset, AssetVariant $variant): ?string
    {
        $cdnUrl = CdnUrl::url($path);
        if ($cdnUrl === '') {
            return null;
        }

        if (! $this->cloudFrontSigningConfigured()) {
            if (app()->environment(['local', 'testing'])) {
                return $cdnUrl;
            }

            Log::warning('[AssetUrlService] CloudFront signing is not configured in non-local environment', [
                'asset_id' => $asset->id,
                'variant' => $variant->value,
            ]);

            return null;
        }

        try {
            // UNIX timestamp in seconds (not milliseconds); CloudFront expects seconds
            $expiresAt = now()->addSeconds($ttlSeconds)->timestamp;
            return $this->signedUrlService->sign($cdnUrl, $expiresAt);
        } catch (\Throwable $e) {
            Log::error('[AssetUrlService] Failed to sign CloudFront URL', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'variant' => $variant->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verify CloudFront signing requirements are present.
     */
    protected function cloudFrontSigningConfigured(): bool
    {
        $domain = (string) config('cloudfront.domain', '');
        $keyPairId = (string) config('cloudfront.key_pair_id', '');
        $privateKeyPath = (string) config('cloudfront.private_key_path', '');

        if ($domain === '' || $keyPairId === '' || $privateKeyPath === '') {
            return false;
        }

        $resolvedPath = str_starts_with($privateKeyPath, '/')
            ? $privateKeyPath
            : base_path($privateKeyPath);

        return file_exists($resolvedPath);
    }

    /**
     * Cached public eligibility check per asset.
     */
    protected function isAssetPublic(Asset $asset): bool
    {
        $cacheKey = (string) $asset->id;
        if (array_key_exists($cacheKey, $this->publicAssetCache)) {
            return $this->publicAssetCache[$cacheKey];
        }

        $isPublic = $asset->isPublic();
        $this->publicAssetCache[$cacheKey] = $isPublic;

        return $isPublic;
    }

    protected function metricsEnabled(): bool
    {
        if ($this->metricsEnabledCache !== null) {
            return $this->metricsEnabledCache;
        }

        $this->metricsEnabledCache = (bool) config('asset_url.metrics_enabled');

        return $this->metricsEnabledCache;
    }

    protected function recordTimedCall(?float $start, string $counterKey): void
    {
        if (! $this->metricsEnabled() || $start === null) {
            return;
        }

        $this->metrics['calls']++;
        if (array_key_exists($counterKey, $this->metrics)) {
            $this->metrics[$counterKey]++;
        }
        $this->metrics['total_time_ms'] += (microtime(true) - $start) * 1000;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function resetMetrics(): void
    {
        $this->metrics = [
            'calls' => 0,
            'admin_thumbnail_calls' => 0,
            'public_thumbnail_calls' => 0,
            'admin_download_calls' => 0,
            'public_download_calls' => 0,
            'tenant_cache_hits' => 0,
            'tenant_cache_misses' => 0,
            'bucket_cache_hits' => 0,
            'bucket_cache_misses' => 0,
            'existence_checks' => 0,
            'existence_cache_hits' => 0,
            'total_time_ms' => 0,
        ];
    }

    /**
     * Temporarily bind tenant context for disk/bucket resolution.
     * Always restores previous container state to avoid context leaks.
     */
    protected function runInTenantContext(Tenant $tenant, callable $callback): mixed
    {
        $hadTenantBinding = app()->bound('tenant');
        $previousTenant = $hadTenantBinding ? app('tenant') : null;

        app()->instance('tenant', $tenant);

        try {
            return $callback();
        } finally {
            if ($hadTenantBinding) {
                app()->instance('tenant', $previousTenant);
            } else {
                app()->forgetInstance('tenant');
            }
        }
    }
}
