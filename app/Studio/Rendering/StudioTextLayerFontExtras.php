<?php

namespace App\Studio\Rendering;

/**
 * Normalizes font-related keys from persisted text layer JSON into {@see RenderLayer::$extra}.
 */
final class StudioTextLayerFontExtras
{
    /**
     * Shallow merge of typography keys from the same buckets as {@see CompositionRenderNormalizer}:
     * {@code defaults}, {@code style}, {@code props}, then {@code props.style} (later wins).
     *
     * @param  array<string, mixed>  $ly  document_json text layer
     * @return array<string, mixed>
     */
    public static function mergeShallowStyleSources(array $ly): array
    {
        $merged = [];
        foreach (['defaults', 'style', 'props'] as $k) {
            $v = $ly[$k] ?? null;
            if (is_array($v)) {
                foreach ($v as $kk => $vv) {
                    if (is_string($kk)) {
                        $merged[$kk] = $vv;
                    }
                }
            }
        }
        $props = $ly['props'] ?? null;
        if (is_array($props) && isset($props['style']) && is_array($props['style'])) {
            foreach ($props['style'] as $kk => $vv) {
                if (is_string($kk)) {
                    $merged[$kk] = $vv;
                }
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $rawLayer  document_json layer (type text)
     * @param  array<string, mixed>  $baseExtra  existing extra (content, font_family, …)
     * @return array<string, mixed>
     */
    public static function mergeFromDocumentLayer(array $rawLayer, array $baseExtra): array
    {
        $out = $baseExtra;
        $style = is_array($rawLayer['style'] ?? null) ? $rawLayer['style'] : [];
        $merged = self::mergeShallowStyleSources($rawLayer);

        $copyTop = [
            'font_asset_id', 'fontAssetId', 'font_local_path', 'fontLocalPath',
            'font_file_path', 'fontFilePath', 'resolved_font_path', 'resolvedFontPath',
            'font_disk', 'fontDisk', 'font_storage_path', 'fontStoragePath',
            'font_key', 'fontKey',
        ];
        foreach ($copyTop as $k) {
            if (array_key_exists($k, $rawLayer)) {
                $out[$k] = $rawLayer[$k];
            }
        }

        foreach (['fontKey', 'font_key', 'fontLabel', 'font_label', 'brandFontId', 'brand_font_id'] as $k) {
            $nk = match ($k) {
                'font_key' => 'font_key',
                'fontKey' => 'fontKey',
                'font_label' => 'font_label',
                'fontLabel' => 'fontLabel',
                'brand_font_id' => 'brand_font_id',
                'brandFontId' => 'brandFontId',
                default => $k,
            };
            if (array_key_exists($k, $merged)) {
                $out[$nk] = $merged[$k];
            } elseif (array_key_exists($k, $style)) {
                $out[$nk] = $style[$k];
            }
        }

        foreach (['fontAssetId', 'font_asset_id', 'fontLocalPath', 'font_local_path'] as $k) {
            $nk = $k === 'fontAssetId' ? 'fontAssetId' : ($k === 'font_asset_id' ? 'font_asset_id' : $k);
            if (array_key_exists($k, $merged)) {
                $out[$nk] = $merged[$k];
            } elseif (array_key_exists($k, $style)) {
                $out[$nk] = $style[$k];
            }
        }

        $fontNested = null;
        if (isset($rawLayer['font']) && is_array($rawLayer['font'])) {
            $fontNested = $rawLayer['font'];
        } elseif (isset($style['font']) && is_array($style['font'])) {
            $fontNested = $style['font'];
        } elseif (isset($merged['font']) && is_array($merged['font'])) {
            $fontNested = $merged['font'];
        }
        if ($fontNested !== null) {
            $out['font'] = self::normalizeFontNested($fontNested);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $font
     * @return array<string, mixed>
     */
    private static function normalizeFontNested(array $font): array
    {
        $n = [];
        if (isset($font['asset_id'])) {
            $n['asset_id'] = $font['asset_id'];
        }
        if (isset($font['assetId'])) {
            $n['asset_id'] = $font['assetId'];
        }
        if (isset($font['storage_path'])) {
            $n['storage_path'] = $font['storage_path'];
        }
        if (isset($font['storagePath'])) {
            $n['storage_path'] = $font['storagePath'];
        }
        if (isset($font['disk'])) {
            $n['disk'] = $font['disk'];
        }
        if (isset($font['family'])) {
            $n['family'] = $font['family'];
        }
        if (isset($font['Family'])) {
            $n['family'] = $font['Family'];
        }

        return $n;
    }
}
