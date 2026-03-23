<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight Brand DNA snapshot for the generative editor (prompt augmentation).
 */
class EditorBrandContextController extends Controller
{
    /**
     * GET /app/api/editor/brand-context
     */
    public function show(Request $request): JsonResponse
    {
        $brand = app('brand');
        if (! $brand instanceof Brand) {
            return response()->json(['brand_context' => null]);
        }

        $brand->loadMissing(['brandModel.activeVersion']);

        return response()->json([
            'brand_context' => $this->serializeBrandContext($brand),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBrandContext(Brand $brand): array
    {
        $settings = is_array($brand->settings) ? $brand->settings : [];

        $tone = $settings['tone'] ?? $settings['voice'] ?? [];
        if (! is_array($tone)) {
            $tone = $tone !== null && $tone !== '' ? [(string) $tone] : [];
        }

        $colors = array_values(array_filter([
            $brand->primary_color,
            $brand->secondary_color,
            $brand->accent_color,
        ]));

        $typography = [
            'primary_font' => $settings['typography']['primary_font']
                ?? $settings['primary_font']
                ?? null,
            'secondary_font' => $settings['typography']['secondary_font'] ?? null,
            'font_urls' => [],
            /** @var list<array{family: string, asset_id: int, weight: string, style: string}> */
            'font_face_sources' => [],
            /** Google Fonts / self-hosted CSS only (not binary font files). */
            'stylesheet_urls' => [],
            'presets' => is_array($settings['typography']['presets'] ?? null)
                ? $settings['typography']['presets']
                : [],
        ];

        if (isset($settings['typography']['font_urls']) && is_array($settings['typography']['font_urls'])) {
            $typography['font_urls'] = array_values(array_filter(
                array_map('strval', $settings['typography']['font_urls'])
            ));
        }

        $brandColorSlots = [
            'primary' => $brand->primary_color ?: null,
            'secondary' => $brand->secondary_color ?: null,
            'accent' => $brand->accent_color ?: null,
        ];

        $visualStyle = $settings['visual_style'] ?? null;
        $archetype = $settings['archetype'] ?? null;

        $version = $brand->brandModel?->activeVersion;
        if ($version && is_array($version->model_payload)) {
            $p = $version->model_payload;
            if (! empty($p['tone'])) {
                $t = $p['tone'];
                $tone = is_array($t) ? $t : [$t];
            }
            if (! empty($p['visual_style'])) {
                $visualStyle = is_string($p['visual_style']) ? $p['visual_style'] : $visualStyle;
            }
            if (! empty($p['archetype'])) {
                $archetype = is_string($p['archetype']) ? $p['archetype'] : $archetype;
            }
            if (! empty($p['colors']) && is_array($p['colors'])) {
                $colors = array_values(array_unique(array_merge($colors, array_map('strval', $p['colors']))));
            }
            if (! empty($p['typography']) && is_array($p['typography'])) {
                $pt = $p['typography'];
                $typography['primary_font'] = $pt['primary_font'] ?? $typography['primary_font'];
                $typography['secondary_font'] = $pt['secondary_font'] ?? $typography['secondary_font'];
                if (! empty($pt['font_urls']) && is_array($pt['font_urls'])) {
                    $typography['font_urls'] = array_values(array_unique(array_merge(
                        $typography['font_urls'],
                        array_filter(array_map('strval', $pt['font_urls']))
                    )));
                }
                // Per-font licensed files (one URL per weight/file); same list the Brand Portal FontManager stores on each font entry.
                if (! empty($pt['fonts']) && is_array($pt['fonts'])) {
                    $fromFonts = [];
                    foreach ($pt['fonts'] as $fontEntry) {
                        if (! is_array($fontEntry)) {
                            continue;
                        }
                        $urls = $fontEntry['file_urls'] ?? [];
                        if (! is_array($urls)) {
                            continue;
                        }
                        foreach ($urls as $u) {
                            if (is_string($u) && $u !== '') {
                                $fromFonts[] = $u;
                            }
                        }
                    }
                    if ($fromFonts !== []) {
                        $typography['font_urls'] = array_values(array_unique(array_merge(
                            $typography['font_urls'],
                            $fromFonts
                        )));
                    }
                }
                if (! empty($pt['presets']) && is_array($pt['presets'])) {
                    $typography['presets'] = array_replace_recursive(
                        is_array($typography['presets']) ? $typography['presets'] : [],
                        $pt['presets']
                    );
                }

                $typography['font_face_sources'] = $this->buildFontFaceSourcesFromTypography($pt);
                /** Same `family` string as FontFace registration for the primary licensed upload (editor canvas). */
                $typography['canvas_primary_font_family'] = $this->canvasPrimaryFontFamily($pt);
                $stylesheetCandidates = array_merge(
                    $typography['font_urls'],
                    $this->collectExternalFontStylesheets($pt)
                );
                $typography['stylesheet_urls'] = $this->filterStylesheetUrls($stylesheetCandidates);
                $typography['font_urls'] = $typography['stylesheet_urls'];
            }
        }

        $sheetMerge = array_merge($typography['font_urls'], $typography['stylesheet_urls']);
        $typography['stylesheet_urls'] = $this->filterStylesheetUrls($sheetMerge);
        $typography['font_urls'] = $typography['stylesheet_urls'];

        return [
            'tone' => array_values(array_filter($tone, fn ($x) => $x !== null && $x !== '')),
            'colors' => $colors,
            'brand_color_slots' => $brandColorSlots,
            'typography' => $typography,
            'visual_style' => is_string($visualStyle) ? $visualStyle : null,
            'archetype' => is_string($archetype) ? $archetype : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payloadTypography  model_payload.typography
     * @return list<array{family: string, asset_id: int, weight: string, style: string}>
     */
    private function buildFontFaceSourcesFromTypography(array $payloadTypography): array
    {
        $fonts = $payloadTypography['fonts'] ?? null;
        if (! is_array($fonts)) {
            return [];
        }

        $out = [];
        foreach ($fonts as $fontEntry) {
            if (! is_array($fontEntry)) {
                continue;
            }
            $family = trim((string) ($fontEntry['name'] ?? ''));
            if ($family === '') {
                continue;
            }
            $urls = $fontEntry['file_urls'] ?? [];
            if (! is_array($urls)) {
                continue;
            }
            foreach ($urls as $u) {
                if (! is_string($u) || $u === '') {
                    continue;
                }
                $assetId = $this->parseAssetIdFromFontUrl($u);
                if ($assetId === null) {
                    continue;
                }
                $base = strtolower(basename(parse_url($u, PHP_URL_PATH) ?? ''));
                $style = str_contains($base, 'italic') ? 'italic' : 'normal';
                $weight = $this->guessFontWeightFromFilename($base) ?? '400';

                $out[] = [
                    'family' => $family,
                    'asset_id' => $assetId,
                    'weight' => $weight,
                    'style' => $style,
                ];
            }
        }

        return $out;
    }

    private function parseAssetIdFromFontUrl(string $url): ?int
    {
        $patterns = [
            '#/assets/(\d+)/download#',
            '#/assets/(\d+)/file#',
            '#/api/assets/(\d+)/(?:file|download)#',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Family name from Brand DNA that matches {@see buildFontFaceSourcesFromTypography} so CSS `font-family`
     * aligns with the FontFace `family` argument for uploaded files.
     */
    private function canvasPrimaryFontFamily(array $payloadTypography): ?string
    {
        $fonts = $payloadTypography['fonts'] ?? null;
        if (! is_array($fonts)) {
            return null;
        }

        $primaryLabel = isset($payloadTypography['primary_font'])
            ? trim((string) $payloadTypography['primary_font'])
            : '';

        $licensed = [];
        foreach ($fonts as $fontEntry) {
            if (! is_array($fontEntry)) {
                continue;
            }
            $name = trim((string) ($fontEntry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $urls = $fontEntry['file_urls'] ?? [];
            if (! is_array($urls)) {
                continue;
            }
            $hasAsset = false;
            foreach ($urls as $u) {
                if (is_string($u) && $this->parseAssetIdFromFontUrl($u) !== null) {
                    $hasAsset = true;
                    break;
                }
            }
            if (! $hasAsset) {
                continue;
            }
            $role = (string) ($fontEntry['role'] ?? '');
            $licensed[] = ['name' => $name, 'role' => $role];
        }

        if ($licensed === []) {
            return null;
        }

        if ($primaryLabel !== '') {
            foreach ($licensed as $row) {
                if (strcasecmp($row['name'], $primaryLabel) === 0) {
                    return $row['name'];
                }
            }
        }

        foreach ($licensed as $row) {
            if (in_array($row['role'], ['primary', 'display'], true)) {
                return $row['name'];
            }
        }

        if (count($licensed) === 1) {
            return $licensed[0]['name'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payloadTypography
     * @return list<string>
     */
    private function collectExternalFontStylesheets(array $payloadTypography): array
    {
        $links = $payloadTypography['external_font_links'] ?? [];
        if (! is_array($links)) {
            return [];
        }

        $out = [];
        foreach ($links as $u) {
            if (is_string($u) && str_starts_with($u, 'https://')) {
                $out[] = $u;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function filterStylesheetUrls(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $u) {
            if (! is_string($u) || $u === '') {
                continue;
            }
            if ($this->parseAssetIdFromFontUrl($u) !== null) {
                continue;
            }
            if (! $this->isLikelyStylesheetFontUrl($u)) {
                continue;
            }
            if (isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $out[] = $u;
        }

        return $out;
    }

    private function isLikelyStylesheetFontUrl(string $url): bool
    {
        $lower = strtolower($url);
        if (str_contains($lower, 'fonts.googleapis.com')) {
            return true;
        }
        if (str_contains($lower, 'fonts.bunny.net')) {
            return true;
        }
        if (str_contains($lower, '/css2?') || str_contains($lower, '/css?')) {
            return true;
        }
        if (str_ends_with($lower, '.css')) {
            return true;
        }

        return false;
    }

    private function guessFontWeightFromFilename(string $baseFilename): ?string
    {
        $s = strtolower($baseFilename);
        if (preg_match('/\b(black|heavy)\b/', $s)) {
            return '900';
        }
        if (preg_match('/\b(extrabold|extra[-_]?bold|ultrabold)\b/', $s)) {
            return '800';
        }
        if (preg_match('/\b(bold|bd)\b/', $s) && ! str_contains($s, 'semi') && ! str_contains($s, 'demi')) {
            return '700';
        }
        if (preg_match('/\b(semi|demi)[-_]?bold|sb\b/', $s)) {
            return '600';
        }
        if (preg_match('/\b(medium|med)\b/', $s)) {
            return '500';
        }
        if (preg_match('/\b(book|regular|roman|normal)\b/', $s)) {
            return '400';
        }
        if (preg_match('/\b(light|lt)\b/', $s)) {
            return '300';
        }
        if (preg_match('/\b(thin|hairline|extralight|extra[-_]?light)\b/', $s)) {
            return '200';
        }

        return null;
    }
}
