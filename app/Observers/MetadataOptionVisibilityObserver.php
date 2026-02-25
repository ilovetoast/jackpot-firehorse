<?php

namespace App\Observers;

use App\Models\MetadataOptionVisibility;
use App\Support\MetadataCache;

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
        MetadataCache::bumpVersion($visibility->tenant_id);
    }
}
