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
            : [AssetVariant::THUMB_PREVIEW, AssetVariant::THUMB_MEDIUM, AssetVariant::THUMB_SMALL];

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

        return $this->firstAvailableVariantUrl(
            $asset,
            [AssetVariant::THUMB_LARGE, AssetVariant::THUMB_MEDIUM, AssetVariant::THUMB_SMALL, AssetVariant::THUMB_PREVIEW],
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
        foreach ($variants as $variant) {
            $url = $this->buildVariantUrl($asset, $variant, $ttlSeconds, $requireObjectExists);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
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
        $tenant = Tenant::find($asset->tenant_id);
        if (! $tenant) {
            return null;
        }

        return $this->runInTenantContext($tenant, function () use ($asset, $variant, $ttlSeconds, $requireObjectExists, $tenant) {
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
        });
    }

    /**
     * Resolve bucket for existence checks without relying on global tenant state.
     */
    protected function resolveBucketForAsset(Asset $asset, Tenant $tenant): ?StorageBucket
    {
        if ($asset->relationLoaded('storageBucket') && $asset->storageBucket) {
            return $asset->storageBucket;
        }

        if ($asset->storage_bucket_id) {
            $bucket = StorageBucket::query()
                ->where('id', $asset->storage_bucket_id)
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($bucket) {
                return $bucket;
            }
        }

        try {
            return $this->tenantBucketService->resolveActiveBucketOrFail($tenant);
        } catch (\Throwable $e) {
            Log::warning('[AssetUrlService] Failed to resolve tenant bucket for asset URL generation', [
                'asset_id' => $asset->id,
                'tenant_id' => $asset->tenant_id,
                'error' => $e->getMessage(),
            ]);

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
