<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\ExtractionEvidenceMapBuilder;
use App\Services\BrandDNA\FieldCandidateValidationService;
use Tests\TestCase;

class FieldCandidateValidationIntegrationTest extends TestCase
{
    public function test_evidence_map_does_not_produce_final_bad_values_from_rejected_candidates(): void
    {
        $validationService = new FieldCandidateValidationService;
        $evidenceBuilder = new ExtractionEvidenceMapBuilder;

        $rawExtractions = [
            [
                'identity' => ['positioning' => 'CONSUMER within a category.'],
                'sources' => ['pdf' => ['extracted' => true]],
                'page_classifications_json' => [['page_type' => 'positioning']],
                'page_extractions_json' => [],
            ],
        ];
        $mergedBeforeSanitize = [
            'identity' => ['positioning' => 'CONSUMER within a category.'],
            'personality' => [],
            'visual' => [],
        ];

        $sanitized = $validationService->sanitizeMergedExtraction($mergedBeforeSanitize);
        $evidence = $evidenceBuilder->build($rawExtractions, $sanitized);

        $this->assertNull($sanitized['identity']['positioning']);
        $this->assertArrayNotHasKey('identity.positioning', $evidence);
    }

    public function test_invalid_candidates_do_not_enter_fusion(): void
    {
        $validationService = new FieldCandidateValidationService;

        $candidates = [
            ['path' => 'typography.primary_font', 'value' => '50 PHOTOGRAPHY premier fitness', 'confidence' => 0.8],
            ['path' => 'scoring_rules.tone_keywords', 'value' => ['OF VOICE'], 'confidence' => 0.7],
        ];

        [$accepted, $rejected] = $validationService->validateMany($candidates);

        $this->assertCount(0, $accepted);
        $this->assertCount(2, $rejected);
        $this->assertSame('invalid_font_candidate', $rejected[0]['reason']);
        $this->assertSame('label_fragment_not_keywords', $rejected[1]['reason']);
    }

    public function test_sanitize_clears_fragmentary_positioning(): void
    {
        $validationService = new FieldCandidateValidationService;

        $extraction = [
            'identity' => [
                'mission' => 'We empower people to live healthier lives through premium fitness solutions.',
                'positioning' => 'within a category',
            ],
            'personality' => [],
            'visual' => [],
        ];

        $sanitized = $validationService->sanitizeMergedExtraction($extraction);

        $this->assertNotNull($sanitized['identity']['mission']);
        $this->assertStringContainsString('empower', $sanitized['identity']['mission']);
        $this->assertNull($sanitized['identity']['positioning']);
    }
}
