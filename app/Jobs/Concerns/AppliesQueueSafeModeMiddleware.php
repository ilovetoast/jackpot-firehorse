<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\ReleaseWhenQueueSafeMode;

trait AppliesQueueSafeModeMiddleware
{
    /**
     * @return array<int, class-string>
     */
    public function middleware(): array
    {
        return [ReleaseWhenQueueSafeMode::class];
    }
}
