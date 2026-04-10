<?php

namespace Tests\Unit\Services;

use App\Services\VideoFrameExtractor;
use Tests\TestCase;

class VideoFrameExtractorTest extends TestCase
{
    public function test_throws_for_unreadable_path(): void
    {
        $this->expectException(\RuntimeException::class);
        (new VideoFrameExtractor)->extractFrames('/nonexistent/path/video-'.uniqid().'.mp4');
    }
}
