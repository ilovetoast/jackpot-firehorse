<?php

namespace App\Jobs;

use App\Services\AssetDeletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessExpiredAssetDeletionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(AssetDeletionService $deletionService): void
    {
        $assetsReadyForDeletion = $deletionService->getAssetsReadyForHardDeletion();

        Log::info('Processing expired asset deletions', [
            'count' => $assetsReadyForDeletion->count(),
        ]);

        foreach ($assetsReadyForDeletion as $asset) {
            // Dispatch hard delete job for each asset
            DeleteAssetJob::dispatch($asset->id);
        }
    }
}
