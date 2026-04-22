<?php

namespace App\Support;

/**
 * @see config('queue.video_light_queue') and config('horizon.php') supervisor-video-*
 */
final class StudioVideoQueue
{
    public static function light(): string
    {
        return (string) config('queue.video_light_queue', 'video-light');
    }

    public static function heavy(): string
    {
        return (string) config('queue.video_heavy_queue', 'video-heavy');
    }
}
