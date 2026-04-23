<?php

namespace App\Studio\Rendering;

/**
 * Normalizes font-related keys from persisted text layer JSON into {@see RenderLayer::$extra}.
 */
final class StudioTextLayerFontExtras
{
    /**
     * @param  array<string, mixed>  $rawLayer  document_json layer (type text)
     * @param  array<string, mixed>  $baseExtra  existing extra (content, font_family, …)
     * @return array<string, mixed>
     */
    public static function mergeFromDocumentLayer(array $rawLayer, array $baseExtra): array
    {
        $out = $baseExtra;
        $style = is_array($rawLayer['style'] ?? null) ? $rawLayer['style'] : [];

        $copyTop = [
            'font_asset_id', 'fontAssetId', 'font_local_path', 'fontLocalPath',
            'font_file_path', 'fontFilePath', 'resolved_font_path', 'resolvedFontPath',
            'font_disk', 'fontDisk', 'font_storage_path', 'fontStoragePath',
        ];
        foreach ($copyTop as $k) {
            if (array_key_exists($k, $rawLayer)) {
                $out[$k] = $rawLayer[$k];
            }
        }

        foreach (['fontAssetId', 'font_asset_id', 'fontLocalPath', 'font_local_path'] as $k) {
            if (array_key_exists($k, $style)) {
                $nk = $k === 'fontAssetId' ? 'fontAssetId' : ($k === 'font_asset_id' ? 'font_asset_id' : $k);
                $out[$nk] = $style[$k];
            }
        }

        $fontNested = null;
        if (isset($rawLayer['font']) && is_array($rawLayer['font'])) {
            $fontNested = $rawLayer['font'];
        } elseif (isset($style['font']) && is_array($style['font'])) {
            $fontNested = $style['font'];
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
