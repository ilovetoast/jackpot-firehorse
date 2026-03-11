<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\ExtractionEvidenceMapBuilder;
use Tests\TestCase;

class ExtractionEvidenceMapBuilderTest extends TestCase
{
    public function test_builds_evidence_map_for_single_source(): void
    {
        $builder = new ExtractionEvidenceMapBuilder();
        $raw = [
            [
                'identity' => ['positioning' => 'We are the leading brand.'],
                'sources' => ['pdf' => ['extracted' => true, 'section_aware' => true]],
                'section_sources' => ['identity.positioning' => 'BRAND POSITIONING'],
                '_extraction_debug' => ['section_quality_by_path' => ['identity.positioning' => 0.85]],
            ],
        ];
        $merged = [
            'identity' => ['positioning' => 'We are the leading brand.'],
            'personality' => [],
            'visual' => [],
        ];

        $evidence = $builder->build($raw, $merged);

        $this->assertArrayHasKey('identity.positioning', $evidence);
        $this->assertSame('pdf_text', $evidence['identity.positioning']['winning_source']);
        $this->assertSame('We are the leading brand.', $evidence['identity.positioning']['final_value']);
        $this->assertNotEmpty($evidence['identity.positioning']['candidates']);
    }

    public function test_includes_winning_reason(): void
    {
        $builder = new ExtractionEvidenceMapBuilder();
        $raw = [
            [
                'personality' => ['primary_archetype' => 'Hero'],
                'sources' => ['pdf' => ['extracted' => true]],
                'page_classifications_json' => [['page_type' => 'archetype']],
                'page_extractions_json' => [
                    ['page' => 7, 'page_type' => 'archetype', 'extractions' => [['path' => 'personality.primary_archetype', 'value' => 'Hero']]],
                ],
            ],
        ];
        $merged = [
            'identity' => [],
            'personality' => ['primary_archetype' => 'Hero'],
            'visual' => [],
        ];

        $evidence = $builder->build($raw, $merged);

        $this->assertArrayHasKey('personality.primary_archetype', $evidence);
        $this->assertSame('pdf_visual', $evidence['personality.primary_archetype']['winning_source']);
        $this->assertArrayHasKey('winning_reason', $evidence['personality.primary_archetype']);
    }

    public function test_visual_winner_retains_winning_page_and_page_type(): void
    {
        $builder = new ExtractionEvidenceMapBuilder();
        $raw = [
            [
                'personality' => ['primary_archetype' => 'Creator'],
                'sources' => ['pdf' => ['extracted' => true]],
                'page_classifications_json' => [['page_type' => 'archetype']],
                'page_extractions_json' => [
                    ['page' => 11, 'page_type' => 'archetype', 'extractions' => [
                        ['path' => 'personality.primary_archetype', 'value' => 'Creator', 'page' => 11, 'page_type' => 'archetype'],
                    ]],
                ],
            ],
        ];
        $merged = [
            'identity' => [],
            'personality' => ['primary_archetype' => 'Creator'],
            'visual' => [],
        ];

        $evidence = $builder->build($raw, $merged);

        $this->assertSame('pdf_visual', $evidence['personality.primary_archetype']['winning_source']);
        $this->assertSame(11, $evidence['personality.primary_archetype']['winning_page']);
        $this->assertSame('archetype', $evidence['personality.primary_archetype']['winning_page_type']);
    }

    public function test_archetype_explicit_match_retains_provenance(): void
    {
        $builder = new ExtractionEvidenceMapBuilder();
        $raw = [
            [
                'personality' => ['primary_archetype' => ['value' => 'Hero', 'source_type' => 'explicit', 'evidence' => 'Explicit match']],
                'sources' => ['pdf' => ['extracted' => true]],
                'page_classifications_json' => [['page_type' => 'archetype']],
                'page_extractions_json' => [
                    ['page' => 5, 'page_type' => 'archetype', 'extractions' => [
                        ['path' => 'personality.primary_archetype', 'value' => 'Hero', 'page' => 5, 'page_type' => 'archetype', 'source_type' => 'explicit'],
                    ]],
                ],
            ],
        ];
        $merged = [
            'identity' => [],
            'personality' => ['primary_archetype' => 'Hero'],
            'visual' => [],
        ];

        $evidence = $builder->build($raw, $merged);

        $this->assertSame('pdf_visual', $evidence['personality.primary_archetype']['winning_source']);
        $this->assertSame(5, $evidence['personality.primary_archetype']['winning_page']);
        $this->assertSame('archetype', $evidence['personality.primary_archetype']['winning_page_type']);
        $this->assertSame('explicit_archetype_match', $evidence['personality.primary_archetype']['winning_reason']);
    }

    public function test_color_visual_winner_keeps_page(): void
    {
        $builder = new ExtractionEvidenceMapBuilder();
        $raw = [
            [
                'visual' => ['primary_colors' => ['#C10230', '#101820']],
                'sources' => ['pdf' => ['extracted' => true]],
                'page_extractions_json' => [
                    ['page' => 12, 'page_type' => 'color_palette', 'extractions' => [
                        ['path' => 'visual.primary_colors', 'value' => ['#C10230', '#101820'], 'page' => 12, 'page_type' => 'color_palette'],
                    ]],
                ],
            ],
        ];
        $merged = [
            'identity' => [],
            'personality' => [],
            'visual' => ['primary_colors' => ['#C10230', '#101820']],
        ];

        $evidence = $builder->build($raw, $merged);

        $this->assertArrayHasKey('visual.primary_colors', $evidence);
        $this->assertSame('pdf_visual', $evidence['visual.primary_colors']['winning_source']);
        $this->assertSame(12, $evidence['visual.primary_colors']['winning_page']);
        $this->assertSame('color_palette', $evidence['visual.primary_colors']['winning_page_type']);
    }

    public function test_pdf_visual_winner_gets_page_from_raw_extractions_when_candidates_lack_it(): void
    {
        $builder = new ExtractionEvidenceMapBuilder();
        $raw = [
            [
                'personality' => ['primary_archetype' => 'Ruler'],
                'sources' => ['pdf' => ['extracted' => true]],
                'page_classifications_json' => [['page_type' => 'archetype']],
                'page_extractions_json' => [
                    ['page' => 8, 'page_type' => 'archetype', 'extractions' => [
                        ['path' => 'personality.primary_archetype', 'value' => 'Ruler'],
                    ]],
                ],
            ],
        ];
        $merged = [
            'identity' => [],
            'personality' => ['primary_archetype' => 'Ruler'],
            'visual' => [],
        ];

        $evidence = $builder->build($raw, $merged);

        $this->assertSame('pdf_visual', $evidence['personality.primary_archetype']['winning_source']);
        $this->assertSame(8, $evidence['personality.primary_archetype']['winning_page']);
        $this->assertSame('archetype', $evidence['personality.primary_archetype']['winning_page_type']);
    }
}
