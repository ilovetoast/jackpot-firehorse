<?php

namespace Tests\Unit\BrandIntelligence;

use App\Enums\BrandAlignmentState;
use App\Services\BrandIntelligence\ReferenceSimilarityCalculator;
use PHPUnit\Framework\TestCase;

class ContextAwareBlendTest extends TestCase
{
    public function test_blend_identity_and_style_weights(): void
    {
        $b = ReferenceSimilarityCalculator::blendIdentityAndStyle(0.8, 0.2, ReferenceSimilarityCalculator::DEFAULT_STYLE_WEIGHT);
        $this->assertEqualsWithDelta(0.8 * 0.7 + 0.2 * 0.3, $b, 0.0001);

        $low = ReferenceSimilarityCalculator::blendIdentityAndStyle(0.8, 0.2, ReferenceSimilarityCalculator::LOW_REF_STYLE_WEIGHT);
        $this->assertEqualsWithDelta(0.8 * 0.9 + 0.2 * 0.1, $low, 0.0001);
    }

    public function test_variance_boost_increases_style_channel(): void
    {
        $this->assertSame(0.0, ReferenceSimilarityCalculator::varianceStyleBoost(0.005));
        $this->assertGreaterThan(0.0, ReferenceSimilarityCalculator::varianceStyleBoost(0.2));
    }

    public function test_strong_identity_weak_style_maps_to_partial_not_off_brand_threshold(): void
    {
        // Identity 0.8, style 0.25 → combined default weights: 0.8*0.7 + 0.25*0.3 = 0.635 → partial band
        $combined = ReferenceSimilarityCalculator::blendIdentityAndStyle(0.8, 0.25, ReferenceSimilarityCalculator::DEFAULT_STYLE_WEIGHT);
        $state = BrandAlignmentState::fromNormalizedScore($combined);
        $this->assertSame(BrandAlignmentState::PARTIAL_ALIGNMENT, $state);
    }

    /**
     * Dark outdoor ad with strong in-brand identity but low style match vs studio refs:
     * blended score should not land in OFF_BRAND when identity is high and style is moderate-low.
     */
    public function test_dark_outdoor_strong_identity_not_off_brand_after_blend(): void
    {
        $identity = 0.85;
        $style = 0.28;
        $combined = ReferenceSimilarityCalculator::blendIdentityAndStyle($identity, $style, ReferenceSimilarityCalculator::DEFAULT_STYLE_WEIGHT);
        $this->assertGreaterThanOrEqual(0.4, $combined);
        $state = BrandAlignmentState::fromNormalizedScore($combined);
        $this->assertNotSame(BrandAlignmentState::OFF_BRAND, $state);
    }
}
