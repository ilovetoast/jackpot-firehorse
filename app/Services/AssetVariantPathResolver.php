<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Support\AssetVariant;
use App\Support\ThumbnailMetadata;
use App\Support\ThumbnailMode;

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
     * @param  string  $variant  AssetVariant enum value (e.g. AssetVariant::ORIGINAL->value)
     * @param  array  $options  Optional options (e.g. ['page' => 1] for PDF_PAGE)
     * @return string Storage path (S3 key). Returns fallback placeholder path if file may not exist (stub variants).
     */
    public function resolve(Asset $asset, string $variant, array $options = []): string
    {
        $basePath = $this->getBasePath($asset);
        $variantEnum = AssetVariant::tryFrom($variant) ?? AssetVariant::ORIGINAL;

        return match ($variantEnum) {
            AssetVariant::ORIGINAL => $asset->storage_root_path ?? '',
            AssetVariant::THUMB_SMALL => $this->resolveRasterThumbnailPath($asset, $basePath, 'thumb', $options),
            AssetVariant::THUMB_MEDIUM => $this->resolveRasterThumbnailPath($asset, $basePath, 'medium', $options),
            AssetVariant::THUMB_LARGE => $this->resolveRasterThumbnailPath($asset, $basePath, 'large', $options),
            AssetVariant::THUMB_PREVIEW => $this->resolvePreviewThumbnailPath($asset, $basePath),
            AssetVariant::VIDEO_PREVIEW => $this->resolveVideoPreviewPath($asset, $basePath),
            AssetVariant::VIDEO_POSTER => $this->resolveVideoPosterPath($asset, $basePath),
            AssetVariant::PDF_PAGE => $this->resolvePdfPagePathFromVariant($asset, $options),
            AssetVariant::AUDIO_WAVEFORM => $this->resolveAudioWaveformPath($asset, $basePath),
        };
    }

    /**
     * Phase 3: Resolve waveform PNG path for AUDIO_WAVEFORM variant.
     *
     * AudioWaveformService writes the rendered PNG to S3 and stores the
     * key in metadata.audio.waveform_path. We honor the recorded path
     * verbatim and only fall back to the canonical convention (used by
     * legacy assets pre-metadata) when nothing has been written yet.
     */
    protected function resolveAudioWaveformPath(Asset $asset, string $basePath): string
    {
        $metadataPath = $asset->metadata['audio']['waveform_path'] ?? null;
        if (is_string($metadataPath) && $metadataPath !== '' && ! str_starts_with($metadataPath, 'http')) {
            return $metadataPath;
        }

        return $basePath !== '' ? $basePath.'previews/audio_waveform.png' : '';
    }

    /**
     * Resolve video preview path for VIDEO_PREVIEW variant.
     *
     * Priority:
     * 1. video_preview_url column (raw S3 path) — set by GenerateVideoPreviewJob after generation
     * 2. metadata['video_preview']['path'] — custom path if stored in metadata
     * 3. Canonical path: {basePath}previews/video_preview.mp4 — matches VideoPreviewGenerationService output
     *
     * Returns '' when no path available (triggers placeholder in AssetDeliveryService).
     */
    protected function resolveVideoPreviewPath(Asset $asset, string $basePath): string
    {
        $path = $asset->getRawOriginal('video_preview_url')
            ?? ($asset->attributes['video_preview_url'] ?? null);

        if ($path && is_string($path) && ! str_starts_with($path, 'http')) {
            return $path;
        }

        $metadataPath = $asset->metadata['video_preview']['path'] ?? null;
        if ($metadataPath && is_string($metadataPath) && ! str_starts_with($metadataPath, 'http')) {
            return $metadataPath;
        }

        return $basePath !== '' ? $basePath.'previews/video_preview.mp4' : '';
    }

    /**
     * Resolve video poster path. Uses video_poster_url column (raw path) when available; otherwise canonical path (stub).
     */
    protected function resolveVideoPosterPath(Asset $asset, string $basePath): string
    {
        $path = $asset->getRawOriginal('video_poster_url')
            ?? ($asset->attributes['video_poster_url'] ?? null);

        if ($path && is_string($path) && ! str_starts_with($path, 'http')) {
            return $path;
        }

        if ($basePath === '') {
            return '';
        }

        $meta = $asset->metadata ?? [];
        $mode = ThumbnailMode::default();
        $canonical = $basePath.'thumbnails/'.$mode.'/medium/medium.webp';
        $legacy = $basePath.'thumbnails/medium/medium.webp';

        return $this->guessThumbnailDiskPathWithoutMetadataPath($meta, $canonical, $legacy);
    }

    /**
     * True when there is no thumbnails bucket in metadata (null or empty array).
     * Old assets may have files on disk under legacy flat keys only; new pipeline always writes nested metadata.
     */
    protected function lacksThumbnailMetadataRoot(array $metadata): bool
    {
        $root = $metadata['thumbnails'] ?? null;

        return $root === null || $root === [];
    }

    /**
     * Single-URL fallback when no stored path: prefer legacy layout if thumbnails metadata is wholly absent,
     * else prefer canonical thumbnails/{mode}/… (partial pipeline / new-only-on-disk edge cases).
     *
     * @param  string  $canonical  e.g. …/thumbnails/original/thumb/thumb.webp
     * @param  string  $legacy  e.g. …/thumbnails/thumb/thumb.webp
     */
    protected function guessThumbnailDiskPathWithoutMetadataPath(array $metadata, string $canonical, string $legacy): string
    {
        if ($this->lacksThumbnailMetadataRoot($metadata)) {
            return $legacy;
        }

        return $canonical;
    }

    /**
     * Metadata path, else deterministic guess: canonical mode path when thumbnails metadata exists,
     * legacy flat path when metadata.thumbnails is missing (pre-mode on-disk layout).
     */
    protected function resolveRasterThumbnailPath(Asset $asset, string $basePath, string $style, array $options = []): string
    {
        $modeOpt = $options['thumbnail_mode'] ?? null;
        $modeFilter = is_string($modeOpt) && $modeOpt !== '' ? $modeOpt : null;

        $fromMeta = $asset->thumbnailPathForStyle($style, $modeFilter);
        if ($fromMeta !== null && $fromMeta !== '') {
            return $fromMeta;
        }
        if ($basePath === '') {
            return '';
        }
        $modeSeg = $modeFilter ?? ThumbnailMode::default();
        $ext = $this->thumbnailExtension();
        $canonical = $basePath.'thumbnails/'.$modeSeg.'/'.$style.'/'.$style.'.'.$ext;
        $legacy = $basePath.'thumbnails/'.$style.'/'.$style.'.'.$ext;

        return $this->guessThumbnailDiskPathWithoutMetadataPath($asset->metadata ?? [], $canonical, $legacy);
    }

    protected function resolvePreviewThumbnailPath(Asset $asset, string $basePath): string
    {
        $fromMeta = ThumbnailMetadata::previewPath($asset->metadata ?? []);
        if ($fromMeta !== null && $fromMeta !== '') {
            return $fromMeta;
        }
        if ($basePath === '') {
            return '';
        }
        $mode = ThumbnailMode::default();
        $ext = $this->thumbnailExtension();
        $canonical = $basePath.'thumbnails/'.$mode.'/preview/preview.'.$ext;
        $legacy = $basePath.'thumbnails/preview/preview.'.$ext;

        return $this->guessThumbnailDiskPathWithoutMetadataPath($asset->metadata ?? [], $canonical, $legacy);
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

        return $dir === '.' ? '' : rtrim($dir, '/').'/';
    }

    /**
     * Deterministic S3 path for a PDF page derivative.
     * Permanent path per version; no randomness. Used for store-once, never regenerate.
     * Version in path ensures new asset versions get distinct pages (assets can change).
     *
     * Format: assets/{tenant_id}/{asset_id}/v{version}/pdf-pages/page_{n}.webp
     */
    public static function resolvePdfPagePath(Asset $asset, int $pageNumber, ?int $versionNumber = null): string
    {
        $page = max(1, $pageNumber);
        $version = $versionNumber ?? (
            $asset->relationLoaded('currentVersion')
                ? ($asset->currentVersion?->version_number ?? 1)
                : (int) ($asset->currentVersion()->value('version_number') ?? 1)
        );

        return 'assets/'.$asset->tenant_id.'/'.$asset->id.'/v'.$version.'/pdf-pages/page_'.$page.'.webp';
    }

    /**
     * Resolve rendered PDF page path: from DB record when available, else deterministic path.
     */
    protected function resolvePdfPagePathFromVariant(Asset $asset, array $options): string
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $versionNumber = $asset->relationLoaded('currentVersion')
            ? ($asset->currentVersion?->version_number ?? 1)
            : (int) ($asset->currentVersion()->value('version_number') ?? 1);

        $record = AssetPdfPage::query()
            ->where('asset_id', $asset->id)
            ->where('version_number', $versionNumber)
            ->where('page_number', $page)
            ->where('status', 'completed')
            ->first();

        if ($record && $record->storage_path) {
            return $record->storage_path;
        }

        return self::resolvePdfPagePath($asset, $page, $versionNumber);
    }
}
