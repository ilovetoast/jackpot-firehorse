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

    public function __construct(
        protected AssetVariantPathResolver $pathResolver,
        protected CloudFrontSignedUrlService $signedUrlService,
        protected TenantBucketService $tenantBucketService
    ) {
    }

    /**
     * Admin thumbnail URL (signed CloudFront, 5-minute TTL).
     * Cross-tenant safe: resolves tenant context from Asset::tenant_id.
     */
    public function getAdminThumbnailUrl(Asset $asset): ?string
    {
        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status->value
            : (string) ($asset->thumbnail_status ?? 'pending');

        $variants = $thumbnailStatus === ThumbnailStatus::COMPLETED->value
            ? [AssetVariant::THUMB_MEDIUM, AssetVariant::THUMB_SMALL, AssetVariant::THUMB_PREVIEW]
            : [AssetVariant::THUMB_PREVIEW];

        return $this->firstAvailableVariantUrl(
            $asset,
            $variants,
            self::ADMIN_TTL_SECONDS,
            true
        );
    }

    /**
     * Admin helper for explicit thumbnail style links in detail view.
     * Supported styles: thumb, medium, large.
     */
    public function getAdminThumbnailUrlForStyle(Asset $asset, string $style): ?string
    {
        $variant = match ($style) {
            'thumb' => AssetVariant::THUMB_SMALL,
            'medium' => AssetVariant::THUMB_MEDIUM,
            'large' => AssetVariant::THUMB_LARGE,
            default => null,
        };

        if (! $variant) {
            return null;
        }

        return $this->buildVariantUrl(
            $asset,
            $variant,
            self::ADMIN_TTL_SECONDS,
            true
        );
    }

    /**
     * Public thumbnail URL (signed CloudFront, 30-minute TTL).
     * Only available when the asset is public via collection membership.
     */
    public function getPublicThumbnailUrl(Asset $asset): ?string
    {
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
    }

    /**
     * Admin source download URL (signed CloudFront, 5-minute TTL).
     */
    public function getAdminDownloadUrl(Asset $asset): ?string
    {
        return $this->buildVariantUrl(
            $asset,
            AssetVariant::ORIGINAL,
            self::ADMIN_TTL_SECONDS,
            false
        );
    }

    /**
     * Public source download URL (signed CloudFront, 30-minute TTL).
     * Only available when the asset is public via collection membership.
     */
    public function getPublicDownloadUrl(Asset $asset): ?string
    {
        if (! $this->isAssetPublic($asset)) {
            return null;
        }

        return $this->buildVariantUrl(
            $asset,
            AssetVariant::ORIGINAL,
            self::PUBLIC_TTL_SECONDS,
            false
        );
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
            return null;
        }

        if (array_key_exists($tenantId, $this->tenantCache)) {
            return $this->tenantCache[$tenantId];
        }

        $tenant = ($asset->relationLoaded('tenant') && $asset->tenant)
            ? $asset->tenant
            : Tenant::find($tenantId);

        $this->tenantCache[$tenantId] = $tenant;

        return $tenant;
    }

    /**
     * Resolve bucket for existence checks without relying on global tenant state.
     */
    protected function resolveBucketForAsset(Asset $asset, Tenant $tenant): ?StorageBucket
    {
        $cacheKey = $tenant->id . ':' . ($asset->storage_bucket_id ?: 'active');
        if (array_key_exists($cacheKey, $this->bucketCache)) {
            return $this->bucketCache[$cacheKey];
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

        $cacheKey = $bucket->name . ':' . ltrim($path, '/');
        if (array_key_exists($cacheKey, $this->objectExistenceCache)) {
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
            return $this->signedUrlService->sign(
                $cdnUrl,
                now()->addSeconds($ttlSeconds)->timestamp
            );
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
