<?php

namespace App\Jobs\Middleware;

/**
 * Circuit breaker: when app.queue_safe_mode is true, defer work without running the job body.
 * Uses release() so Bus::chain() does not advance until the job actually completes.
 */
class ReleaseWhenQueueSafeMode
{
    public function handle(object $job, callable $next): void
    {
        if (config('app.queue_safe_mode')) {
            $job->release(60);

            return;
        }

        $next($job);
    }
}
