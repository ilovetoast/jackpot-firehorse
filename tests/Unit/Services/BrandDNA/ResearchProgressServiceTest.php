<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\ResearchProgressService;
use PHPUnit\Framework\TestCase;

/**
 * Research progress calculation for Brand Guidelines processing UI.
 */
class ResearchProgressServiceTest extends TestCase
{
    private ResearchProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ResearchProgressService;
    }

    public function test_text_extraction_only(): void
    {
        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'pdf_render_complete' => true,
                'page_classification_complete' => false,
                'page_extraction_complete' => false,
                'fusion_complete' => false,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
            'pdf' => ['pages_total' => 0, 'pages_processed' => 0],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        $this->assertArrayHasKey('overall_percent', $result);
        $this->assertArrayHasKey('current_stage', $result);
        $this->assertArrayHasKey('stages', $result);
        $this->assertArrayHasKey('pages', $result);

        $textStage = $this->findStage($result['stages'], 'text_extraction');
        $this->assertNotNull($textStage);
        $this->assertSame('complete', $textStage['status']);
        $this->assertSame(100, $textStage['percent']);

        $this->assertGreaterThanOrEqual(20, $result['overall_percent']);
    }

    public function test_page_rendering_in_progress(): void
    {
        $visionBatch = (object) [
            'batch_id' => 'vision_test_123',
            'pages_total' => 10,
            'pages_processed' => 0,
        ];

        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'pdf_render_complete' => false,
                'page_classification_complete' => false,
                'page_extraction_complete' => false,
                'fusion_complete' => false,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
            'pdf' => ['pages_total' => 10, 'pages_processed' => 0],
        ];

        $result = $this->service->compute($context, $visionBatch, (object) ['id' => 'x']);

        $renderStage = $this->findStage($result['stages'], 'page_rendering');
        $this->assertNotNull($renderStage);
        $this->assertContains($renderStage['status'], ['complete', 'processing']);

        $this->assertSame(10, $result['pages']['total']);
    }

    public function test_partial_page_extraction_progress(): void
    {
        $visionBatch = (object) [
            'batch_id' => 'vision_partial_456',
            'pages_total' => 18,
            'pages_processed' => 7,
        ];

        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'pdf_render_complete' => true,
                'page_classification_complete' => false,
                'page_extraction_complete' => false,
                'fusion_complete' => false,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
            'pdf' => ['pages_total' => 18, 'pages_processed' => 7],
        ];

        $result = $this->service->compute($context, $visionBatch, (object) ['id' => 'x']);

        $visualStage = $this->findStage($result['stages'], 'visual_extraction');
        $this->assertNotNull($visualStage);
        $this->assertSame('processing', $visualStage['status']);
        $this->assertGreaterThan(0, $visualStage['percent']);
        $this->assertLessThan(100, $visualStage['percent']);

        $this->assertSame(18, $result['pages']['total']);
        $this->assertSame(7, $result['pages']['extracted']);
    }

    public function test_fusion_complete_but_finalization_pending(): void
    {
        $visionBatch = (object) [
            'batch_id' => 'vision_fusion_789',
            'pages_total' => 5,
            'pages_processed' => 5,
        ];

        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'pdf_render_complete' => true,
                'page_classification_complete' => true,
                'page_extraction_complete' => true,
                'fusion_complete' => true,
                'snapshot_persisted' => true,
                'suggestions_ready' => false,
                'coherence_ready' => false,
                'alignment_ready' => false,
                'research_finalized' => false,
            ],
            'pdf' => ['pages_total' => 5, 'pages_processed' => 5],
        ];

        $result = $this->service->compute($context, $visionBatch, (object) ['id' => 'x']);

        $fusionStage = $this->findStage($result['stages'], 'fusion');
        $this->assertNotNull($fusionStage);
        $this->assertSame('complete', $fusionStage['status']);

        $finalizeStage = $this->findStage($result['stages'], 'finalizing');
        $this->assertNotNull($finalizeStage);
        $this->assertSame('processing', $finalizeStage['status']);

        $this->assertGreaterThanOrEqual(85, $result['overall_percent']);
        $this->assertLessThan(100, $result['overall_percent']);
    }

    public function test_research_finalized_equals_100_percent(): void
    {
        $visionBatch = (object) [
            'batch_id' => 'vision_done_999',
            'pages_total' => 3,
            'pages_processed' => 3,
        ];

        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'pdf_render_complete' => true,
                'page_classification_complete' => true,
                'page_extraction_complete' => true,
                'fusion_complete' => true,
                'snapshot_persisted' => true,
                'suggestions_ready' => true,
                'coherence_ready' => true,
                'alignment_ready' => true,
                'research_finalized' => true,
            ],
            'pdf' => ['pages_total' => 3, 'pages_processed' => 3],
        ];

        $result = $this->service->compute($context, $visionBatch, (object) ['id' => 'x']);

        $this->assertSame(100, $result['overall_percent']);
        $this->assertSame('finalizing', $result['current_stage']);

        foreach ($result['stages'] as $stage) {
            $this->assertSame('complete', $stage['status'], "Stage {$stage['key']} should be complete");
        }
    }

    public function test_no_pdf_returns_valid_structure(): void
    {
        $result = $this->service->compute(
            ['pipeline_status' => [], 'pdf' => []],
            null,
            null
        );

        $this->assertArrayHasKey('overall_percent', $result);
        $this->assertArrayHasKey('stages', $result);
        $this->assertCount(5, $result['stages']);
        $this->assertSame(0, $result['pages']['total']);
    }

    public function test_finalized_forces_all_stages_complete(): void
    {
        $context = [
            'pipeline_status' => [
                'research_finalized' => true,
            ],
            'pdf' => [],
        ];

        $result = $this->service->compute($context, null, null);

        foreach ($result['stages'] as $stage) {
            $this->assertSame('complete', $stage['status'], "Stage {$stage['key']} must be complete when research_finalized");
            $this->assertSame(100, $stage['percent']);
        }
    }

    public function test_stage_sequence_later_stages_pending_until_earlier_complete(): void
    {
        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => false,
                'pdf_render_complete' => false,
                'page_classification_complete' => false,
                'page_extraction_complete' => false,
                'fusion_complete' => false,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
            'pdf' => ['pages_total' => 0, 'pages_processed' => 0],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        $visualStage = $this->findStage($result['stages'], 'visual_extraction');
        $this->assertSame('pending', $visualStage['status']);

        $fusionStage = $this->findStage($result['stages'], 'fusion');
        $this->assertSame('pending', $fusionStage['status']);
    }

    private function findStage(array $stages, string $key): ?array
    {
        foreach ($stages as $stage) {
            if (($stage['key'] ?? '') === $key) {
                return $stage;
            }
        }

        return null;
    }
}
