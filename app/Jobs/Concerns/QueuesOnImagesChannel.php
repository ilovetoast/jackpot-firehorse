<?php

namespace App\Jobs\Concerns;

/**
 * Pushes the job to the dedicated images/asset-pipeline queue so Horizon can
 * isolate heavy thumbnail and processing workers from default (light) jobs.
 */
trait QueuesOnImagesChannel
{
    use AppliesQueueSafeModeMiddleware;

    protected function configureImagesQueue(): void
    {
        $this->onQueue(config('queue.images_queue', 'images'));
    }
}
