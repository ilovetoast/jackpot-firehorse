<?php

namespace App\Services\Studio;

use App\Models\Composition;

/**
 * Declares which persisted document features are unsupported by the FFmpeg-native V1 exporter.
 * Used at export request time and again inside the worker as a safety check.
 */
final class StudioCompositionFfmpegNativeFeaturePolicy
{
    /**
     * @return list<string> machine-readable codes (empty = supported)
     */
    public static function unsupportedCodes(Composition $composition): array
    {
        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $layers = isset($doc['layers']) && is_array($doc['layers']) ? $doc['layers'] : [];
        $codes = [];
        foreach ($layers as $ly) {
            if (! is_array($ly)) {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            $type = (string) ($ly['type'] ?? '');
            if ($type === 'mask') {
                $codes[] = 'mask_layer';
            }
            $blend = strtolower(trim((string) ($ly['blendMode'] ?? $ly['blend_mode'] ?? 'normal')));
            if ($blend === '') {
                $blend = 'normal';
            }
            // Image / generative_image: FFmpeg V1 uses overlay (normal-style composite). Non-normal blend
            // modes are not reproduced faithfully but are allowed so publish does not hard-fail.
            if ($blend !== 'normal' && $type === 'video') {
                $codes[] = 'non_normal_blend:'.$type;
            }
        }

        return array_values(array_unique($codes));
    }

    public static function isSupported(Composition $composition): bool
    {
        return self::unsupportedCodes($composition) === [];
    }

    public static function humanSummary(array $codes): string
    {
        if ($codes === []) {
            return '';
        }

        return 'This composition uses features not yet supported by FFmpeg-native export: '.implode(', ', $codes).'.';
    }
}
