<?php

namespace App\Services\BrandIntelligence;

/**
 * Pure helpers for EBI reference similarity (testable without DB).
 */
final class ReferenceSimilarityCalculator
{
    public const MIN_STYLE_REFERENCES_FOR_EMBEDDING = 3;

    public const NOISE_SIMILARITY_FLOOR = 0.2;

    public const TOP_N = 5;

    public const VARIANCE_STABILITY_THRESHOLD = 0.01;

    /** Minimum context-matched style references before we trust style weight fully. */
    public const MIN_CONTEXT_MATCHED_REFS = 3;

    /** Default blend: identity vs style (see Brand Intelligence context-aware scoring). */
    public const DEFAULT_STYLE_WEIGHT = 0.3;

    /** When context pool is thin, rely more on identity signals. */
    public const LOW_REF_STYLE_WEIGHT = 0.1;

    /**
     * @param  array<string, bool>  $signalBreakdown  has_logo, has_brand_colors, has_typography
     */
    public static function identityFallbackScore(array $signalBreakdown): float
    {
        $logo = ($signalBreakdown['has_logo'] ?? false) === true ? 1.0 : 0.0;
        $colors = ($signalBreakdown['has_brand_colors'] ?? false) === true ? 1.0 : 0.0;
        $typo = ($signalBreakdown['has_typography'] ?? false) === true ? 1.0 : 0.0;

        return max(0.0, min(1.0, $logo * 0.4 + $colors * 0.4 + $typo * 0.2));
    }

    /**
     * Population variance (biased, N denominator) — matches existing engine behavior.
     *
     * @param  list<float>  $values
     */
    public static function populationVariance(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return $sum / $n;
    }

    public static function stabilityLabel(float $variance): string
    {
        return $variance < self::VARIANCE_STABILITY_THRESHOLD ? 'consistent' : 'diverse';
    }

    /**
     * @param  list<float>  $similarities  Top-N cosine similarities (already clamped 0–1)
     * @param  list<float>  $weights       Same length, per-reference weights
     */
    public static function weightedMean(array $similarities, array $weights): float
    {
        $sumW = array_sum($weights);
        if ($sumW < 1e-10 || count($similarities) !== count($weights)) {
            return 0.0;
        }
        $acc = 0.0;
        foreach ($similarities as $i => $s) {
            $acc += $s * ($weights[$i] ?? 0.0);
        }

        return $acc / $sumW;
    }

    public static function confidenceBand(bool $referenceSimilarityUsed, bool $fallbackUsed, float $variance): string
    {
        if ($fallbackUsed || ! $referenceSimilarityUsed) {
            return 'low';
        }

        return $variance < self::VARIANCE_STABILITY_THRESHOLD ? 'high' : 'moderate';
    }

    /**
     * Map band to numeric confidence for persistence (merged with other engine factors).
     */
    public static function bandToNumericConfidence(string $band): float
    {
        return match ($band) {
            'high' => 0.88,
            'moderate' => 0.65,
            'low' => 0.42,
            default => 0.45,
        };
    }

    /**
     * Blend identity (logo/colors/typography signal strength) with style (embedding) similarity.
     *
     * @param  float  $styleWeight  Fraction applied to style (e.g. 0.3); identity gets (1 - styleWeight).
     */
    public static function blendIdentityAndStyle(float $identity, float $style, float $styleWeight): float
    {
        $styleWeight = max(0.0, min(1.0, $styleWeight));
        $identityWeight = 1.0 - $styleWeight;

        return max(0.0, min(1.0, $identity * $identityWeight + $style * $styleWeight));
    }

    /**
     * When references disagree (high variance), slightly widen the acceptable style channel before blending.
     */
    public static function varianceStyleBoost(float $variance): float
    {
        if ($variance <= self::VARIANCE_STABILITY_THRESHOLD) {
            return 0.0;
        }

        return min(0.12, ($variance - (float) self::VARIANCE_STABILITY_THRESHOLD) * 1.5);
    }
}
