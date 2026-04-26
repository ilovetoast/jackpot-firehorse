<?php

namespace App\Studio\Animation\Providers\Kling;

/**
 * Maps app aspect keys to Kling native image2video `aspect_ratio` values.
 *
 * The UI supports 4:5 and 3:4 (and “match layer” can resolve to them). The previous
 * implementation dropped those to 1:1, so the first frame and the requested ratio
 * disagreed, which can heavily distort the output.
 */
final class KlingNativeAspectMapper
{
    private const PASS_THROUGH = ['16:9', '9:16', '1:1', '4:5', '3:4'];

    public static function toKlingRequestValue(string $aspectRatio): string
    {
        $a = str_replace('x', ':', trim($aspectRatio));
        if (in_array($a, self::PASS_THROUGH, true)) {
            return $a;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)$/', $a, $m)) {
            $w = (float) $m[1];
            $h = (float) $m[2];
            if ($w > 0 && $h > 0) {
                return self::nearestKlingThree($w / $h);
            }
        }

        return '1:1';
    }

    /**
     * @return '16:9'|'9:16'|'1:1'
     */
    private static function nearestKlingThree(float $r): string
    {
        $candidates = [
            '16:9' => 16.0 / 9.0,
            '9:16' => 9.0 / 16.0,
            '1:1' => 1.0,
        ];
        $best = '1:1';
        $bestD = PHP_FLOAT_MAX;
        foreach ($candidates as $key => $kr) {
            $d = abs($r - $kr);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $key;
            }
        }

        return $best;
    }
}
