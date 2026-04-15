<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Services\BrandIntelligence\BrandIntelligenceAdminPresenter;
use Tests\TestCase;

class BrandIntelligenceAdminPresenterTest extends TestCase
{
    public function test_pdf_with_derived_raster_does_not_claim_not_an_image_skip(): void
    {
        $reason = BrandIntelligenceAdminPresenter::firstAiSkipReason(
            [
                'recommendations' => [],
                'reference_similarity' => ['used' => false],
                'ebi_ai_trace' => [
                    'skip_reason' => 'parse_failed',
                    'visual_evaluation_source' => [
                        'used' => true,
                        'source_type' => 'pdf_rendered_image',
                        'resolved' => true,
                    ],
                ],
            ],
            0.5,
            'low',
            'application/pdf',
        );

        $this->assertStringNotContainsString('not an image', strtolower((string) $reason));
    }

    public function test_no_thumbnail_for_vision_with_resolved_raster_explains_fetch_not_missing_pdf_raster(): void
    {
        $reason = BrandIntelligenceAdminPresenter::firstAiSkipReason(
            [
                'recommendations' => [],
                'reference_similarity' => ['used' => false],
                'ebi_ai_trace' => [
                    'skip_reason' => 'no_thumbnail_for_vision',
                    'visual_evaluation_source' => [
                        'used' => true,
                        'source_type' => 'pdf_rendered_image',
                        'resolved' => true,
                    ],
                ],
            ],
            0.5,
            'low',
            'application/pdf',
        );

        $this->assertStringContainsString('could not be loaded', strtolower((string) $reason));
        $this->assertStringNotContainsString('not an image', strtolower((string) $reason));
    }

    public function test_video_still_reports_not_image_when_no_raster_trace(): void
    {
        $reason = BrandIntelligenceAdminPresenter::firstAiSkipReason(
            [
                'recommendations' => [],
                'reference_similarity' => ['used' => false],
                'ebi_ai_trace' => [
                    'skip_reason' => 'not_image',
                    'visual_evaluation_source' => [
                        'used' => false,
                        'source_type' => 'none',
                        'resolved' => false,
                        'reason' => 'non_image_root_type',
                    ],
                ],
            ],
            0.5,
            'low',
            'video/mp4',
        );

        $this->assertStringContainsString('not an image', strtolower((string) $reason));
    }
}
