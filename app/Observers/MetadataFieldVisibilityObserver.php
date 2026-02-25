<?php

namespace App\Observers;

use App\Models\MetadataFieldVisibility;
use App\Support\MetadataCache;

/**
 * Flush tenant-scoped metadata schema cache when metadata_field_visibility changes.
 */
class MetadataFieldVisibilityObserver
{
    public function saved(MetadataFieldVisibility $visibility): void
    {
        $this->flushForVisibility($visibility);
    }

    public function deleted(MetadataFieldVisibility $visibility): void
    {
        $this->flushForVisibility($visibility);
    }

    private function flushForVisibility(MetadataFieldVisibility $visibility): void
    {
        MetadataCache::bumpVersion($visibility->tenant_id);
    }
}
