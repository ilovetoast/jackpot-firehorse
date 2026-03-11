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

    public function test_low_section_quality_caps_auto_apply(): void
    {
        $service = new ExtractionSuggestionService;
        $extraction = [
            'personality' => [],
            'explicit_signals' => ['positioning_declared' => true],
            'identity' => ['positioning' => 'We are the leading brand in our category with authentic products.'],
            'visual' => [],
            'section_sources' => ['identity.positioning' => 'BRAND POSITIONING'],
            '_extraction_debug' => [
                'section_metadata' => [
                    ['title' => 'BRAND POSITIONING', 'source' => 'heuristic', 'confidence' => 0.7, 'quality_score' => 0.5],
                ],
                'section_quality_by_path' => ['identity.positioning' => 0.5],
            ],
        ];

        $suggestions = $service->generateSuggestions($extraction);

        $posSuggestion = collect($suggestions)->firstWhere('key', 'SUG:identity.positioning');
        $this->assertNotNull($posSuggestion);
        $this->assertFalse($posSuggestion['auto_apply'], 'Low section quality must not auto-apply');
    }
}
