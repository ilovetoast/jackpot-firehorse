<?php

namespace Tests\Unit;

use App\Services\Studio\StudioCompositionVideoExportMediaHelper;
use PHPUnit\Framework\TestCase;

class StudioCompositionVideoExportMediaHelperTest extends TestCase
{
    public function test_primary_video_still_to_video_false_without_provenance(): void
    {
        $doc = [
            'width' => 100,
            'height' => 100,
            'studio_timeline' => ['duration_ms' => 5000],
            'layers' => [
                [
                    'id' => 'v1',
                    'type' => 'video',
                    'visible' => true,
                    'z' => 0,
                    'assetId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    'primaryForExport' => true,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
                    'timeline' => ['trim_in_ms' => 0, 'trim_out_ms' => 0, 'muted' => true],
                ],
            ],
        ];
        $this->assertFalse(StudioCompositionVideoExportMediaHelper::primaryVideoIsStudioStillToVideoAnimation($doc));
    }

    public function test_primary_video_still_to_video_true_with_studio_provenance_job_id(): void
    {
        $doc = [
            'width' => 100,
            'height' => 100,
            'studio_timeline' => ['duration_ms' => 5000],
            'layers' => [
                [
                    'id' => 'v1',
                    'type' => 'video',
                    'visible' => true,
                    'z' => 0,
                    'assetId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    'primaryForExport' => true,
                    'studioProvenance' => [
                        'jobId' => '01hzyd8k2examplejobid9abcdef',
                        'sourceMode' => 'single_layer',
                        'provider' => 'fal',
                    ],
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
                    'timeline' => ['trim_in_ms' => 0, 'trim_out_ms' => 0, 'muted' => true],
                ],
            ],
        ];
        $this->assertTrue(StudioCompositionVideoExportMediaHelper::primaryVideoIsStudioStillToVideoAnimation($doc));
    }
}
