<?php

namespace App\Services;

use App\Models\Asset;
use App\Support\AssetVariant;

/**
 * Resolves canonical storage paths for asset variants.
 *
 * Uses storage_root_path to derive base prefix.
 * Canonical format: tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/...
 *
 * Do NOT hardcode tenant logic. Paths are derived from asset storage_root_path.
 */
class AssetVariantPathResolver
{
    /**
     * Thumbnail output extension (from config or default webp).
     */
    protected function thumbnailExtension(): string
    {
        return config('assets.thumbnail.output_format', 'webp') === 'webp' ? 'webp' : 'jpg';
    }

    /**
     * Resolve the canonical storage path for an asset variant.
     *
     * Prefers metadata paths when available (e.g. thumbnails from metadata).
     * Returns fallback placeholder path for stub variants (VIDEO_PREVIEW, PDF_PAGE) if file may not exist.
     *
     * @param Asset $asset
     * @param string $variant AssetVariant enum value (e.g. AssetVariant::ORIGINAL->value)
     * @param array $options Optional options (e.g. ['page' => 1] for PDF_PAGE)
     * @return string Storage path (S3 key). Returns fallback placeholder path if file may not exist (stub variants).
     */
    public function resolve(Asset $asset, string $variant, array $options = []): string
    {
        $basePath = $this->getBasePath($asset);
        $variantEnum = AssetVariant::tryFrom($variant) ?? AssetVariant::ORIGINAL;

        return match ($variantEnum) {
            AssetVariant::ORIGINAL => $asset->storage_root_path ?? '',
            AssetVariant::THUMB_SMALL => $asset->thumbnailPathForStyle('thumb')
                ?? ($basePath !== '' ? $basePath . 'thumbnails/thumb/thumb.' . $this->thumbnailExtension() : ''),
            AssetVariant::THUMB_MEDIUM => $asset->thumbnailPathForStyle('medium')
                ?? ($basePath !== '' ? $basePath . 'thumbnails/medium/medium.' . $this->thumbnailExtension() : ''),
            AssetVariant::THUMB_LARGE => $asset->thumbnailPathForStyle('large')
                ?? ($basePath !== '' ? $basePath . 'thumbnails/large/large.' . $this->thumbnailExtension() : ''),
            AssetVariant::THUMB_PREVIEW => $asset->metadata['preview_thumbnails']['preview']['path'] ?? '',
            AssetVariant::VIDEO_PREVIEW => $this->resolveVideoPreviewPath($asset, $basePath),
            AssetVariant::PDF_PAGE => $basePath !== '' ? $this->resolvePdfPagePath($basePath, $options) : '',
        };
    }

    /**
     * Resolve video preview path. Uses video_preview_url column (raw path) when available; otherwise canonical path (stub).
     */
    protected function resolveVideoPreviewPath(Asset $asset, string $basePath): string
    {
        $path = $asset->getRawOriginal('video_preview_url')
            ?? ($asset->attributes['video_preview_url'] ?? null);

        if ($path && is_string($path) && !str_starts_with($path, 'http')) {
            return $path;
        }

        return $basePath !== '' ? $basePath . 'previews/video_preview.mp4' : '';
    }

    /**
     * Get base path from asset storage_root_path (directory containing original).
     */
    protected function getBasePath(Asset $asset): string
    {
        $path = $asset->storage_root_path ?? '';
        if ($path === '') {
            return '';
        }

        $dir = dirname($path);

        return $dir === '.' ? '' : rtrim($dir, '/') . '/';
    }

    /**
     * Resolve PDF page path (stub). Returns placeholder if page not provided.
     */
    protected function resolvePdfPagePath(string $basePath, array $options): string
    {
        $page = $options['page'] ?? 1;

        return $basePath . 'pdf/pages/' . (int) $page . '.webp';
    }
}
