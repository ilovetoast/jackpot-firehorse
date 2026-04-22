<?php

namespace App\Support;

/**
 * Studio animation (Process / Poll / Finalize) jobs use the {@see config('queue.ai_queue')} list in staging and
 * production where Horizon runs {@code supervisor-ai}. Locally, developers often run {@code php artisan queue:work}
 * without {@code --queue}, which only consumes the connection's default list — so we route to that list unless
 * {@see config('studio_animation.dispatch_queue')} or {@code STUDIO_ANIMATION_QUEUE} is set.
 */
final class StudioAnimationQueue
{
    public static function name(): string
    {
        $override = (string) config('studio_animation.dispatch_queue', '');
        if ($override !== '') {
            return $override;
        }

        if (! app()->environment('local')) {
            return (string) config('queue.ai_queue', 'ai');
        }

        $connection = (string) config('queue.default', 'sync');
        if ($connection === 'sync') {
            return (string) config('queue.connections.redis.queue', 'default');
        }

        return (string) config("queue.connections.{$connection}.queue", 'default');
    }
}
