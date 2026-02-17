<?php

namespace App\Services\Color;

/**
 * Hue Cluster Service — perceptual hue clusters for filtering.
 *
 * Replaces dominant_color_bucket with semantic clusters.
 * Max 18 clusters. Assignment via ΔE to LAB centroids.
 * Used for filtering only; BrandComplianceService scoring remains LAB ΔE based.
 */
class HueClusterService
{
    /**
     * Canonical clusters (max 18).
     * Each: key, label, lab_centroid [L,a,b], display_hex, threshold_deltaE (default 18).
     * display_hex: Hard-coded representative colors for semantic clusters — do NOT compute dynamically.
     */
    protected const CLUSTERS = [
        ['key' => 'red', 'label' => 'Red', 'lab_centroid' => [53, 80, 67], 'display_hex' => '#E53935', 'threshold_deltaE' => 18, 'row_group' => 1],
        ['key' => 'orange', 'label' => 'Orange', 'lab_centroid' => [70, 45, 65], 'display_hex' => '#FB8C00', 'threshold_deltaE' => 18, 'row_group' => 1],
        ['key' => 'yellow', 'label' => 'Yellow', 'lab_centroid' => [95, -15, 90], 'display_hex' => '#FDD835', 'threshold_deltaE' => 18, 'row_group' => 1],
        ['key' => 'pink', 'label' => 'Pink', 'lab_centroid' => [75, 45, 5], 'display_hex' => '#D81B60', 'threshold_deltaE' => 18, 'row_group' => 1],
        ['key' => 'lime_green', 'label' => 'Lime Green', 'lab_centroid' => [85, -55, 75], 'display_hex' => '#9CCC65', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'green', 'label' => 'Green', 'lab_centroid' => [55, -45, 45], 'display_hex' => '#43A047', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'teal', 'label' => 'Teal', 'lab_centroid' => [50, -25, -15], 'display_hex' => '#00897B', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'cyan', 'label' => 'Cyan', 'lab_centroid' => [75, -35, -35], 'display_hex' => '#26C6DA', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'blue', 'label' => 'Blue', 'lab_centroid' => [45, 15, -55], 'display_hex' => '#1E88E5', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'indigo', 'label' => 'Indigo', 'lab_centroid' => [35, 25, -45], 'display_hex' => '#3949AB', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'purple', 'label' => 'Purple', 'lab_centroid' => [45, 55, -35], 'display_hex' => '#8E24AA', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'magenta', 'label' => 'Magenta', 'lab_centroid' => [55, 75, -25], 'display_hex' => '#D81B60', 'threshold_deltaE' => 18, 'row_group' => 2],
        ['key' => 'warm_brown', 'label' => 'Warm Brown', 'lab_centroid' => [45, 25, 45], 'display_hex' => '#8D6E63', 'threshold_deltaE' => 18, 'row_group' => 3],
        ['key' => 'cool_brown', 'label' => 'Cool Brown', 'lab_centroid' => [40, 10, 25], 'display_hex' => '#6D4C41', 'threshold_deltaE' => 18, 'row_group' => 3],
        ['key' => 'black', 'label' => 'Black', 'lab_centroid' => [15, 0, 0], 'display_hex' => '#212121', 'threshold_deltaE' => 18, 'row_group' => 4],
        ['key' => 'gray', 'label' => 'Gray', 'lab_centroid' => [55, 0, 0], 'display_hex' => '#9E9E9E', 'threshold_deltaE' => 18, 'row_group' => 4],
        ['key' => 'white', 'label' => 'White', 'lab_centroid' => [95, 0, 0], 'display_hex' => '#FAFAFA', 'threshold_deltaE' => 18, 'row_group' => 4],
        ['key' => 'neutral', 'label' => 'Neutral', 'lab_centroid' => [65, 2, 5], 'display_hex' => '#BDBDBD', 'threshold_deltaE' => 18, 'row_group' => 4],
    ];

    /**
     * Get all clusters.
     *
     * @return array<int, array{key: string, label: string, lab_centroid: array, display_hex: string, threshold_deltaE: int}>
     */
    public function getClusters(): array
    {
        return self::CLUSTERS;
    }

