<?php

namespace App\Services\BrandIntelligence;

use App\Models\Asset;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;

/**
 * Compares asset dominant colors (from image analysis) to the brand palette using ΔE76
 * (tolerant matching) and a simple hue-opposite heuristic — not exact hex equality.
 */
final class BrandColorPaletteAlignmentEvaluator
{
    /** ΔE below this counts as a strong match. */
    private const DELTA_E_STRONG = 18.0;

    /** ΔE below this counts as a "close enough" match for palette family. */
    private const DELTA_E_CLOSE = 32.0;

    /** If average min-ΔE exceeds this, creative is likely off-palette (unless opposite heuristic fires). */
    private const DELTA_E_AVG_FAIL = 42.0;

    /** HSL: saturation above this → "chromatic" for opposite-palette check. */
    private const SATURATION_CHROMATIC = 0.12;

    /** Hue wheel difference (degrees) to treat as opposing chromatic palettes. */
    private const OPPOSITE_HUE_MIN = 95.0;

    /**
     * @return array{
     *     evaluated: bool,
     *     aligned: bool|null,
     *     opposite_palette: bool,
     *     brand_colors_available: bool,
     *     asset_colors_available: bool,
     *     mean_min_delta_e: float|null,
     *     per_brand_min_delta_e: list<float>
     * }
     */
    public function evaluate(Asset $asset, Brand $brand): array
    {
        $brandHexes = $this->extractBrandPaletteHexes($brand);
        $assetColors = $this->extractAssetDominantColors($asset);

        $brandOk = count($brandHexes) > 0;
        $assetOk = count($assetColors) > 0;

        if (! $brandOk || ! $assetOk) {
            return [
                'evaluated' => false,
                'aligned' => null,
                'opposite_palette' => false,
                'brand_colors_available' => $brandOk,
                'asset_colors_available' => $assetOk,
                'mean_min_delta_e' => null,
                'per_brand_min_delta_e' => [],
            ];
        }

        $brandLabs = [];
        foreach ($brandHexes as $hex) {
            $rgb = $this->hexToRgb($hex);
            if ($rgb !== null) {
                $brandLabs[] = $this->rgbToLab($rgb[0], $rgb[1], $rgb[2]);
            }
        }

        $assetLabs = [];
        foreach ($assetColors as $row) {
            $hex = $row['hex'] ?? null;
            if (! is_string($hex)) {
                continue;
            }
            $rgb = $this->hexToRgb($hex);
            if ($rgb !== null) {
                $assetLabs[] = [
                    'lab' => $this->rgbToLab($rgb[0], $rgb[1], $rgb[2]),
                    'weight' => isset($row['coverage']) && is_numeric($row['coverage']) ? (float) $row['coverage'] : 1.0,
                ];
            }
        }

        if ($brandLabs === [] || $assetLabs === []) {
            return [
                'evaluated' => false,
                'aligned' => null,
                'opposite_palette' => false,
                'brand_colors_available' => $brandOk,
                'asset_colors_available' => $assetOk,
                'mean_min_delta_e' => null,
                'per_brand_min_delta_e' => [],
            ];
        }

        $perBrandMin = [];
        foreach ($brandLabs as $bLab) {
            $min = null;
            foreach ($assetLabs as $a) {
                $d = $this->deltaE76($bLab, $a['lab']);
                $min = $min === null ? $d : min($min, $d);
            }
            $perBrandMin[] = (float) $min;
        }

        $meanMin = array_sum($perBrandMin) / max(1, count($perBrandMin));
        $opposite = $this->detectOppositePalette($brandHexes, $assetColors);

        $strongHits = count(array_filter($perBrandMin, fn (float $d) => $d <= self::DELTA_E_STRONG));
        $closeHits = count(array_filter($perBrandMin, fn (float $d) => $d <= self::DELTA_E_CLOSE));

        $aligned = ! $opposite
            && (
                $meanMin <= self::DELTA_E_CLOSE
                || ($closeHits >= max(1, (int) ceil(count($perBrandMin) * 0.5)) && $meanMin <= self::DELTA_E_AVG_FAIL)
                || ($strongHits >= 1 && $meanMin <= self::DELTA_E_AVG_FAIL + 6)
            );

        if (! $opposite && $meanMin > self::DELTA_E_AVG_FAIL && $closeHits === 0) {
            $aligned = false;
        }

        return [
            'evaluated' => true,
            'aligned' => $aligned,
            'opposite_palette' => $opposite,
            'brand_colors_available' => true,
            'asset_colors_available' => true,
            'mean_min_delta_e' => round($meanMin, 2),
            'per_brand_min_delta_e' => array_map(fn (float $v) => round($v, 2), $perBrandMin),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractBrandPaletteHexes(Brand $brand): array
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];
        $colors = $visual['colors'] ?? $visual['palette'] ?? $visual['brand_colors'] ?? [];
        if (! is_array($colors)) {
            return [];
        }

        $out = [];
        foreach ($colors as $c) {
            if (is_string($c) && trim($c) !== '') {
                $h = $this->normalizeHex($c);
                if ($h !== null) {
                    $out[] = $h;
                }
            }
            if (is_array($c)) {
                $raw = $c['hex'] ?? $c['value'] ?? null;
                if (is_string($raw) && trim($raw) !== '') {
                    $h = $this->normalizeHex($raw);
                    if ($h !== null) {
                        $out[] = $h;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{hex: string, coverage?: float}>
     */
    private function extractAssetDominantColors(Asset $asset): array
    {
        $meta = $asset->metadata ?? [];
        $raw = $meta['dominant_colors'] ?? data_get($meta, 'fields.dominant_colors');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            $raw = $this->dominantColorsFromAssetMetadataTable($asset);
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hex = $row['hex'] ?? null;
            if (! is_string($hex)) {
                continue;
            }
            $norm = $this->normalizeHex($hex);
            if ($norm === null) {
                continue;
            }
            $cov = $row['coverage'] ?? null;
            $out[] = [
                'hex' => $norm,
                'coverage' => is_numeric($cov) ? (float) $cov : 1.0,
            ];
        }

        return $out;
    }

    /**
     * Canonical dominant_colors often live in asset_metadata while metadata JSON is still hydrating.
     *
     * @return array<int, mixed>|null
     */
    private function dominantColorsFromAssetMetadataTable(Asset $asset): ?array
    {
        $fieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
        if (! $fieldId) {
            return null;
        }
        $row = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->orderByDesc('id')
            ->first();
        if (! $row || $row->value_json === null) {
            return null;
        }
        $decoded = json_decode($row->value_json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeHex(string $value): ?string
    {
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        if ($s[0] === '#') {
            $s = substr($s, 1);
        }
        if (strlen($s) === 3) {
            $s = $s[0].$s[0].$s[1].$s[1].$s[2].$s[2];
        }
        if (strlen($s) !== 6 || ! ctype_xdigit($s)) {
            return null;
        }

        return '#'.strtoupper($s);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function hexToRgb(string $hex): ?array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) !== 6) {
            return null;
        }
        $r = hexdec(substr($h, 0, 2));
        $g = hexdec(substr($h, 2, 2));
        $b = hexdec(substr($h, 4, 2));

        return [(int) $r, (int) $g, (int) $b];
    }

    /**
     * @return array{0: float, 1: float, 2: float} L*, a*, b*
     */
    private function rgbToLab(int $r, int $g, int $b): array
    {
        $r = $r / 255.0;
        $g = $g / 255.0;
        $b = $b / 255.0;

        $r = $r > 0.04045 ? (($r + 0.055) / 1.055) ** 2.4 : $r / 12.92;
        $g = $g > 0.04045 ? (($g + 0.055) / 1.055) ** 2.4 : $g / 12.92;
        $b = $b > 0.04045 ? (($b + 0.055) / 1.055) ** 2.4 : $b / 12.92;

        $x = ($r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375) / 0.95047;
        $y = ($r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750) / 1.00000;
        $z = ($r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041) / 1.08883;

        $x = $x > 0.008856 ? ($x ** (1 / 3)) : (7.787 * $x + 16 / 116);
        $y = $y > 0.008856 ? ($y ** (1 / 3)) : (7.787 * $y + 16 / 116);
        $z = $z > 0.008856 ? ($z ** (1 / 3)) : (7.787 * $z + 16 / 116);

        $l = (116.0 * $y) - 16.0;
        $a = 500.0 * ($x - $y);
        $bb = 200.0 * ($y - $z);

        return [$l, $a, $bb];
    }

    /**
     * CIE76 ΔE in Lab space.
     *
     * @param  array{0: float, 1: float, 2: float}  $a
     * @param  array{0: float, 1: float, 2: float}  $b
     */
    private function deltaE76(array $a, array $b): float
    {
        return sqrt(
            ($a[0] - $b[0]) ** 2
            + ($a[1] - $b[1]) ** 2
            + ($a[2] - $b[2]) ** 2
        );
    }

    /**
     * @param  list<string>  $brandHexes
     * @param  list<array{hex: string, coverage?: float}>  $assetColors
     */
    private function detectOppositePalette(array $brandHexes, array $assetColors): bool
    {
        $bh = $this->weightedAverageHue($brandHexes);
        $ah = $this->weightedAverageHue(array_column($assetColors, 'hex'));

        if ($bh === null || $ah === null) {
            return false;
        }

        $diff = abs($bh - $ah);
        if ($diff > 180) {
            $diff = 360 - $diff;
        }

        $bs = $this->maxSaturation($brandHexes);
        $as = $this->maxSaturation(array_column($assetColors, 'hex'));

        return $diff >= self::OPPOSITE_HUE_MIN
            && $bs >= self::SATURATION_CHROMATIC
            && $as >= self::SATURATION_CHROMATIC;
    }

    /**
     * @param  list<string>  $hexes
     */
    private function weightedAverageHue(array $hexes): ?float
    {
        $sx = 0.0;
        $sy = 0.0;
        foreach ($hexes as $hex) {
            if (! is_string($hex)) {
                continue;
            }
            $norm = $this->normalizeHex($hex);
            if ($norm === null) {
                continue;
            }
            $rgb = $this->hexToRgb($norm);
            if ($rgb === null) {
                continue;
            }
            $hsl = $this->rgbToHsl($rgb[0], $rgb[1], $rgb[2]);
            if ($hsl['s'] < self::SATURATION_CHROMATIC) {
                continue;
            }
            $rad = deg2rad($hsl['h']);
            $weight = $hsl['s'];
            $sx += cos($rad) * $weight;
            $sy += sin($rad) * $weight;
        }

        if (abs($sx) < 1e-6 && abs($sy) < 1e-6) {
            return null;
        }

        $hue = rad2deg(atan2($sy, $sx));

        return $hue < 0 ? $hue + 360 : $hue;
    }

    /**
     * @param  list<string>  $hexes
     */
    private function maxSaturation(array $hexes): float
    {
        $m = 0.0;
        foreach ($hexes as $hex) {
            if (! is_string($hex)) {
                continue;
            }
            $norm = $this->normalizeHex($hex);
            if ($norm === null) {
                continue;
            }
            $rgb = $this->hexToRgb($norm);
            if ($rgb === null) {
                continue;
            }
            $hsl = $this->rgbToHsl($rgb[0], $rgb[1], $rgb[2]);
            $m = max($m, $hsl['s']);
        }

        return $m;
    }

    /**
     * @return array{h: float, s: float, l: float} h in [0,360), s,l in [0,1]
     */
    private function rgbToHsl(int $r, int $g, int $b): array
    {
        $r = $r / 255;
        $g = $g / 255;
        $b = $b / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return ['h' => 0.0, 's' => 0.0, 'l' => $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r) {
            $h = 60 * (fmod((($g - $b) / $d), 6));
        } elseif ($max === $g) {
            $h = 60 * ((($b - $r) / $d) + 2);
        } else {
            $h = 60 * ((($r - $g) / $d) + 4);
        }
        if ($h < 0) {
            $h += 360;
        }

        return ['h' => $h, 's' => $s, 'l' => $l];
    }
}
