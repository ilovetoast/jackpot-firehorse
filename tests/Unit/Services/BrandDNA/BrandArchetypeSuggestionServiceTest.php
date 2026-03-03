<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandArchetypeSuggestionService;
use PHPUnit\Framework\TestCase;

/**
 * Brand Archetype Suggestion Service — unit tests.
 */
class BrandArchetypeSuggestionServiceTest extends TestCase
{
    public function test_outlaw_archetype_empty_traits_generates_suggestion(): void
    {
        $service = new BrandArchetypeSuggestionService;
        $draftPayload = [
            'personality' => [
                'primary_archetype' => 'Outlaw',
                'traits' => [],
            ],
        ];

        $result = $service->generate($draftPayload);

        $this->assertArrayHasKey('suggestions', $result);
        $traitsSuggestions = array_filter($result['suggestions'], fn ($s) => ($s['key'] ?? '') === 'SUG:expression.traits');
        $this->assertNotEmpty($traitsSuggestions, 'Outlaw with empty traits must generate traits suggestion');
        $suggestion = reset($traitsSuggestions);
        $this->assertSame('personality.traits', $suggestion['path']);
        $this->assertSame('merge', $suggestion['type']);
        $this->assertIsArray($suggestion['value']);
        $this->assertContains('rebellious', $suggestion['value']);
    }

    public function test_outlaw_archetype_four_traits_no_suggestion(): void
    {
        $service = new BrandArchetypeSuggestionService;
        $draftPayload = [
            'personality' => [
                'primary_archetype' => 'Outlaw',
                'traits' => ['bold', 'edgy', 'rebellious', 'unconventional'],
            ],
        ];

        $result = $service->generate($draftPayload);

        $traitsSuggestions = array_filter($result['suggestions'] ?? [], fn ($s) => ($s['key'] ?? '') === 'SUG:expression.traits');
        $this->assertEmpty($traitsSuggestions, 'Outlaw with 4+ traits must not generate traits suggestion');
    }

    public function test_no_archetype_no_suggestion(): void
    {
        $service = new BrandArchetypeSuggestionService;
        $draftPayload = [
            'personality' => [
                'traits' => [],
            ],
        ];

        $result = $service->generate($draftPayload);

        $this->assertEmpty($result['suggestions'] ?? [], 'No archetype must produce no suggestions');
    }

    public function test_empty_tone_keywords_generates_suggestion(): void
    {
        $service = new BrandArchetypeSuggestionService;
        $draftPayload = [
            'personality' => [
                'primary_archetype' => 'Hero',
                'traits' => ['courageous', 'strong', 'driven', 'honorable'],
            ],
            'scoring_rules' => [
                'tone_keywords' => [],
            ],
        ];

        $result = $service->generate($draftPayload);

        $toneSuggestions = array_filter($result['suggestions'] ?? [], fn ($s) => ($s['key'] ?? '') === 'SUG:expression.tone_keywords');
        $this->assertNotEmpty($toneSuggestions, 'Empty tone_keywords must generate suggestion');
        $suggestion = reset($toneSuggestions);
        $this->assertSame('scoring_rules.tone_keywords', $suggestion['path']);
        $this->assertContains('inspiring', $suggestion['value']);
    }

    public function test_archetype_reinforcement_generated_when_tone_low(): void
    {
        $service = new BrandArchetypeSuggestionService;
        $draftPayload = [
            'personality' => [
                'primary_archetype' => 'Creator',
                'traits' => ['imaginative', 'innovative', 'expressive'],
            ],
            'scoring_rules' => [
                'tone_keywords' => ['bold'],
            ],
        ];

        $result = $service->generate($draftPayload);

        $reinforceSuggestions = array_filter(
            $result['suggestions'] ?? [],
            fn ($s) => ($s['key'] ?? '') === 'SUG:expression.tone_keywords.reinforce'
        );
        $this->assertNotEmpty($reinforceSuggestions, 'Archetype with < 3 tone keywords must generate reinforcement suggestion');
        $suggestion = reset($reinforceSuggestions);
        $this->assertSame('merge', $suggestion['type']);
        $this->assertSame(0.75, $suggestion['confidence']);
        $this->assertStringContainsString('Strengthen tone alignment', $suggestion['reason']);
    }
}
