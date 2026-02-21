<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Tenant;

/**
 * Central path generator for canonical shared bucket asset storage.
 *
 * Phase 5 + 6: All shared bucket assets MUST follow this structure:
 *
 *   tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/original.{ext}
 *   tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/thumbnails/{style}/{filename}
 *
 * NO tenant_id usage. NO brand_id in path. NO legacy uuid_filename pattern.
 */
class AssetPathGenerator
{
    /**
     * Generate canonical path for original asset file.
     *
     * @param Tenant $tenant Tenant model (required for isolation)
     * @param Asset $asset Asset model
     * @param int $version Version number (e.g. 1, 2, 3)
     * @param string $extension File extension (e.g. jpg, png)
     * @return string Canonical S3 key
     */
    public function generateOriginalPath(Tenant $tenant, Asset $asset, int $version, string $extension): string
    {
        if (!$tenant->uuid) {
            throw new \RuntimeException('Tenant UUID required for canonical storage path.');
        }
        if ($version < 1) {
            throw new \RuntimeException('Version must be >= 1 for canonical storage path. Non-versioned path writes are not allowed.');
        }

        return "tenants/{$tenant->uuid}/assets/{$asset->id}/v{$version}/original.{$extension}";
    }

    /**
     * Generate canonical path for thumbnail.
     *
     * @param Tenant $tenant Tenant model (required for isolation)
     * @param Asset $asset Asset model
     * @param int $version Version number
     * @param string $style Thumbnail style (e.g. grid, detail, preview)
     * @param string $filename Thumbnail filename (e.g. grid.jpg)
     * @return string Canonical S3 key
     */
    public function generateThumbnailPath(Tenant $tenant, Asset $asset, int $version, string $style, string $filename): string
    {
        if (!$tenant->uuid) {
            throw new \RuntimeException('Tenant UUID required for canonical storage path.');
        }
        if ($version < 1) {
            throw new \RuntimeException('Version must be >= 1 for canonical storage path. Non-versioned path writes are not allowed.');
        }

        return "tenants/{$tenant->uuid}/assets/{$asset->id}/v{$version}/thumbnails/{$style}/{$filename}";
    }
}
