<?php

namespace App\Observers;

use App\Models\MetadataOptionVisibility;
use App\Support\MetadataCache;
use Illuminate\Support\Facades\Cache;

/**
 * Flush tenant-scoped metadata schema cache when metadata_option_visibility changes.
 */
class MetadataOptionVisibilityObserver
{
    public function saved(MetadataOptionVisibility $visibility): void
    {
        $this->flushForVisibility($visibility);
    }

    public function deleted(MetadataOptionVisibility $visibility): void
    {
        $this->flushForVisibility($visibility);
    }

    private function flushForVisibility(MetadataOptionVisibility $visibility): void
    {
        if (! method_exists(Cache::getStore(), 'tags')) {
            return;
        }

        $tenantId = $visibility->tenant_id;
        Cache::tags(MetadataCache::tags($tenantId))->flush();
    }
}
