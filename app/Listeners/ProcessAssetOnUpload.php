<?php

namespace App\Listeners;

use App\Events\AssetUploaded;
use App\Jobs\ProcessAssetJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ProcessAssetOnUpload implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(AssetUploaded $event): void
    {
        ProcessAssetJob::dispatch($event->asset->id);
    }
}
