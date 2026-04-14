<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\StorageBucket;
use App\Services\ThumbnailGenerationService;

/**
 * Input fingerprint + template version for enhanced previews (staleness + cache busting).
 */
final class EnhancedPreviewFingerprint
{
    /**
     * Configured semantic version for compositing template presets.
     */
    public static function templateVersionFor(string $templateId): string
    {
        $templates = config('enhanced_preview.templates', []);
        $row = is_array($templates[$templateId] ?? null) ? $templates[$templateId] : [];

        return isset($row['version']) && is_string($row['version']) && $row['version'] !== ''
            ? $row['version']
            : '1.0.0';
    }

    /**
     * Same source resolution as {@see GenerateEnhancedPreviewJob}: preferred medium → thumb → original medium → thumb.
     *
     * @param  array<string, mixed>  $versionMetadata
     * @return array{0: string|null, 1: string} [sourcePath, sourceMode]
     */
    public static function resolveEnhancedSource(array $versionMetadata): array
    {
        $preferredMode = ThumbnailMode::Preferred->value;
        $originalMode = ThumbnailMode::Original->value;

        $prefMedium = ThumbnailMetadata::stylePath($versionMetadata, 'medium', $preferredMode);
        $prefThumb = ThumbnailMetadata::stylePath($versionMetadata, 'thumb', $preferredMode);
        $origMedium = ThumbnailMetadata::stylePath($versionMetadata, 'medium', $originalMode);
        $origThumb = ThumbnailMetadata::stylePath($versionMetadata, 'thumb', $originalMode);

        $hasPreferred = $prefMedium !== null || $prefThumb !== null;
        $sourceMode = $hasPreferred ? $preferredMode : $originalMode;
        $sourcePath = $prefMedium ?? $prefThumb ?? $origMedium ?? $origThumb;

        return [$sourcePath !== '' ? $sourcePath : null, $sourceMode];
    }

    /**
     * sha256(s3_key + '|' + etag_or_last_modified) for staleness vs preferred/original raster.
     */
    public static function computeInputHash(
        ThumbnailGenerationService $thumbnails,
        StorageBucket $bucket,
        string $sourceS3Key
    ): string {
        $tag = $thumbnails->headObjectFingerprint($bucket, $sourceS3Key);

        return hash('sha256', $sourceS3Key.'|'.$tag);
    }

    /**
     * True when stored enhanced metadata matches current template version + source S3 fingerprint.
     *
     * @param  array<string, mixed>  $enhancedMetaStored  thumbnail_modes_meta.enhanced
     * @param  array<string, mixed>  $versionMetadata
     */
    public static function isCompleteOutputStillFresh(
        Asset $asset,
        array $versionMetadata,
        array $enhancedMetaStored,
        \App\Services\TemplateRenderer $templateRenderer,
        ThumbnailGenerationService $thumbnailService
    ): bool {
        $storedHash = (string) ($enhancedMetaStored['input_hash'] ?? '');
        if ($storedHash === '') {
            return false;
        }

        if (! empty($enhancedMetaStored['manual_studio'])) {
            return self::isStudioOutputStillFresh(
                $asset,
                $versionMetadata,
                $enhancedMetaStored,
                $templateRenderer,
                $thumbnailService,
            );
        }

        $bucket = $asset->storageBucket;
        if (! $bucket) {
            return false;
        }

        [$sourcePath] = self::resolveEnhancedSource($versionMetadata);
        if ($sourcePath === null || $sourcePath === '') {
            return false;
        }

        $currentHash = self::computeInputHash($thumbnailService, $bucket, $sourcePath);
        if ($currentHash === '' || $currentHash !== $storedHash) {
            return false;
        }

        $currentTemplate = $templateRenderer->selectTemplateForAsset($asset);
        $currentTv = self::templateVersionFor($currentTemplate);

        return (string) ($enhancedMetaStored['template'] ?? '') === $currentTemplate
            && (string) ($enhancedMetaStored['template_version'] ?? '') === $currentTv;
    }

