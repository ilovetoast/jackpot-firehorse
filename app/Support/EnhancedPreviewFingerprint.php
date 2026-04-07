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
}
