<?php

namespace Tests\Unit;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Rendering\BrowserHeadlessLockedFrameRenderer;
use Tests\TestCase;

final class BrowserHeadlessLockedFrameRendererTest extends TestCase
{
    public function test_skips_when_disabled_even_if_command_set(): void
    {
        config([
            'studio_animation.browser_locked_frame.enabled' => false,
            'studio_animation.browser_locked_frame.command_template' => 'echo {{DOCUMENT_JSON}}',
        ]);
        $job = new StudioAnimationJob;
        $job->id = 1;
        $r = (new BrowserHeadlessLockedFrameRenderer)->tryRenderPng($job, ['width' => 1, 'height' => 1, 'layers' => []]);
        $this->assertFalse($r->ok);
        $this->assertSame('browser_locked_frame_disabled', $r->skipReason);
    }
}