    /**
     * Fingerprint for manual Studio View: large source object + normalized crop + POI + compositing template.
     *
     * @param  array{x:float,y:float,width:float,height:float}  $cropNormalized
     * @param  array{x:float,y:float}|null  $poiNormalized
     */
    public static function computeStudioInputHash(
        ThumbnailGenerationService $thumbnails,
        \App\Models\StorageBucket $bucket,
        string $largeSourceS3Key,
        array $cropNormalized,
        ?array $poiNormalized,
        string $templateId,
    ): string {
        $tag = $thumbnails->headObjectFingerprint($bucket, $largeSourceS3Key);
        $payload = json_encode([
            'key' => $largeSourceS3Key,
            'tag' => $tag,
            'crop' => $cropNormalized,
            'poi' => $poiNormalized,
            'template' => $templateId,
            'tv' => self::templateVersionFor($templateId),
            'transparent_plate' => (bool) config('enhanced_preview.transparent_plate', true),
        ], JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $payload);
    }

    /**
     * When enhanced metadata is from manual Studio View, freshness uses {@see computeStudioInputHash}.
     *
     * @param  array<string, mixed>  $enhancedMetaStored
     * @param  array<string, mixed>  $versionMetadata
     */
    public static function isStudioOutputStillFresh(
        Asset $asset,
        array $versionMetadata,
        array $enhancedMetaStored,
        \App\Services\TemplateRenderer $templateRenderer,
        ThumbnailGenerationService $thumbnailService,
    ): bool {
        $storedHash = (string) ($enhancedMetaStored['input_hash'] ?? '');
        if ($storedHash === '') {
            return false;
        }

        $bucket = $asset->storageBucket;
        if (! $bucket) {
            return false;
        }

        [$largePath] = StudioViewSourceResolver::resolveLargeRasterPath($versionMetadata);
        if ($largePath === null || $largePath === '') {
            return false;
        }

        $crop = $enhancedMetaStored['studio_crop'] ?? null;
        if (! is_array($crop) || ! isset($crop['x'], $crop['y'], $crop['width'], $crop['height'])) {
            return false;
        }

        $cropNorm = [
            'x' => (float) $crop['x'],
            'y' => (float) $crop['y'],
            'width' => (float) $crop['width'],
            'height' => (float) $crop['height'],
        ];

        $poi = null;
        if (isset($enhancedMetaStored['poi']) && is_array($enhancedMetaStored['poi'])
            && isset($enhancedMetaStored['poi']['x'], $enhancedMetaStored['poi']['y'])) {
            $poi = [
                'x' => (float) $enhancedMetaStored['poi']['x'],
                'y' => (float) $enhancedMetaStored['poi']['y'],
            ];
        }

        $currentTemplate = $templateRenderer->selectTemplateForAsset($asset);
        $currentHash = self::computeStudioInputHash(
            $thumbnailService,
            $bucket,
            $largePath,
            $cropNorm,
            $poi,
            $currentTemplate,
        );

        return $currentHash !== '' && $currentHash === $storedHash;
    }

    /**
     * AI presentation (stored under {@see ThumbnailMode::Presentation}) prefers Studio (enhanced) raster, else pipeline source.
     *
     * @param  array<string, mixed>  $versionMetadata
     * @return array{0: string|null, 1: string} path, label (enhanced|preferred|original)
     */
    public static function resolvePresentationAiSource(array $versionMetadata): array
    {
        $enhancedMode = ThumbnailMode::Enhanced->value;
        foreach (['medium', 'large', 'thumb'] as $style) {
            $p = ThumbnailMetadata::stylePath($versionMetadata, $style, $enhancedMode);
            if ($p !== null && $p !== '') {
                return [$p, $enhancedMode];
            }
        }

        [$path, $mode] = self::resolveEnhancedSource($versionMetadata);

        return [$path, $mode];
    }
}
