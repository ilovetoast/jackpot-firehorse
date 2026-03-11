<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\FieldCandidateValidationService;
use Tests\TestCase;

class FieldCandidateValidationServiceTest extends TestCase
{
    protected FieldCandidateValidationService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FieldCandidateValidationService;
    }

    public function test_font_validator_rejects_sentence_fragments(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => '50 PHOTOGRAPHY premier fitness accessory brand',
            'confidence' => 0.80,
            'page' => 12,
            'page_type' => 'typography',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('invalid_font_candidate', $result['reason']);
    }

    public function test_font_validator_accepts_valid_font_family_names(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => 'Montserrat',
            'confidence' => 0.85,
            'page' => 12,
            'page_type' => 'typography',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('Montserrat', $result['normalized_value']);
    }

    public function test_font_validator_accepts_helvetica_neue(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => 'Helvetica Neue',
            'confidence' => 0.9,
        ]);

        $this->assertTrue($result['accepted']);
    }

    public function test_tone_keyword_validator_rejects_of_voice(): void
    {
        $result = $this->validator->validate([
            'path' => 'scoring_rules.tone_keywords',
            'value' => ['OF VOICE'],
            'confidence' => 0.7,
            'page' => 8,
            'page_type' => 'brand_voice',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('label_fragment_not_keywords', $result['reason']);
    }

    public function test_tone_keyword_validator_accepts_valid_keywords(): void
    {
        $result = $this->validator->validate([
            'path' => 'personality.tone_keywords',
            'value' => ['bold', 'confident', 'playful'],
            'confidence' => 0.8,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertIsArray($result['normalized_value']);
    }

    public function test_positioning_validator_rejects_within_a_category(): void
    {
        $result = $this->validator->validate([
            'path' => 'identity.positioning',
            'value' => 'CONSUMER within a category.',
            'confidence' => 0.75,
            'page' => 5,
            'page_type' => 'positioning',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('fragmentary_narrative', $result['reason']);
    }

    public function test_positioning_validator_accepts_complete_statement(): void
    {
        $result = $this->validator->validate([
            'path' => 'identity.positioning',
            'value' => 'We are the leading brand in premium fitness accessories for health-conscious consumers.',
            'confidence' => 0.85,
        ]);

        $this->assertTrue($result['accepted']);
    }

    public function test_archetype_validator_accepts_known_archetypes_only(): void
    {
        $result = $this->validator->validate([
            'path' => 'personality.primary_archetype',
            'value' => 'Hero',
            'confidence' => 0.9,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame('Hero', $result['normalized_value']);
    }

    public function test_archetype_validator_rejects_unknown_archetype(): void
    {
        $result = $this->validator->validate([
            'path' => 'personality.primary_archetype',
            'value' => 'Innovator',
            'confidence' => 0.8,
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('archetype_not_in_list', $result['reason']);
    }

    public function test_color_validator_accepts_valid_hex(): void
    {
        $result = $this->validator->validate([
            'path' => 'visual.primary_colors',
            'value' => ['#003388', '#FF6600'],
            'confidence' => 0.95,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertNotEmpty($result['normalized_value']);
    }

    public function test_color_validator_rejects_invalid_tokens(): void
    {
        $result = $this->validator->validate([
            'path' => 'scoring_rules.allowed_color_palette',
            'value' => ['not a color', 'red'],
            'confidence' => 0.5,
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('no_valid_hex_colors', $result['reason']);
    }

    public function test_is_likely_label_text(): void
    {
        $this->assertTrue($this->validator->isLikelyLabelText('OF VOICE'));
        $this->assertTrue($this->validator->isLikelyLabelText('TYPOGRAPHY'));
        $this->assertFalse($this->validator->isLikelyLabelText('Montserrat'));
    }

    public function test_is_fragmentary_narrative(): void
    {
        $this->assertTrue($this->validator->isFragmentaryNarrative('within a category'));
        $this->assertTrue($this->validator->isFragmentaryNarrative('for the consumer'));
        $this->assertFalse($this->validator->isFragmentaryNarrative('We are the leading brand in premium fitness.'));
    }

    public function test_validate_many_filters_rejected(): void
    {
        $candidates = [
            ['path' => 'typography.primary_font', 'value' => 'Montserrat', 'confidence' => 0.9],
            ['path' => 'typography.primary_font', 'value' => '50 PHOTOGRAPHY premier fitness', 'confidence' => 0.8],
        ];

        [$accepted, $rejected] = $this->validator->validateMany($candidates);

        $this->assertCount(1, $accepted);
        $this->assertSame('Montserrat', $accepted[0]['value']);
        $this->assertCount(1, $rejected);
        $this->assertSame('invalid_font_candidate', $rejected[0]['reason']);
    }
}
