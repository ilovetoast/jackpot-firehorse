<?php

namespace Tests\Unit\BrandIntelligence;

use App\Services\BrandIntelligence\ReferenceSimilarityCalculator;
use PHPUnit\Framework\TestCase;

class ReferenceSimilarityCalculatorTest extends TestCase
{
    public function test_identity_fallback_score_weights(): void
    {
        $all = ReferenceSimilarityCalculator::identityFallbackScore([
            'has_logo' => true,
            'has_brand_colors' => true,
            'has_typography' => true,
        ]);
        $this->assertEqualsWithDelta(1.0, $all, 0.0001);

        $logoColors = ReferenceSimilarityCalculator::identityFallbackScore([
            'has_logo' => true,
            'has_brand_colors' => true,
            'has_typography' => false,
        ]);
        $this->assertEqualsWithDelta(0.8, $logoColors, 0.0001);

        $typoOnly = ReferenceSimilarityCalculator::identityFallbackScore([
            'has_logo' => false,
            'has_brand_colors' => false,
            'has_typography' => true,
        ]);
        $this->assertEqualsWithDelta(0.2, $typoOnly, 0.0001);

        $none = ReferenceSimilarityCalculator::identityFallbackScore([
            'has_logo' => false,
            'has_brand_colors' => false,
            'has_typography' => false,
        ]);
        $this->assertEqualsWithDelta(0.0, $none, 0.0001);
    }

    public function test_weighted_mean(): void
    {
        $m = ReferenceSimilarityCalculator::weightedMean([0.8, 0.6], [1.0, 1.0]);
        $this->assertEqualsWithDelta(0.7, $m, 0.0001);

        $m2 = ReferenceSimilarityCalculator::weightedMean([1.0, 0.0], [0.2, 0.8]);
        $this->assertEqualsWithDelta(0.2, $m2, 0.0001);
    }

    public function test_population_variance_low_vs_high(): void
    {
        $lowSpread = ReferenceSimilarityCalculator::populationVariance([0.81, 0.82, 0.80]);
        $this->assertLessThan(0.01, $lowSpread);

        $highSpread = ReferenceSimilarityCalculator::populationVariance([0.2, 0.9, 0.5]);
        $this->assertGreaterThan(0.01, $highSpread);
    }

    public function test_single_value_variance_is_zero(): void
    {
        $this->assertSame(0.0, ReferenceSimilarityCalculator::populationVariance([0.75]));
    }

    public function test_confidence_band_mapping(): void
    {
        $this->assertSame('high', ReferenceSimilarityCalculator::confidenceBand(true, false, 0.005));
        $this->assertSame('moderate', ReferenceSimilarityCalculator::confidenceBand(true, false, 0.05));
        $this->assertSame('low', ReferenceSimilarityCalculator::confidenceBand(false, true, 0.0));
    }

    public function test_stability_label(): void
    {
        $this->assertSame('consistent', ReferenceSimilarityCalculator::stabilityLabel(0.005));
        $this->assertSame('diverse', ReferenceSimilarityCalculator::stabilityLabel(0.02));
    }

    public function test_blend_identity_and_style(): void
    {
        $b = ReferenceSimilarityCalculator::blendIdentityAndStyle(1.0, 0.0, 0.3);
        $this->assertEqualsWithDelta(0.7, $b, 0.0001);
    }
}
