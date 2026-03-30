<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandDnaPayloadNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Brand DNA Payload Normalizer — unit tests.
 */
class BrandDnaPayloadNormalizerTest extends TestCase
{
    public function test_normalizer_adds_defaults_for_new_keys_without_clobbering_existing(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'identity' => [
                'beliefs' => ['Quality first'],
                'values' => ['Integrity', 'Honesty'],
            ],
        ];

        $result = $normalizer->normalize($payload);

        // Existing values preserved
        $this->assertSame(['Quality first'], $result['identity']['beliefs']);
        $this->assertSame(['Integrity', 'Honesty'], $result['identity']['values']);

        // New keys get defaults
        $this->assertArrayHasKey('personality', $result);
        $this->assertNull($result['personality']['primary_archetype']);
        $this->assertSame([], $result['personality']['candidate_archetypes']);
        $this->assertSame([], $result['personality']['rejected_archetypes']);

        $this->assertArrayHasKey('visual', $result);
        $this->assertNull($result['visual']['visual_density']);
        $this->assertSame([], $result['visual']['textures']);
    }

    public function test_normalizer_preserves_existing_nested_values(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'personality' => [
                'primary_archetype' => 'Creator',
                'candidate_archetypes' => ['Explorer', 'Sage'],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame('Creator', $result['personality']['primary_archetype']);
        $this->assertSame(['Explorer', 'Sage'], $result['personality']['candidate_archetypes']);
        $this->assertSame([], $result['personality']['rejected_archetypes']);
    }

    public function test_normalizer_syncs_personality_tone_keywords_to_scoring_rules_when_empty(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'personality' => [
                'tone_keywords' => ['warm', 'friendly'],
            ],
            'scoring_rules' => [
                'tone_keywords' => [],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame(['warm', 'friendly'], $result['scoring_rules']['tone_keywords'], 'Legacy personality.tone_keywords should sync to canonical scoring_rules.tone_keywords');
    }

    public function test_normalizer_scoring_rules_tone_keywords_wins_when_both_present(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'personality' => [
                'tone_keywords' => ['legacy'],
            ],
            'scoring_rules' => [
                'tone_keywords' => ['canonical'],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame(['canonical'], $result['scoring_rules']['tone_keywords'], 'scoring_rules.tone_keywords is canonical and wins when both present');
    }

    public function test_normalizer_adds_presentation_overrides_defaults(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $result = $normalizer->normalize([]);

        $this->assertArrayHasKey('presentation_overrides', $result);
        $this->assertSame([], $result['presentation_overrides']['global']);
        $this->assertSame([], $result['presentation_overrides']['sections']);
    }

    public function test_normalizer_adds_presentation_content_defaults(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $result = $normalizer->normalize([]);

        $this->assertArrayHasKey('presentation_content', $result);
        $this->assertSame([], $result['presentation_content']);
    }

    public function test_normalizer_preserves_existing_presentation_overrides(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'presentation_overrides' => [
                'global' => ['spacing' => 'generous'],
                'sections' => [
                    'sec-hero' => ['visible' => false],
                ],
            ],
            'presentation_content' => [
                'sec-purpose' => ['mission_html' => '<p>Custom mission</p>'],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame('generous', $result['presentation_overrides']['global']['spacing']);
        $this->assertFalse($result['presentation_overrides']['sections']['sec-hero']['visible']);
        $this->assertSame('<p>Custom mission</p>', $result['presentation_content']['sec-purpose']['mission_html']);
    }
}
