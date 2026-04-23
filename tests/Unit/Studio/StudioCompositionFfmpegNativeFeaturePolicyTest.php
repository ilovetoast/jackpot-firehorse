<?php

namespace Tests\Unit\Studio;

use App\Models\Composition;
use App\Services\Studio\StudioCompositionFfmpegNativeFeaturePolicy;
use Tests\TestCase;

class StudioCompositionFfmpegNativeFeaturePolicyTest extends TestCase
{
    public function test_plain_video_and_image_supported(): void
    {
        $c = new Composition([
            'document_json' => [
                'layers' => [
                    ['id' => 'v1', 'type' => 'video', 'visible' => true, 'z' => 0, 'assetId' => 'a'],
                    ['id' => 'i1', 'type' => 'image', 'visible' => true, 'z' => 1, 'blendMode' => 'normal'],
                ],
            ],
        ]);
        $this->assertTrue(StudioCompositionFfmpegNativeFeaturePolicy::isSupported($c));
        $this->assertSame([], StudioCompositionFfmpegNativeFeaturePolicy::unsupportedCodes($c));
    }

    public function test_mask_is_unsupported(): void
    {
        $c = new Composition([
            'document_json' => [
                'layers' => [
                    ['id' => 'm1', 'type' => 'mask', 'visible' => true, 'z' => 0],
                ],
            ],
        ]);
        $this->assertFalse(StudioCompositionFfmpegNativeFeaturePolicy::isSupported($c));
        $this->assertContains('mask_layer', StudioCompositionFfmpegNativeFeaturePolicy::unsupportedCodes($c));
    }

    public function test_gradient_fill_is_allowed_pad_color_is_approximated_elsewhere(): void
    {
        $c = new Composition([
            'document_json' => [
                'layers' => [
                    ['id' => 'f1', 'type' => 'fill', 'visible' => true, 'z' => 0, 'fillKind' => 'gradient', 'gradientEndColor' => '#ff0000'],
                ],
            ],
        ]);
        $this->assertTrue(StudioCompositionFfmpegNativeFeaturePolicy::isSupported($c));
        $this->assertSame([], StudioCompositionFfmpegNativeFeaturePolicy::unsupportedCodes($c));
    }
}
