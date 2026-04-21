<?php

namespace App\Services\BrandIntelligence;

use App\Models\Brand;
use App\Models\BrandAdReference;
use Illuminate\Support\Collection;

/**
 * Aggregate extracted signals across a brand's ad-reference gallery into
 * soft "hints" the recipe engine can apply on top of inference but below
 * explicit user overrides.
 *
 * Output shape (mirrors the TS `BrandAdReferenceHints` in
 * resources/js/Pages/Editor/recipes/brandAdStyle.ts):
 * {
 *   sample_count: int,                              // references with signals
 *   avg_brightness: float 0..1 | null,
 *   avg_saturation: float 0..1 | null,
 *   dominant_hue_bucket: 'warm'|'cool'|'neutral'|null,
 *   palette_mix: {                                  // shares, sum to ~1
 *     monochrome: float, duochrome: float, polychrome: float
 *   } | null,
 *   suggestions: {                                  // coarse booleans
 *     prefers_dark_backgrounds: bool,
 *     prefers_light_backgrounds: bool,
 *     prefers_vibrant: bool,
 *     prefers_muted: bool,
 *     prefers_minimal_palette: bool,
 *     prefers_rich_palette: bool,
 *   } | null,
 * }
 *
 * Thresholds are deliberately generous — hints should only fire when the
 * gallery's signal is unambiguous. If a brand uploads 3 references split
 * evenly between dark and light, we want `prefers_dark_backgrounds` to
 * stay `false` rather than flip on a 2-of-3 majority. The recipe engine
 * already has good defaults; hints are there for the clear cases.
 */
class BrandAdReferenceHintsService
{
    /**
     * Minimum references with successful signals before we emit hints.
     * Below this, the aggregate is too noisy to act on.
     */
    public const MIN_SAMPLES = 2;

    public function forBrand(Brand $brand): array
    {
        $refs = BrandAdReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('signals')
            ->get();

        return $this->aggregate($refs);
    }

    /**
     * Aggregate a Collection of BrandAdReference rows (with `signals`
     * populated). Pure function — no DB, no IO — so it's easy to test.
     *
     * @param  Collection<int, BrandAdReference>  $refs
     */
    public function aggregate(Collection $refs): array
    {
        $samples = $refs->filter(fn ($r) => is_array($r->signals ?? null) && ! empty($r->signals))->values();
        $n = $samples->count();

        $base = [
            'sample_count' => $n,
            'avg_brightness' => null,
            'avg_saturation' => null,
            'dominant_hue_bucket' => null,
            'palette_mix' => null,
            'suggestions' => null,
        ];

        if ($n === 0) {
            return $base;
        }

        $avgBrightness = $samples->avg(fn ($r) => (float) ($r->signals['avg_brightness'] ?? 0.5));
        $avgSaturation = $samples->avg(fn ($r) => (float) ($r->signals['avg_saturation'] ?? 0.5));

        // Hue bucket: mode of the reference-level choices. Ties resolve
        // to 'neutral' because a split signal shouldn't dictate direction.
        $hueCounts = ['warm' => 0, 'cool' => 0, 'neutral' => 0];
        foreach ($samples as $r) {
            $b = $r->signals['dominant_hue_bucket'] ?? 'neutral';
            if (isset($hueCounts[$b])) $hueCounts[$b]++;
        }
        arsort($hueCounts);
        $first = array_key_first($hueCounts);
        $firstCount = $hueCounts[$first];
        $tied = count(array_filter($hueCounts, fn ($c) => $c === $firstCount)) > 1;
        $dominantHue = $tied ? 'neutral' : $first;

        $paletteCounts = ['monochrome' => 0, 'duochrome' => 0, 'polychrome' => 0];
        foreach ($samples as $r) {
            $p = $r->signals['palette_kind'] ?? 'polychrome';
            if (isset($paletteCounts[$p])) $paletteCounts[$p]++;
        }
        $paletteMix = [
            'monochrome' => round($paletteCounts['monochrome'] / $n, 3),
            'duochrome' => round($paletteCounts['duochrome'] / $n, 3),
            'polychrome' => round($paletteCounts['polychrome'] / $n, 3),
        ];

        $result = [
            'sample_count' => $n,
            'avg_brightness' => round((float) $avgBrightness, 4),
            'avg_saturation' => round((float) $avgSaturation, 4),
            'dominant_hue_bucket' => $dominantHue,
            'palette_mix' => $paletteMix,
            'suggestions' => null,
        ];

        if ($n >= self::MIN_SAMPLES) {
            $result['suggestions'] = [
                // Brightness thresholds: <=0.35 dark, >=0.65 light. The
                // midrange stays silent so photography-heavy refs with
                // balanced exposures don't trigger a preference.
                'prefers_dark_backgrounds' => $avgBrightness <= 0.35,
                'prefers_light_backgrounds' => $avgBrightness >= 0.65,
                'prefers_vibrant' => $avgSaturation >= 0.55,
                'prefers_muted' => $avgSaturation <= 0.25,
                // Graphic palette signal requires majority to be simple.
                'prefers_minimal_palette' => ($paletteMix['monochrome'] + $paletteMix['duochrome']) >= 0.6,
                'prefers_rich_palette' => $paletteMix['polychrome'] >= 0.7,
            ];
        }

        return $result;
    }
}
