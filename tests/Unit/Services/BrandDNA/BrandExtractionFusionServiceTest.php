<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandExtractionFusionService;
use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use Tests\TestCase;

class BrandExtractionFusionServiceTest extends TestCase
{
    public function test_page_extractions_to_schema_converts_format(): void
    {
        $service = new BrandExtractionFusionService();
        $pageExtractions = [
            [
                'page' => 12,
                'page_type' => 'typography',
                'extractions' => [
                    [
                        'path' => 'typography.primary_font',
                        'value' => 'Montserrat',
                        'confidence' => 0.84,
                        'evidence' => 'Large heading labels',
                        'page' => 12,
                        'page_type' => 'typography',
                        'source' => ['pdf_visual'],
                    ],
                ],
            ],
        ];

        $schema = $service->pageExtractionsToSchema($pageExtractions);

        $fonts = $schema['visual']['fonts'] ?? [];
        $typography = $schema['_typography'] ?? [];
        $this->assertTrue(
            in_array('Montserrat', $fonts, true) || ($typography['primary_font'] ?? null) === 'Montserrat',
            'Primary font should be in visual.fonts or _typography'
        );
        $this->assertSame('pdf_visual', $schema['sources']['pdf']['source'] ?? null);
    }

    public function test_color_palette_page_produces_only_palette_fields(): void
    {
        $service = new BrandExtractionFusionService();
        $pageExtractions = [
            [
                'page' => 5,
                'page_type' => 'color_palette',
                'extractions' => [
                    [
                        'path' => 'visual.primary_colors',
                        'value' => ['#003388', '#FF6600'],
                        'confidence' => 0.92,
                        'evidence' => 'Color swatches shown',
                        'page' => 5,
                        'page_type' => 'color_palette',
                        'source' => ['pdf_visual'],
                    ],
                ],
            ],
        ];

        $schema = $service->pageExtractionsToSchema($pageExtractions);

        $this->assertNotEmpty($schema['visual']['primary_colors']);
        $this->assertNull($schema['identity']['mission'] ?? null);
        $this->assertNull($schema['personality']['primary_archetype'] ?? null);
    }

    public function test_example_gallery_does_not_produce_positioning_or_mission(): void
    {
        config(['brand_dna_page_extraction.allowed_fields_by_page_type' => [
            'example_gallery' => ['visual.photography_style', 'visual.design_cues'],
        ]]);

        $service = new BrandExtractionFusionService();
        $pageExtractions = [
            [
                'page' => 10,
                'page_type' => 'example_gallery',
                'extractions' => [
                    [
                        'path' => 'visual.photography_style',
                        'value' => 'minimalist',
                        'confidence' => 0.7,
                        'evidence' => 'Clean product shots',
                        'page' => 10,
                        'page_type' => 'example_gallery',
                        'source' => ['pdf_visual'],
                    ],
                ],
            ],
        ];

        $schema = $service->pageExtractionsToSchema($pageExtractions);

        $this->assertNotEmpty($schema['visual']['photography_style'] ?? $schema['visual']['visual_style'] ?? []);
        $this->assertNull($schema['identity']['positioning'] ?? null);
        $this->assertNull($schema['identity']['mission'] ?? null);
    }

    public function test_fusion_merge_page_extractions(): void
    {
        $service = new BrandExtractionFusionService();
        $pageExtractions = [
            [
                'page' => 1,
                'page_type' => 'typography',
                'extractions' => [
                    [
                        'path' => 'typography.primary_font',
                        'value' => 'Montserrat',
                        'confidence' => 0.9,
                        'evidence' => 'Page 1',
                        'page' => 1,
                        'page_type' => 'typography',
                        'source' => ['pdf_visual'],
                    ],
                ],
            ],
            [
                'page' => 2,
                'page_type' => 'color_palette',
                'extractions' => [
                    [
                        'path' => 'visual.primary_colors',
                        'value' => ['#003388'],
                        'confidence' => 0.95,
                        'evidence' => 'Page 2',
                        'page' => 2,
                        'page_type' => 'color_palette',
                        'source' => ['pdf_visual'],
                    ],
                ],
            ],
        ];

        $merged = $service->mergePageExtractions($pageExtractions);

        $this->assertNotEmpty($merged['visual']['fonts']);
        $this->assertNotEmpty($merged['visual']['primary_colors']);
    }

    public function test_explicit_archetype_is_stored(): void
    {
        $service = new BrandExtractionFusionService();
        $pageExtractions = [
            [
                'page' => 7,
                'page_type' => 'archetype',
                'extractions' => [
                    [
                        'path' => 'personality.primary_archetype',
                        'value' => 'Ruler',
                        'confidence' => 0.98,
                        'evidence' => ['A RULER FOR THE PEOPLE'],
                        'page' => 7,
                        'page_type' => 'archetype',
                        'source' => ['pdf_visual'],
                        '_explicit_detection' => true,
                    ],
                ],
            ],
        ];

        $schema = $service->pageExtractionsToSchema($pageExtractions);
        $archetype = $schema['personality']['primary_archetype'];
        $value = is_array($archetype) && isset($archetype['value']) ? $archetype['value'] : $archetype;
        $this->assertSame('Ruler', $value);
        $this->assertSame('explicit', $archetype['source_type'] ?? null);
    }

    public function test_explicit_archetype_outranks_inferred(): void
    {
        $service = new BrandExtractionFusionService();
        $pageExtractions = [
            [
                'page' => 7,
                'page_type' => 'archetype',
                'extractions' => [
                    [
                        'path' => 'personality.primary_archetype',
                        'value' => 'Ruler',
                        'confidence' => 0.98,
                        'evidence' => ['A RULER FOR THE PEOPLE', 'RULER ATTRIBUTES'],
                        'page' => 7,
                        'page_type' => 'archetype',
                        'source' => ['pdf_visual'],
                        '_explicit_detection' => true,
                    ],
                ],
            ],
            [
                'page' => 8,
                'page_type' => 'mission',
                'extractions' => [
                    [
                        'path' => 'personality.primary_archetype',
                        'value' => 'Hero',
                        'confidence' => 0.72,
                        'evidence' => 'Inferred from mission narrative',
                        'page' => 8,
                        'page_type' => 'mission',
                        'source' => ['pdf_visual'],
                    ],
                ],
            ],
        ];

        $schema = $service->pageExtractionsToSchema($pageExtractions);

        $this->assertArrayHasKey('personality', $schema);
        $this->assertArrayHasKey('primary_archetype', $schema['personality']);

        $archetype = $schema['personality']['primary_archetype'];
        $value = is_array($archetype) && isset($archetype['value']) ? $archetype['value'] : $archetype;
        $this->assertSame('Ruler', $value, 'Explicit Ruler should win over inferred Hero');
        if (is_array($archetype)) {
            $this->assertSame('explicit', $archetype['source_type'] ?? null);
        }
    }
}
