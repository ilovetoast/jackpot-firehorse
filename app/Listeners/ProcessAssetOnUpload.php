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
        $asset = $event->asset;
        \App\Services\UploadDiagnosticLogger::assetSnapshot($asset, 'ProcessAssetOnUpload DISPATCHING ProcessAssetJob', [
            'version_id' => $asset->currentVersion?->id,
        ]);
        ProcessAssetJob::dispatch($asset->id);
    }
}
