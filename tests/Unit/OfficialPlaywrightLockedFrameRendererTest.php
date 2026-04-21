<?php

namespace Tests\Unit;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Rendering\OfficialPlaywrightLockedFrameRenderer;
use Tests\TestCase;

final class OfficialPlaywrightLockedFrameRendererTest extends TestCase
{
    public function test_skips_when_disabled(): void
    {
        config(['studio_animation.official_playwright_renderer.enabled' => false]);
        $job = new StudioAnimationJob;
        $job->id = 1;
        $job->aspect_ratio = '16:9';
        $r = (new OfficialPlaywrightLockedFrameRenderer)->tryRenderPng($job, ['width' => 10, 'height' => 10, 'layers' => []], 10, 10);
        $this->assertFalse($r->ok);
        $this->assertSame('official_playwright_disabled', $r->skipReason);
    }
}
