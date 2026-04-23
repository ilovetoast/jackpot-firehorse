<?php

namespace App\Support;

/**
 * Queue for Studio composition **canvas runtime** video export (Playwright / headless Chromium, frame capture, FFmpeg).
 *
 * Defaults to the same Redis queue as {@see StudioVideoQueue::heavy()} so existing `supervisor-video-heavy` workers
 * can pick up jobs until ops split capacity. Set {@code QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE} to dedicate workers.
 */
final class StudioCanvasExportQueue
{
    public static function heavy(): string
    {
        $dedicated = (string) config('studio_video.canvas_export_queue', '');

        return $dedicated !== ''
            ? $dedicated
            : (string) config('queue.video_heavy_queue', 'video-heavy');
    }
}
