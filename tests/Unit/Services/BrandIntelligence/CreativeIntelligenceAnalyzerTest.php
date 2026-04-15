<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Enums\AssetContextType;
use App\Enums\PdfBrandIntelligenceScanMode;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AiMetadataGenerationService;
use App\Services\BrandIntelligence\CreativeIntelligenceAnalyzer;
use App\Services\BrandIntelligence\VisualEvaluationSourceResolver;
use Tests\TestCase;

class CreativeIntelligenceAnalyzerTest extends TestCase
{
    public function test_image_root_trace_uses_original_image_source(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->once())
            ->method('fetchThumbnailForVisionAnalysis')
            ->willReturn('data:image/png;base64,AAAA');

        $json = json_encode([
            'creative_analysis' => [
                'context_type' => 'other',
                'scene_type' => '',
                'lighting_type' => '',
                'mood' => '',
                'detected_text' => '',
                'headline_text' => '',
                'supporting_text' => '',
                'cta_text' => '',
                'voice_traits_detected' => [],
                'visual_traits_detected' => [],
            ],
            'copy_alignment' => [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0,
                'reasons' => [],
            ],
            'visual_alignment' => ['summary' => 'ok', 'fit_score' => 50, 'confidence' => 0.5],
            'brand_copy_conflict' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->once())->method('analyzeImage')->willReturn(['text' => $json]);

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'image/jpeg',
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/m.jpg'],
                    ],
                ],
            ],
        ]);
        $out = $analyzer->analyze($asset, new Brand(['name' => 'Acme']), AssetContextType::OTHER, false);

        $this->assertTrue($out['ebi_ai_trace']['creative_ai_ran'] ?? false);
        $this->assertSame('original_image', $out['ebi_ai_trace']['visual_evaluation_source']['source_type'] ?? null);
        $this->assertNotSame('not_image', $out['ebi_ai_trace']['skip_reason'] ?? null);
    }

    public function test_pdf_without_raster_skips_with_pdf_visual_source_missing(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->never())->method('fetchThumbnailForVisionAnalysis');
        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->never())->method('analyzeImage');

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'metadata' => [],
        ]);
        $brand = new Brand(['name' => 'Acme']);

        $out = $analyzer->analyze($asset, $brand, AssetContextType::OTHER, false);
        $this->assertNull($out['creative_analysis']);
        $this->assertSame('pdf_visual_source_missing', $out['ebi_ai_trace']['skip_reason'] ?? null);
        $this->assertFalse($out['ebi_ai_trace']['visual_evaluation_source']['used'] ?? true);
        $ves = $out['ebi_ai_trace']['visual_evaluation_source'] ?? [];
        foreach (['used', 'source_type', 'origin', 'resolved', 'reason', 'page', 'root_mime_type'] as $k) {
            $this->assertArrayHasKey($k, $ves, 'trace key '.$k);
        }
    }

    public function test_pdf_resolved_raster_but_fetch_failure_does_not_use_pdf_visual_source_missing(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->once())
            ->method('fetchStoragePathForVisionAnalysis')
            ->willReturn(null);
        $meta->expects($this->once())
            ->method('fetchThumbnailForVisionAnalysis')
            ->willReturn(null);
        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->never())->method('analyzeImage');

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/page_1.png'],
                    ],
                ],
            ],
        ]);
        $out = $analyzer->analyze($asset, new Brand(['name' => 'Acme']), AssetContextType::OTHER, false);

        $this->assertSame('no_thumbnail_for_vision', $out['ebi_ai_trace']['skip_reason'] ?? null);
        $this->assertTrue($out['ebi_ai_trace']['visual_evaluation_source']['used'] ?? false);
        $this->assertNotSame('pdf_visual_source_missing', $out['ebi_ai_trace']['skip_reason'] ?? null);
    }

    public function test_pdf_with_raster_invokes_vision_when_thumbnail_fetch_succeeds(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->once())
            ->method('fetchStoragePathForVisionAnalysis')
            ->willReturn('data:image/png;base64,AAAA');
        $meta->expects($this->never())->method('fetchThumbnailForVisionAnalysis');

        $json = json_encode([
            'creative_analysis' => [
                'context_type' => 'other',
                'scene_type' => 's',
                'lighting_type' => 'l',
                'mood' => 'm',
                'detected_text' => 'Hello',
                'headline_text' => '',
                'supporting_text' => '',
                'cta_text' => '',
                'voice_traits_detected' => [],
                'visual_traits_detected' => [],
            ],
            'copy_alignment' => [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0,
                'reasons' => [],
            ],
            'visual_alignment' => ['summary' => 'ok', 'fit_score' => 50, 'confidence' => 0.5],
            'brand_copy_conflict' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->once())
            ->method('analyzeImage')
            ->willReturn(['text' => $json]);

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/page_1.png'],
                    ],
                ],
            ],
        ]);
        $brand = new Brand(['name' => 'Acme']);

        $out = $analyzer->analyze($asset, $brand, AssetContextType::OTHER, false);
        $this->assertTrue($out['ebi_ai_trace']['creative_ai_ran'] ?? false);
        $this->assertFalse($out['ebi_ai_trace']['skipped'] ?? true);
        $this->assertNotSame('not_image', $out['ebi_ai_trace']['skip_reason'] ?? null);
        $this->assertTrue($out['ebi_ai_trace']['visual_evaluation_source']['used'] ?? false);
        $this->assertSame('pdf_rendered_image', $out['ebi_ai_trace']['visual_evaluation_source']['source_type'] ?? null);
        $ves = $out['ebi_ai_trace']['visual_evaluation_source'] ?? [];
        foreach (['used', 'source_type', 'origin', 'resolved', 'reason', 'page', 'root_mime_type'] as $k) {
            $this->assertArrayHasKey($k, $ves);
        }
        $pm = $out['ebi_ai_trace']['pdf_multi_page'] ?? null;
        $this->assertIsArray($pm);
        $this->assertSame('single_page_catalog_or_thumbnail', $pm['page_combination_strategy'] ?? null);
        $this->assertSame('standard', $out['ebi_ai_trace']['pdf_scan_mode'] ?? null);
        $this->assertSame(1, $out['ebi_ai_trace']['max_pdf_pages_allowed'] ?? null);
    }

    public function test_pdf_three_rasters_standard_scan_uses_single_vision_call(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->once())
            ->method('fetchStoragePathForVisionAnalysis')
            ->willReturn('data:image/png;base64,AAAA');
        $meta->expects($this->never())->method('fetchThumbnailForVisionAnalysis');

        $json = json_encode([
            'creative_analysis' => [
                'context_type' => 'other',
                'scene_type' => '',
                'lighting_type' => '',
                'mood' => '',
                'detected_text' => 'solo',
                'headline_text' => '',
                'supporting_text' => '',
                'cta_text' => '',
                'voice_traits_detected' => [],
                'visual_traits_detected' => [],
            ],
            'copy_alignment' => [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0,
                'reasons' => [],
            ],
            'visual_alignment' => ['summary' => 'ok', 'fit_score' => 50, 'confidence' => 0.5],
            'brand_copy_conflict' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->once())->method('analyzeImage')->willReturn(['text' => $json]);

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
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

        $out = $analyzer->analyze($asset, new Brand(['name' => 'Acme']), AssetContextType::OTHER, false, PdfBrandIntelligenceScanMode::Standard);
        $this->assertTrue($out['ebi_ai_trace']['creative_ai_ran'] ?? false);
        $this->assertSame('standard', $out['ebi_ai_trace']['pdf_scan_mode'] ?? null);
        $this->assertSame(1, $out['ebi_ai_trace']['max_pdf_pages_allowed'] ?? null);
        $this->assertSame([1], $out['ebi_ai_trace']['pdf_multi_page']['selected_pdf_pages'] ?? null);
    }

    public function test_pdf_multi_page_evaluates_multiple_pages_and_merges_trace(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->exactly(3))
            ->method('fetchStoragePathForVisionAnalysis')
            ->willReturn('data:image/png;base64,AAAA');
        $meta->expects($this->never())->method('fetchThumbnailForVisionAnalysis');

        $pageJson = static fn (string $ctx, string $text) => json_encode([
            'creative_analysis' => [
                'context_type' => $ctx,
                'scene_type' => 's',
                'lighting_type' => 'l',
                'mood' => 'm',
                'detected_text' => $text,
                'headline_text' => '',
                'supporting_text' => '',
                'cta_text' => '',
                'voice_traits_detected' => [],
                'visual_traits_detected' => [],
            ],
            'copy_alignment' => [
                'score' => 70,
                'alignment_state' => 'partial',
                'confidence' => 0.6,
                'reasons' => ['r'],
            ],
            'visual_alignment' => ['summary' => 'ok', 'fit_score' => 55, 'confidence' => 0.5],
            'brand_copy_conflict' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->exactly(3))
            ->method('analyzeImage')
            ->willReturnOnConsecutiveCalls(
                ['text' => $pageJson('product_hero', 'One')],
                ['text' => $pageJson('product_hero', 'Two')],
                ['text' => $pageJson('lifestyle', 'Three')],
            );

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'pdf_page_count' => 5,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
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
        $brand = new Brand(['name' => 'Acme']);

        $out = $analyzer->analyze($asset, $brand, AssetContextType::OTHER, false, PdfBrandIntelligenceScanMode::Deep);
        $this->assertTrue($out['ebi_ai_trace']['creative_ai_ran'] ?? false);
        $this->assertSame('deep', $out['ebi_ai_trace']['pdf_scan_mode'] ?? null);
        $this->assertSame(3, $out['ebi_ai_trace']['max_pdf_pages_allowed'] ?? null);
        $pm = $out['ebi_ai_trace']['pdf_multi_page'] ?? [];
        $this->assertSame([1, 3, 5], $pm['selected_pdf_pages'] ?? null);
        $this->assertCount(3, $pm['evaluated_pdf_pages'] ?? []);
        $this->assertSame('merged_multi_page_vision_best_signals', $pm['page_combination_strategy'] ?? null);
        $this->assertSame('first_middle_last_then_fill', $pm['pdf_page_selection_strategy'] ?? null);
        $this->assertCount(3, $pm['per_page_visual_sources'] ?? []);
        $this->assertSame('product_hero', $out['context_analysis']['context_type_ai'] ?? null);
        $this->assertSame(2, $out['context_analysis']['multi_page_context_type_votes']['product_hero'] ?? 0);
        $this->assertStringContainsString('One', (string) ($out['creative_analysis']['detected_text'] ?? ''));
    }

    public function test_pdf_multi_page_continues_when_one_page_fetch_fails(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->exactly(3))
            ->method('fetchStoragePathForVisionAnalysis')
            ->willReturnOnConsecutiveCalls(null, 'data:image/png;base64,BBBB', 'data:image/png;base64,CCCC');

        $pageJson = static fn (string $text) => json_encode([
            'creative_analysis' => [
                'context_type' => 'other',
                'scene_type' => '',
                'lighting_type' => '',
                'mood' => '',
                'detected_text' => $text,
                'headline_text' => '',
                'supporting_text' => '',
                'cta_text' => '',
                'voice_traits_detected' => [],
                'visual_traits_detected' => [],
            ],
            'copy_alignment' => [
                'score' => null,
                'alignment_state' => 'not_applicable',
                'confidence' => 0,
                'reasons' => [],
            ],
            'visual_alignment' => ['summary' => 'ok', 'fit_score' => 40, 'confidence' => 0.4],
            'brand_copy_conflict' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ai = $this->createMock(AIProviderInterface::class);
        $ai->expects($this->exactly(2))
            ->method('analyzeImage')
            ->willReturnOnConsecutiveCalls(
                ['text' => $pageJson('B')],
                ['text' => $pageJson('C')],
            );

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
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

        $out = $analyzer->analyze($asset, new Brand(['name' => 'Acme']), AssetContextType::OTHER, false, PdfBrandIntelligenceScanMode::Deep);
        $this->assertTrue($out['ebi_ai_trace']['creative_ai_ran'] ?? false);
        $sources = $out['ebi_ai_trace']['pdf_multi_page']['per_page_visual_sources'] ?? [];
        $this->assertSame('fetch_failed_or_empty', $sources[0]['reason'] ?? null);
        $this->assertCount(2, $out['ebi_ai_trace']['pdf_multi_page']['evaluated_pdf_pages'] ?? []);
    }

    public function test_dry_run_includes_visual_evaluation_source_trace(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->never())->method('fetchThumbnailForVisionAnalysis');
        $ai = $this->createMock(AIProviderInterface::class);
        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'application/pdf',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'original' => [
                        'medium' => ['path' => 'assets/tenant/x/page_1.png'],
                    ],
                ],
            ],
        ]);
        $brand = new Brand(['name' => 'Acme']);

        $out = $analyzer->analyze($asset, $brand, AssetContextType::OTHER, true, PdfBrandIntelligenceScanMode::Deep);
        $this->assertSame('dry_run', $out['ebi_ai_trace']['skip_reason'] ?? null);
        $ves = $out['ebi_ai_trace']['visual_evaluation_source'] ?? [];
        $this->assertTrue($ves['used'] ?? false);
        $this->assertSame('pdf_rendered_image', $ves['source_type'] ?? null);
        $this->assertSame('deep', $out['ebi_ai_trace']['pdf_scan_mode'] ?? null);
        $this->assertSame(3, $out['ebi_ai_trace']['max_pdf_pages_allowed'] ?? null);
    }

    public function test_plain_image_still_skips_when_not_image_mime(): void
    {
        $resolver = new VisualEvaluationSourceResolver;
        $meta = $this->createMock(AiMetadataGenerationService::class);
        $meta->expects($this->never())->method('fetchThumbnailForVisionAnalysis');
        $ai = $this->createMock(AIProviderInterface::class);

        $analyzer = new CreativeIntelligenceAnalyzer($ai, $meta, $resolver);

        $asset = new Asset([
            'mime_type' => 'video/mp4',
            'metadata' => [],
        ]);
        $brand = new Brand(['name' => 'Acme']);

        $out = $analyzer->analyze($asset, $brand, AssetContextType::OTHER, false);
        $this->assertSame('not_image', $out['ebi_ai_trace']['skip_reason'] ?? null);
    }
}
