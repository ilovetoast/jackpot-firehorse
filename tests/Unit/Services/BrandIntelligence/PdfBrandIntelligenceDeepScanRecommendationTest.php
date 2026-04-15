<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Enums\AlignmentDimension;
use App\Enums\BrandAlignmentState;
use App\Enums\PdfBrandIntelligenceScanMode;
use App\Models\Asset;
use App\Services\BrandIntelligence\PdfBrandIntelligenceDeepScanRecommendation;
use App\Services\BrandIntelligence\VisualEvaluationSourceResolver;
use Tests\TestCase;

class PdfBrandIntelligenceDeepScanRecommendationTest extends TestCase
{
    protected function pdfWithThreeRasters(): Asset
    {
        return new Asset([
            'mime_type' => 'application/pdf',
            'pdf_page_count' => 5,
            'metadata' => [
                'pdf_page_count' => 5,
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/page_1.png'],
                        'preview' => ['path' => 'assets/tenant/x/page_3.png'],
                        'large' => ['path' => 'assets/tenant/x/page_5.png'],
                    ],
                ],
            ],
        ]);
    }

    public function test_recommends_deep_when_weak_and_multiple_rasters(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = $this->pdfWithThreeRasters();
        $breakdown = [
            'alignment_state' => BrandAlignmentState::INSUFFICIENT_EVIDENCE->value,
            'confidence' => 0.5,
            'insufficient_signal' => true,
            'dimensions' => [],
        ];

        $out = PdfBrandIntelligenceDeepScanRecommendation::evaluate(
            $asset,
            PdfBrandIntelligenceScanMode::Standard,
            $breakdown,
            $resolver,
        );

        $this->assertTrue($out['additional_pdf_pages_available']);
        $this->assertGreaterThanOrEqual(2, $out['additional_pdf_pages_count']);
        $this->assertTrue($out['deep_scan_recommended']);
        $this->assertNotNull($out['deep_scan_recommendation_reason']);
    }

    public function test_not_recommended_when_only_one_raster(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/page_1.png'],
                    ],
                ],
            ],
        ]);
        $breakdown = [
            'alignment_state' => BrandAlignmentState::INSUFFICIENT_EVIDENCE->value,
            'confidence' => 0.2,
            'dimensions' => [],
        ];

        $out = PdfBrandIntelligenceDeepScanRecommendation::evaluate(
            $asset,
            PdfBrandIntelligenceScanMode::Standard,
            $breakdown,
            $resolver,
        );

        $this->assertFalse($out['additional_pdf_pages_available']);
        $this->assertFalse($out['deep_scan_recommended']);
        $this->assertNull($out['deep_scan_recommendation_reason']);
    }

    public function test_not_recommended_when_result_strong(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = $this->pdfWithThreeRasters();
        $dims = [];
        foreach ([AlignmentDimension::VISUAL_STYLE, AlignmentDimension::IDENTITY, AlignmentDimension::COPY_VOICE, AlignmentDimension::CONTEXT_FIT] as $d) {
            $dims[] = [
                'dimension' => $d->value,
                'evaluable' => true,
                'confidence' => 0.75,
            ];
        }
        $breakdown = [
            'alignment_state' => BrandAlignmentState::PARTIAL_ALIGNMENT->value,
            'confidence' => 0.85,
            'insufficient_signal' => false,
            'overall_confidence' => 0.72,
            'evaluable_proportion' => 0.85,
            'dimensions' => $dims,
        ];

        $out = PdfBrandIntelligenceDeepScanRecommendation::evaluate(
            $asset,
            PdfBrandIntelligenceScanMode::Standard,
            $breakdown,
            $resolver,
        );

        $this->assertTrue($out['additional_pdf_pages_available']);
        $this->assertFalse($out['deep_scan_recommended']);
    }

    public function test_never_recommends_after_deep_scan_mode(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = $this->pdfWithThreeRasters();
        $breakdown = [
            'alignment_state' => BrandAlignmentState::INSUFFICIENT_EVIDENCE->value,
            'confidence' => 0.2,
            'dimensions' => [],
        ];

        $out = PdfBrandIntelligenceDeepScanRecommendation::evaluate(
            $asset,
            PdfBrandIntelligenceScanMode::Deep,
            $breakdown,
            $resolver,
        );

        $this->assertFalse($out['deep_scan_recommended']);
        $this->assertSame('deep', $out['pdf_scan_mode_used']);
    }

    public function test_video_root_not_applicable(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $asset = new Asset(['mime_type' => 'video/mp4']);
        $out = PdfBrandIntelligenceDeepScanRecommendation::evaluate(
            $asset,
            PdfBrandIntelligenceScanMode::Standard,
            ['alignment_state' => BrandAlignmentState::INSUFFICIENT_EVIDENCE->value, 'dimensions' => []],
            $resolver,
        );

        $this->assertSame('not_applicable', $out['pdf_scan_mode_used']);
        $this->assertFalse($out['deep_scan_recommended']);
    }
}
