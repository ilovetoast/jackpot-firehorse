<?php

namespace App\Support;

use App\Models\Asset;
use App\Services\TemplateRenderer;
use App\Services\ThumbnailGenerationService;

/**
 * Presigned/CDN URLs for thumbnails keyed by pipeline mode (original, preferred, enhanced).
 * Used by the asset API and drawer polling so the UI can switch preview modes without extra round-trips.
 */
final class ThumbnailModeDeliveryUrls
{
    /**
     * Human label for compositing template ids (drawer copy).
     */
    public static function enhancedTemplateLabel(?string $templateId): string
    {
        if ($templateId === null || $templateId === '') {
            return '';
        }
        $map = [
            'catalog_v1' => 'Catalog',
            'surface_v1' => 'Surface',
            'neutral_v1' => 'Neutral',
        ];
        if (isset($map[$templateId])) {
            return $map[$templateId];
        }

        $base = preg_replace('/_v\\d+(\\.\\d+)*$/', '', $templateId) ?? $templateId;

        return ucfirst(str_replace('_', ' ', $base));
    }

    /**
     * Per-mode cache hints for the frontend (stable across presign rotation when storage paths unchanged).
     * Merges stored {@see $metadata['thumbnail_modes_meta']} with computed {@see cache_key}: {@code v1:{sha256}}.
     *
     * When {@code $includeEnhancedOutputFreshness} is true, the enhanced row may include {@code output_fresh} (bool)
     * by comparing stored fingerprints to live S3 + template config. Only use for single-asset polling — not list endpoints.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function modesMetaForApi(Asset $asset, bool $includeEnhancedOutputFreshness = false): array
    {
        $meta = $asset->metadata ?? [];
        $stored = $meta['thumbnail_modes_meta'] ?? [];
        if (! is_array($stored)) {
            $stored = [];
        }

        $genAt = isset($meta['thumbnails_generated_at']) ? (string) $meta['thumbnails_generated_at'] : '';

        $out = $stored;
        foreach ([ThumbnailMode::Original, ThumbnailMode::Preferred, ThumbnailMode::Enhanced, ThumbnailMode::Presentation] as $modeEnum) {
            $mode = $modeEnum->value;
            $paths = [];
            foreach (['thumb', 'medium', 'large'] as $style) {
                $p = $asset->thumbnailPathForStyle($style, $mode);
                if ($p !== null && $p !== '') {
                    $paths[$style] = $p;
                }
            }
            if ($paths === []) {
                continue;
            }
            ksort($paths);

            $confidencePart = '';
            if ($mode === ThumbnailMode::Preferred->value) {
                $pref = $stored['preferred'] ?? [];
                if (is_array($pref) && isset($pref['confidence']) && is_numeric($pref['confidence'])) {
                    $confidencePart = (string) (float) $pref['confidence'];
                }
            }

            $templatePart = '';
            if ($mode === ThumbnailMode::Enhanced->value) {
                $enh = $stored['enhanced'] ?? [];
                if (is_array($enh)) {
                    $templatePart = ($enh['template'] ?? '')
                        .'|'.($enh['template_version'] ?? '')
                        .'|'.($enh['input_hash'] ?? '');
                }
            }

            if ($mode === ThumbnailMode::Presentation->value) {
                $pr = $stored['presentation'] ?? [];
                if (is_array($pr)) {
                    $templatePart = ($pr['input_mode'] ?? '')
                        .'|'.hash('sha256', (string) ($pr['prompt'] ?? ''))
                        .'|'.($pr['model'] ?? '')
                        .'|'.($pr['style'] ?? '');
                }
            }

            $pathsJson = json_encode($paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($pathsJson === false) {
                $pathsJson = serialize($paths);
            }
            $hash = hash('sha256', $mode.'|'.$genAt.'|'.$pathsJson.'|'.$confidencePart.'|'.$templatePart);
            $modeRow = is_array($out[$mode] ?? null) ? $out[$mode] : [];
            $out[$mode] = array_merge($modeRow, [
                'cache_key' => 'v1:'.$hash,
            ]);
        }

        $enhOut = is_array($out['enhanced'] ?? null) ? $out['enhanced'] : [];
        $enhStored = is_array($stored['enhanced'] ?? null) ? $stored['enhanced'] : [];
        $tid = $enhOut['template'] ?? ($enhStored['template'] ?? null);
        if (is_string($tid) && $tid !== '') {
            $enhOut['template_label'] = self::enhancedTemplateLabel($tid);
            $out['enhanced'] = $enhOut;
        }

        if ($includeEnhancedOutputFreshness) {
            $modesStatus = is_array($meta['thumbnail_modes_status'] ?? null) ? $meta['thumbnail_modes_status'] : [];
            if (($modesStatus['enhanced'] ?? '') === 'complete' && $enhStored !== []) {
                try {
                    $asset->loadMissing(['storageBucket', 'currentVersion']);
                    $metaFp = is_array($asset->currentVersion?->metadata)
                        ? $asset->currentVersion->metadata
                        : $meta;
                    $fresh = EnhancedPreviewFingerprint::isCompleteOutputStillFresh(
                        $asset,
                        is_array($metaFp) ? $metaFp : [],
                        $enhStored,
                        app(TemplateRenderer::class),
                        app(ThumbnailGenerationService::class),
                    );
                    $enhOut = is_array($out['enhanced'] ?? null) ? $out['enhanced'] : [];
                    $enhOut['output_fresh'] = $fresh;
                    $out['enhanced'] = $enhOut;
                } catch (\Throwable) {
                    $enhOut = is_array($out['enhanced'] ?? null) ? $out['enhanced'] : [];
                    $enhOut['output_fresh'] = null;
                    $out['enhanced'] = $enhOut;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function map(Asset $asset): array
    {
        $out = [];
        foreach ([ThumbnailMode::Original, ThumbnailMode::Preferred, ThumbnailMode::Enhanced, ThumbnailMode::Presentation] as $modeEnum) {
            $mode = $modeEnum->value;
            foreach (['thumb', 'medium', 'large'] as $style) {
                $path = $asset->thumbnailPathForStyle($style, $mode);
                if ($path === null || $path === '') {
                    continue;
                }
                $variant = match ($style) {
                    'medium' => AssetVariant::THUMB_MEDIUM,
                    'large' => AssetVariant::THUMB_LARGE,
                    default => AssetVariant::THUMB_SMALL,
                };
                $url = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED, ['thumbnail_mode' => $mode]);
                if ($url !== '') {
                    $out[$mode] ??= [];
                    $out[$mode][$style] = $url;
                }
            }
        }

        return $out;
    }
}
