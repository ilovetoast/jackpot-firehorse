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
     * Cache key for resolved metadata schema.
     * Unique per (tenant, brand, category, assetType).
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param string $assetType
     * @return string
     */
    public static function schemaKey(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        string $assetType
    ): string {
        $b = $brandId ?? 'n';
        $c = $categoryId ?? 'n';
        return "metadata_schema:{$tenantId}:{$b}:{$c}:{$assetType}";
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
     * Flush metadata schema cache for a single tenant.
     * Safe to call when cache driver does not support tags (no-op).
     *
     * @param int $tenantId
     */
    public static function flushTenant(int $tenantId): void
    {
        $store = Cache::getStore();
        if (! method_exists($store, 'tags')) {
            return;
        }
        Cache::tags(self::tags($tenantId))->flush();
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
