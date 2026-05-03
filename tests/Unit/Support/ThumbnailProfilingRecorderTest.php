<?php

namespace Tests\Unit\Support;

use App\Support\Logging\ThumbnailProfilingRecorder;
use PHPUnit\Framework\TestCase;

class ThumbnailProfilingRecorderTest extends TestCase
{
    public function test_resolve_queue_wait_ms_with_null_job(): void
    {
        $this->assertNull(ThumbnailProfilingRecorder::resolveQueueWaitMs(null));
    }
}