    /**
     * Assign cluster from hex. Converts hex → LAB, computes ΔE to each centroid, returns key if within threshold.
     *
     * @param string $hex Hex color (#RRGGBB or RRGGBB)
     * @return string|null Cluster key or null if no match within threshold
     */
    public function assignClusterFromHex(string $hex): ?string
    {
        $lab = $this->hexToLab($hex);
        if ($lab === null) {
            return null;
        }

        return $this->assignClusterFromLab($lab);
    }

    /**
     * Assign cluster from LAB. Computes ΔE to each centroid, returns key if within threshold.
     *
     * @param array{0: float, 1: float, 2: float} $lab [L, a, b]
     * @return string|null Cluster key or null if no match within threshold
     */
    public function assignClusterFromLab(array $lab): ?string
    {
        if (count($lab) < 3) {
            return null;
        }

        $bestKey = null;
        $bestDeltaE = PHP_FLOAT_MAX;

        foreach (self::CLUSTERS as $cluster) {
            $centroid = $cluster['lab_centroid'];
            $threshold = $cluster['threshold_deltaE'] ?? 18;
            $deltaE = $this->deltaE($lab, $centroid);

            if ($deltaE <= $threshold && $deltaE < $bestDeltaE) {
                $bestDeltaE = $deltaE;
                $bestKey = $cluster['key'];
            }
        }

        return $bestKey;
    }

    /**
     * Get cluster metadata by key.
     *
     * @param string $key Cluster key
     * @return array{key: string, label: string, lab_centroid: array, display_hex: string, threshold_deltaE: int}|null
     */
    public function getClusterMeta(string $key): ?array
    {
        foreach (self::CLUSTERS as $cluster) {
            if ($cluster['key'] === $key) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * Convert hex to LAB (D65).
     *
     * @return array{0: float, 1: float, 2: float}|null [L, a, b] or null if invalid
     */
    protected function hexToLab(string $hex): ?array
    {
        $rgb = $this->hexToRgb($hex);
        if ($rgb === null) {
            return null;
        }

        [$r, $g, $b] = $rgb;
        $xyz = $this->rgbToXyz($r, $g, $b);

        return $this->xyzToLab($xyz[0], $xyz[1], $xyz[2]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null [r, g, b] 0-255
     */
    protected function hexToRgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * sRGB to XYZ (D65).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    protected function rgbToXyz(int $r, int $g, int $b): array
    {
        $rs = $r / 255.0;
        $gs = $g / 255.0;
        $bs = $b / 255.0;

        $rs = $rs <= 0.04045 ? $rs / 12.92 : pow(($rs + 0.055) / 1.055, 2.4);
        $gs = $gs <= 0.04045 ? $gs / 12.92 : pow(($gs + 0.055) / 1.055, 2.4);
        $bs = $bs <= 0.04045 ? $bs / 12.92 : pow(($bs + 0.055) / 1.055, 2.4);

        $x = $rs * 0.4124564 + $gs * 0.3575761 + $bs * 0.1804375;
        $y = $rs * 0.2126729 + $gs * 0.7151522 + $bs * 0.0721750;
        $z = $rs * 0.0193339 + $gs * 0.1191920 + $bs * 0.9503041;

        return [$x * 100, $y * 100, $z * 100];
    }

    /**
     * XYZ to LAB (D65 reference white).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    protected function xyzToLab(float $x, float $y, float $z): array
    {
        $xn = 95.047;
        $yn = 100.000;
        $zn = 108.883;

        $fx = $x / $xn > 0.008856 ? pow($x / $xn, 1 / 3) : (7.787 * $x / $xn + 16 / 116);
        $fy = $y / $yn > 0.008856 ? pow($y / $yn, 1 / 3) : (7.787 * $y / $yn + 16 / 116);
        $fz = $z / $zn > 0.008856 ? pow($z / $zn, 1 / 3) : (7.787 * $z / $zn + 16 / 116);

        $L = 116 * $fy - 16;
        $a = 500 * ($fx - $fy);
        $b = 200 * ($fy - $fz);

        return [$L, $a, $b];
    }

    /**
     * CIE76 delta E between two LAB colors.
     */
    protected function deltaE(array $lab1, array $lab2): float
    {
        return sqrt(
            pow($lab1[0] - $lab2[0], 2) +
            pow($lab1[1] - $lab2[1], 2) +
            pow($lab1[2] - $lab2[2], 2)
        );
    }
}
