<?php

namespace App\Observers;

use App\Models\MetadataField;
use App\Support\MetadataCache;
use Illuminate\Support\Facades\Cache;

/**
 * Flush tenant-scoped metadata schema cache when metadata_fields change.
 */
class MetadataFieldObserver
{
    public function saved(MetadataField $field): void
    {
        $this->flushForField($field);
    }

    public function deleted(MetadataField $field): void
    {
        $this->flushForField($field);
    }

    private function flushForField(MetadataField $field): void
    {
        if (! method_exists(Cache::getStore(), 'tags')) {
            return;
        }

        $tenantId = $field->tenant_id;
        if ($tenantId !== null) {
            Cache::tags(MetadataCache::tags($tenantId))->flush();
            return;
        }

        MetadataCache::flushGlobal();
    }
}
