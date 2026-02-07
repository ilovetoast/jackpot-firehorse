<?php

namespace App\Listeners;

use App\Events\AssetUploaded;
use App\Jobs\ProcessAssetJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessAssetOnUpload implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(AssetUploaded $event): void
    {
        // Pipeline health logging: Event received and job dispatched
        // Gated by PIPELINE_DEBUG env var to reduce production noise
        \App\Support\Logging\PipelineLogger::info('[ProcessAssetOnUpload] AssetUploaded event received', [
            'asset_id' => $event->asset->id,
            'asset_type' => $event->asset->type?->value ?? 'unknown',
            'tenant_id' => $event->asset->tenant_id,
            'brand_id' => $event->asset->brand_id,
        ]);

        // TEMPORARY: QUEUE_DEBUG (remove after confirmation)
        Log::info('[QUEUE_DEBUG] About to dispatch job', [
            'job' => ProcessAssetJob::class,
            'env' => app()->environment(),
        ]);

        // Dispatch processing job when asset is uploaded
        ProcessAssetJob::dispatch($event->asset->id);
        
        \App\Support\Logging\PipelineLogger::info('[ProcessAssetOnUpload] ProcessAssetJob dispatched', [
            'asset_id' => $event->asset->id,
        ]);
    }
}
