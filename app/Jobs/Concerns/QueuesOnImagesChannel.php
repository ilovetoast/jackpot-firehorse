<?php

namespace App\Jobs\Concerns;

/**
 * Pushes the job to the dedicated images/asset-pipeline queue so Horizon can
 * isolate heavy thumbnail and processing workers from default (light) jobs.
 */
trait QueuesOnImagesChannel
{
    protected function configureImagesQueue(): void
    {
        $this->onQueue(config('queue.images_queue', 'images'));
    }
}
