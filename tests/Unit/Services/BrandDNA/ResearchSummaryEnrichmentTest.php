<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandResearchReportBuilder;
use App\Services\BrandDNA\SuggestionViewTransformer;
use Tests\TestCase;

class ResearchSummaryEnrichmentTest extends TestCase
{
    public function test_explicit_archetype_enriches_recommended_archetypes_when_suggestions_empty(): void
    {
        $suggestions = ['recommended_archetypes' => []];
        $snapshot = [
            'evidence_map' => [
                'personality.primary_archetype' => [
                    'final_value' => 'Ruler',
                    'winning_source' => 'pdf_visual',
                    'winning_reason' => 'explicit_archetype_match',
                ],
            ],
        ];

        $result = SuggestionViewTransformer::forFrontend($suggestions, $snapshot);

        $this->assertNotEmpty($result['recommended_archetypes']);
        $this->assertSame('Ruler', $result['recommended_archetypes'][0]['label'] ?? $result['recommended_archetypes'][0]);
    }

    public function test_explicit_colors_enrich_detected_confidently(): void
    {
        $snapshot = [
            'primary_colors' => ['#C10230', '#101820'],
            'explicit_signals' => ['colors_declared' => true],
            'evidence_map' => [
                'visual.primary_colors' => [
                    'winning_source' => 'pdf_visual',
                    'winning_page' => 12,
                ],
            ],
        ];
        $suggestions = [];
        $coherence = ['overall' => ['confidence' => 80]];
        $alignment = [];

        $report = BrandResearchReportBuilder::build($snapshot, $suggestions, $coherence, $alignment, []);

        $this->assertArrayHasKey('detected_confidently', $report);
        $this->assertArrayHasKey('primary_colors', $report['detected_confidently']);
        $this->assertCount(2, $report['detected_confidently']['primary_colors']);
    }

    public function test_detected_confidently_includes_archetype_when_explicit(): void
    {
        $snapshot = [
            'evidence_map' => [
                'personality.primary_archetype' => [
                    'final_value' => 'Ruler',
                    'winning_reason' => 'explicit_archetype_match',
                ],
            ],
            'explicit_signals' => ['archetype_declared' => true],
        ];
        $suggestions = [];
        $coherence = ['overall' => ['confidence' => 90]];
        $alignment = [];

        $report = BrandResearchReportBuilder::build($snapshot, $suggestions, $coherence, $alignment, []);

        $this->assertSame('Ruler', $report['detected_confidently']['archetype'] ?? null);
    }

    public function test_narrative_field_debug_structure(): void
    {
        $snapshot = [
            'narrative_field_debug' => [
                'identity.positioning' => [
                    'candidate_pages' => [8, 9],
                    'accepted' => [],
                    'rejected' => [
                        ['page' => 8, 'value' => 'within a category', 'reason' => 'fragmentary_narrative'],
                    ],
                ],
            ],
        ];

        $this->assertArrayHasKey('identity.positioning', $snapshot['narrative_field_debug']);
        $debug = $snapshot['narrative_field_debug']['identity.positioning'];
        $this->assertSame([8, 9], $debug['candidate_pages']);
        $this->assertEmpty($debug['accepted']);
        $this->assertCount(1, $debug['rejected']);
        $this->assertSame('fragmentary_narrative', $debug['rejected'][0]['reason']);
    }
}
