<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\ResearchProgressService;
use PHPUnit\Framework\TestCase;

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
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        $this->assertSame('analyzing', $result['current_stage']);
        // text_extraction complete (5%) + analyzing processing at 30% (0.3 * 80 = 24) = 29
        $this->assertSame(29, $result['overall_percent']);
        $this->assertCount(3, $result['stages']);
        $this->assertArrayNotHasKey('pages', $result);
    }

    public function test_analyzing_in_progress(): void
    {
        $pipelineRun = (object) ['status' => 'processing', 'error_message' => null];

        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
        ];

        $result = $this->service->compute($context, $pipelineRun, (object) ['id' => 'x']);

        $this->assertSame('analyzing', $result['current_stage']);
        $stages = collect($result['stages']);
        $this->assertSame('complete', $stages->firstWhere('key', 'text_extraction')['status']);
        $this->assertSame('processing', $stages->firstWhere('key', 'analyzing')['status']);
        $this->assertSame('pending', $stages->firstWhere('key', 'finalizing')['status']);
    }

    public function test_snapshot_persisted_but_finalization_pending(): void
    {
        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'snapshot_persisted' => true,
                'suggestions_ready' => false,
                'coherence_ready' => false,
                'alignment_ready' => false,
                'research_finalized' => false,
            ],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        $this->assertSame('finalizing', $result['current_stage']);
        $stages = collect($result['stages']);
        $this->assertSame('complete', $stages->firstWhere('key', 'analyzing')['status']);
        $this->assertSame('processing', $stages->firstWhere('key', 'finalizing')['status']);
    }

    public function test_research_finalized_equals_100_percent(): void
    {
        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'snapshot_persisted' => true,
                'suggestions_ready' => true,
                'coherence_ready' => true,
                'alignment_ready' => true,
                'research_finalized' => true,
            ],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        $this->assertSame(100, $result['overall_percent']);
        foreach ($result['stages'] as $stage) {
            $this->assertSame('complete', $stage['status']);
        }
    }

    public function test_no_pdf_returns_valid_structure(): void
    {
        $context = [
            'pipeline_status' => ['text_extraction_complete' => true, 'research_finalized' => false],
        ];

        $result = $this->service->compute($context, null, null);

        $this->assertArrayHasKey('overall_percent', $result);
        $this->assertArrayHasKey('stages', $result);
        $this->assertArrayNotHasKey('pages', $result);
    }

    public function test_finalized_forces_all_stages_complete(): void
    {
        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'snapshot_persisted' => true,
                'suggestions_ready' => true,
                'coherence_ready' => true,
                'alignment_ready' => true,
                'research_finalized' => true,
            ],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        foreach ($result['stages'] as $stage) {
            $this->assertSame('complete', $stage['status']);
            $this->assertSame(100, $stage['percent']);
        }
    }

    public function test_failed_run_shows_error_stage(): void
    {
        $pipelineRun = (object) [
            'status' => 'failed',
            'error_message' => 'Your credit balance is too low to access the Anthropic API.',
        ];

        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => true,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
        ];

        $result = $this->service->compute($context, $pipelineRun, (object) ['id' => 'x']);

        $stages = collect($result['stages']);
        $this->assertSame('complete', $stages->firstWhere('key', 'text_extraction')['status']);
        $this->assertSame('failed', $stages->firstWhere('key', 'analyzing')['status']);
        $this->assertNotNull($stages->firstWhere('key', 'analyzing')['error']);
        $this->assertStringContainsString('billing', $stages->firstWhere('key', 'analyzing')['error']);
        $this->assertSame('pending', $stages->firstWhere('key', 'finalizing')['status']);
    }

    public function test_stage_sequence_later_stages_pending_until_earlier_complete(): void
    {
        $context = [
            'pipeline_status' => [
                'text_extraction_complete' => false,
                'snapshot_persisted' => false,
                'research_finalized' => false,
            ],
        ];

        $result = $this->service->compute($context, null, (object) ['id' => 'x']);

        $stages = collect($result['stages']);
        $this->assertSame('processing', $stages->firstWhere('key', 'text_extraction')['status']);
        $this->assertSame('pending', $stages->firstWhere('key', 'analyzing')['status']);
        $this->assertSame('pending', $stages->firstWhere('key', 'finalizing')['status']);
    }
}
