<?php

namespace App\Observers;

use App\Models\Asset;
use App\Services\SystemIncidentService;

/**
 * Clears operational incidents when an asset reaches terminal processing success.
 */
class AssetObserver
{
    public function updated(Asset $asset): void
    {
        if (! $asset->wasChanged('analysis_status')) {
            return;
        }
        if ($asset->analysis_status !== 'complete') {
            return;
        }

        $id = (string) $asset->id;
        app(SystemIncidentService::class)->resolveBySource('asset', $id);
        app(SystemIncidentService::class)->resolveBySource('job', $id);
    }
}
