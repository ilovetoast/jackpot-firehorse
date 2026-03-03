<?php

namespace Tests\Unit\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\Extraction\ExtractionSuggestionService;
use PHPUnit\Framework\TestCase;

class ExtractionSuggestionServiceTest extends TestCase
{
    public function test_explicit_archetype_generates_high_confidence_suggestion(): void
    {
        $service = new ExtractionSuggestionService;
        $extraction = [
            'personality' => ['primary_archetype' => 'Creator'],
            'explicit_signals' => ['archetype_declared' => true],
            'identity' => [],
            'visual' => [],
        ];

        $suggestions = $service->generateSuggestions($extraction);

        $archetypeSuggestion = collect($suggestions)->firstWhere('key', 'SUG:personality.primary_archetype');
        $this->assertNotNull($archetypeSuggestion);
        $this->assertSame(0.95, $archetypeSuggestion['confidence']);
        $this->assertSame('Creator', $archetypeSuggestion['value']);
        $this->assertStringContainsString('explicitly', $archetypeSuggestion['reason']);
    }

    public function test_non_explicit_archetype_does_not_generate_suggestion(): void
    {
        $service = new ExtractionSuggestionService;
        $extraction = [
            'personality' => ['primary_archetype' => 'Creator'],
            'explicit_signals' => ['archetype_declared' => false],
            'identity' => [],
            'visual' => [],
        ];

        $suggestions = $service->generateSuggestions($extraction);

        $archetypeSuggestion = collect($suggestions)->firstWhere('key', 'SUG:personality.primary_archetype');
        $this->assertNull($archetypeSuggestion);
    }

    public function test_conflict_generates_informational_suggestion(): void
    {
        $service = new ExtractionSuggestionService;
        $extraction = [
            'personality' => ['primary_archetype' => 'Ruler'],
            'explicit_signals' => [],
            'identity' => [],
            'visual' => [],
        ];
        $conflicts = [
            [
                'field' => 'personality.primary_archetype',
                'candidates' => [
                    ['value' => 'Ruler', 'source' => 'pdf', 'weight' => 1.0],
                    ['value' => 'Hero', 'source' => 'website', 'weight' => 0.7],
                ],
                'recommended' => 'Ruler',
                'recommended_weight' => 1.0,
            ],
        ];

        $suggestions = $service->generateSuggestions($extraction, $conflicts);

        $conflictSuggestion = collect($suggestions)->firstWhere('key', 'SUG:conflict.personality.primary_archetype');
        $this->assertNotNull($conflictSuggestion);
        $this->assertSame('informational', $conflictSuggestion['type']);
        $this->assertSame(1.0, $conflictSuggestion['confidence']);
        $this->assertStringContainsString('disagree', $conflictSuggestion['reason']);
    }
}
