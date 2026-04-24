<?php

namespace App\Services\Studio;

use App\Studio\Rendering\Dto\StudioFontDescriptor;

/**
 * Builds grouped font options for the generative editor (stable keys for native export).
 */
final class StudioEditorFontRegistryService
{
    /**
     * @param  array<string, mixed>|null  $brandContext  Same shape as `brand_context` from {@see \App\Http\Controllers\Editor\EditorBrandContextController}
     * @return array{groups: list<array{id:string,label:string,fonts:list<array<string,mixed>>}>}
     */
    public function groupedFonts(?array $brandContext): array
    {
        $typography = is_array($brandContext['typography'] ?? null) ? $brandContext['typography'] : [];
        $groups = [];

        $primaryCanvas = trim((string) ($typography['canvas_primary_font_family'] ?? ''));
        $primaryLabel = trim((string) ($typography['primary_font'] ?? ''));
        $secondary = trim((string) ($typography['secondary_font'] ?? ''));

        $guidelineFonts = [];
        if ($primaryCanvas !== '' || $primaryLabel !== '') {
            $fam = $primaryCanvas !== '' ? $primaryCanvas : $primaryLabel;
            $gk = $this->guessKeyForCssFamily($fam);
            $guidelineFonts[] = (new StudioFontDescriptor(
                key: $gk,
                label: 'Brand primary — '.$this->firstFamilyToken($fam),
                source: $this->inferSourceFromKey($gk),
                family: $this->firstFamilyToken($fam),
                weight: 400,
                style: 'normal',
                exportSupported: true,
                cssStack: $fam,
            ))->toArray();
        }
        if ($secondary !== '' && strcasecmp($secondary, $primaryCanvas) !== 0 && strcasecmp($secondary, $primaryLabel) !== 0) {
            $gk = $this->guessKeyForCssFamily($secondary);
            $guidelineFonts[] = (new StudioFontDescriptor(
                key: $gk,
                label: 'Brand secondary — '.$this->firstFamilyToken($secondary),
                source: $this->inferSourceFromKey($gk),
                family: $this->firstFamilyToken($secondary),
                weight: 400,
                style: 'normal',
                exportSupported: true,
                cssStack: $secondary,
            ))->toArray();
        }
        if ($guidelineFonts !== []) {
            $groups[] = [
                'id' => 'brand_guidelines',
                'label' => 'Brand guidelines',
                'fonts' => $guidelineFonts,
            ];
        }

        $uploaded = [];
        foreach ($typography['font_face_sources'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $assetId = $row['asset_id'] ?? null;
            $family = trim((string) ($row['family'] ?? ''));
            if ($assetId === null || $assetId === '' || $family === '') {
                continue;
            }
            $key = 'tenant:'.(string) $assetId;
            $weight = (int) ($row['weight'] ?? 400);
            $style = (string) ($row['style'] ?? 'normal');
            $isLib = ($row['source'] ?? '') === 'library';
            $uploaded[] = (new StudioFontDescriptor(
                key: $key,
                label: $family.($isLib ? ' (library)' : ' (DNA)'),
                source: 'tenant',
                family: $family,
                weight: $weight,
                style: $style,
                exportSupported: true,
                cssStack: $this->quoteFamilyForCss($family).', sans-serif',
                assetId: (string) $assetId,
            ))->toArray();
        }
        if ($uploaded !== []) {
            $groups[] = [
                'id' => 'uploaded_brand_fonts',
                'label' => 'Uploaded brand fonts',
                'fonts' => $uploaded,
            ];
        }

        $googleOut = [];
        /** @var array<string, array<string, mixed>> $google */
        $google = is_array(config('studio_rendering.fonts.google', []))
            ? config('studio_rendering.fonts.google', [])
            : [];
        foreach ($google as $slug => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $label = (string) ($meta['label'] ?? $slug);
            $family = (string) ($meta['family'] ?? $label);
            $weight = (int) ($meta['weight'] ?? 400);
            $style = (string) ($meta['style'] ?? 'normal');
            $export = (bool) ($meta['supported_export'] ?? true);
            $googleOut[] = (new StudioFontDescriptor(
                key: 'google:'.$slug,
                label: $label.' — '.$weight,
                source: 'google',
                family: $family,
                weight: $weight,
                style: $style,
                exportSupported: $export,
                cssStack: $this->quoteFamilyForCss($family).', sans-serif',
            ))->toArray();
        }
        if ($googleOut !== []) {
            $groups[] = [
                'id' => 'google',
                'label' => 'Google Fonts (curated)',
                'fonts' => $googleOut,
            ];
        }

        $bundledOut = [];
        /** @var array<string, array<string, mixed>> $bundled */
        $bundled = is_array(config('studio_rendering.fonts.bundled', []))
            ? config('studio_rendering.fonts.bundled', [])
            : [];
        foreach ($bundled as $slug => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $path = trim((string) ($meta['path'] ?? ''));
            if ($path === '' || ! is_file($path)) {
                continue;
            }
            $label = (string) ($meta['label'] ?? $slug);
            $family = (string) ($meta['family'] ?? $label);
            $weight = (int) ($meta['weight'] ?? 400);
            $style = (string) ($meta['style'] ?? 'normal');
            $export = (bool) ($meta['export_supported'] ?? true);
            $bundledOut[] = (new StudioFontDescriptor(
                key: 'bundled:'.$slug,
                label: $label.' — '.$weight,
                source: 'bundled',
                family: $family,
                weight: $weight,
                style: $style,
                exportSupported: $export,
                cssStack: $this->quoteFamilyForCss($family).', system-ui, sans-serif',
            ))->toArray();
        }
        if ($bundledOut !== []) {
            $groups[] = [
                'id' => 'bundled',
                'label' => 'Bundled fonts',
                'fonts' => $bundledOut,
            ];
        }

        $groups[] = [
            'id' => 'system',
            'label' => 'System fallback',
            'fonts' => [
                (new StudioFontDescriptor(
                    key: 'bundled:system-ui-regular',
                    label: 'System UI (Inter)',
                    source: 'system',
                    family: 'system-ui',
                    weight: 400,
                    style: 'normal',
                    exportSupported: true,
                    cssStack: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                ))->toArray(),
                (new StudioFontDescriptor(
                    key: 'bundled:georgia-regular',
                    label: 'Georgia (metric-compatible)',
                    source: 'system',
                    family: 'Georgia',
                    weight: 400,
                    style: 'normal',
                    exportSupported: true,
                    cssStack: 'Georgia, "Times New Roman", serif',
                ))->toArray(),
            ],
        ];

        return ['groups' => $groups];
    }

    private function inferSourceFromKey(string $key): string
    {
        if (str_starts_with($key, 'bundled:')) {
            return 'bundled';
        }
        if (str_starts_with($key, 'google:')) {
            return 'google';
        }
        if (str_starts_with($key, 'tenant:')) {
            return 'tenant';
        }

        return 'system';
    }

    private function guessKeyForCssFamily(string $cssFamily): string
    {
        $slug = \App\Studio\Rendering\StudioLegacyFontFamilyMapper::bundledSlugFor($cssFamily, 400);
        if ($slug !== null) {
            return 'bundled:'.$slug;
        }
        /** @var array<string, array<string, mixed>> $google */
        $google = is_array(config('studio_rendering.fonts.google', []))
            ? config('studio_rendering.fonts.google', [])
            : [];
        $first = strtolower($this->firstFamilyToken($cssFamily));
        foreach ($google as $gSlug => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $fam = strtolower(trim((string) ($meta['family'] ?? '')));
            if ($fam !== '' && $fam === $first) {
                return 'google:'.$gSlug;
            }
        }

        return 'bundled:inter-regular';
    }

    private function firstFamilyToken(string $fontFamily): string
    {
        $s = trim($fontFamily);
        if ($s === '') {
            return '';
        }
        $parts = preg_split('/\s*,\s*/', $s) ?: [];

        return trim((string) ($parts[0] ?? ''), " '\"");
    }

    private function quoteFamilyForCss(string $family): string
    {
        $t = trim($family);
        if ($t === '') {
            return 'sans-serif';
        }
        if (preg_match('/[\s,]/', $t)) {
            return '"'.str_replace('"', '\\"', $t).'"';
        }

        return $t;
    }
}
