<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Services\VideoWebPlaybackOptimizationService;
use Tests\TestCase;

class VideoWebPlaybackOptimizationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function asset(array $attrs): Asset
    {
        return new Asset(array_merge([
            'id' => '00000000-0000-4000-8000-000000000001',
            'mime_type' => 'video/mp4',
            'original_filename' => 'clip.mp4',
        ], $attrs));
    }

    public function test_disabled_skips(): void
    {
        config(['assets.video.web_playback.enabled' => false]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['original_filename' => 'x.avi', 'mime_type' => 'video/x-msvideo']));
        $this->assertFalse($d['should_generate']);
        $this->assertSame('feature_disabled', $d['reason']);
    }

    public function test_non_video_skips(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'image/jpeg', 'original_filename' => 'x.jpg']));
        $this->assertFalse($d['should_generate']);
        $this->assertSame('not_video', $d['reason']);
    }

    public function test_avi_generates(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'video/x-msvideo', 'original_filename' => 'a.avi']));
        $this->assertTrue($d['should_generate']);
        $this->assertSame('transcode', $d['strategy']);
        $this->assertSame('avi', $d['extension']);
    }

    public function test_mkv_generates(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'video/x-matroska', 'original_filename' => 'a.mkv']));
        $this->assertTrue($d['should_generate']);
    }

    public function test_mpg_and_mpeg_generate(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $this->assertTrue($svc->decide($this->asset(['mime_type' => 'video/mpeg', 'original_filename' => 'a.mpg']))['should_generate']);
        $this->assertTrue($svc->decide($this->asset(['mime_type' => 'video/mpeg', 'original_filename' => 'a.mpeg']))['should_generate']);
    }

    public function test_webm_generates_when_in_force_list(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'video/webm', 'original_filename' => 'a.webm']));
        $this->assertTrue($d['should_generate']);
    }

    public function test_mp4_skips_phase_one(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'video/mp4', 'original_filename' => 'a.mp4']));
        $this->assertFalse($d['should_generate']);
        $this->assertSame('likely_browser_safe_or_not_forced', $d['reason']);
    }

    public function test_mov_skips_unless_in_force_list(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'video/quicktime', 'original_filename' => 'a.mov']));
        $this->assertFalse($d['should_generate']);
    }

    public function test_mov_generates_when_force_extensions_includes_mov(): void
    {
        config([
            'assets.video.web_playback.enabled' => true,
            'assets.video.web_playback.force_extensions' => ['mov', 'avi'],
        ]);
        $svc = new VideoWebPlaybackOptimizationService;
        $d = $svc->decide($this->asset(['mime_type' => 'video/quicktime', 'original_filename' => 'a.mov']));
        $this->assertTrue($d['should_generate']);
    }

    public function test_should_defer_hover_preview_matches_should_generate(): void
    {
        config(['assets.video.web_playback.enabled' => true]);
        $svc = new VideoWebPlaybackOptimizationService;
        $this->assertTrue($svc->shouldDeferHoverPreviewUntilVideoWeb($this->asset([
            'mime_type' => 'video/x-msvideo',
            'original_filename' => 'a.avi',
        ])));
        $this->assertFalse($svc->shouldDeferHoverPreviewUntilVideoWeb($this->asset([
            'mime_type' => 'video/mp4',
            'original_filename' => 'a.mp4',
        ])));
    }

    public function test_should_defer_hover_preview_false_when_feature_disabled(): void
    {
        config(['assets.video.web_playback.enabled' => false]);
        $svc = new VideoWebPlaybackOptimizationService;
        $this->assertFalse($svc->shouldDeferHoverPreviewUntilVideoWeb($this->asset([
            'mime_type' => 'video/x-msvideo',
            'original_filename' => 'a.avi',
        ])));
    }
}
