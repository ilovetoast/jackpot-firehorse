<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache tags and key building for metadata schema.
 * Used for tenant-scoped caching and invalidation via observers.
 */
class MetadataCache
{
    public const TAG_METADATA = 'metadata';

    /**
     * Cache tags for a tenant's metadata schema entries.
     * Use for remember and for flush (tenant-specific invalidation).
     *
     * @param int $tenantId
     * @return array{0: string, 1: string}
     */
    public static function tags(int $tenantId): array
    {
        return [
            "tenant:{$tenantId}",
            self::TAG_METADATA,
        ];
    }

    /**
     * Tags for global metadata flush (e.g. system field change).
     * Flushing this clears all metadata schema caches.
     *
     * @return array{0: string}
     */
    public static function globalTags(): array
    {
        return [self::TAG_METADATA];
    }

    /**
     * Cache key for the tenant's schema version (used for invalidation).
     *
     * @param int $tenantId
     * @return string
     */
    public static function versionKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:metadata_schema_version";
    }

    /**
     * Get current schema version for tenant (bumping invalidates all schema keys for that tenant).
     *
     * @param int $tenantId
     * @return int
     */
    public static function getVersion(int $tenantId): int
    {
        return (int) cache()->rememberForever(
            self::versionKey($tenantId),
            fn () => 1
        );
    }

    /**
     * Bump tenant schema version so all cached schema entries for that tenant are considered stale.
     *
     * @param int $tenantId
     * @return void
     */
    public static function bumpVersion(int $tenantId): void
    {
        $key = self::versionKey($tenantId);
        $current = (int) cache()->get($key, 1);
        cache()->forever($key, $current + 1);
    }

    /**
     * Cache key for resolved metadata schema.
     * Unique per (tenant, version, brand, category, assetType). Version included for invalidation.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param string|null $assetType
     * @return string
     */
    public static function schemaKey(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        ?string $assetType
    ): string {
        $version = self::getVersion($tenantId);
        return implode(':', [
            'metadata_schema',
            (string) $tenantId,
            "v{$version}",
            $brandId !== null ? (string) $brandId : 'null',
            $categoryId !== null ? (string) $categoryId : 'null',
            $assetType !== null ? $assetType : 'null',
        ]);
    }

    /**
     * Return a tagged cache store for the tenant, or null if the driver does not support tags.
     * Use this to fail safely when Redis (or another tag-capable driver) is not available.
     *
     * @param int $tenantId
     * @return \Illuminate\Cache\TagSet|\Illuminate\Contracts\Cache\Repository|null Tagged repository, or null if tags unsupported
     */
    public static function taggedStore(int $tenantId)
    {
        $store = Cache::getStore();
        if (! method_exists($store, 'tags')) {
            return null;
        }

        return Cache::tags(self::tags($tenantId));
    }

    /**
     * Invalidate metadata schema cache for a single tenant (version-based).
     * Replaced tag flush with version bump; no tag support required.
     *
     * @param int $tenantId
     */
    public static function flushTenant(int $tenantId): void
    {
        self::bumpVersion($tenantId);
    }

    /**
     * Flush all metadata schema caches (e.g. when a system-wide field changes).
     * Only use when driver supports tags.
     */
    public static function flushGlobal(): void
    {
        $store = Cache::getStore();
        if (! method_exists($store, 'tags')) {
            return;
        }
        Cache::tags(self::globalTags())->flush();
    }
}
