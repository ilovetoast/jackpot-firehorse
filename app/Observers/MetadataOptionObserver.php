<?php

namespace App\Observers;

use App\Models\MetadataOption;
use App\Support\MetadataCache;
use Illuminate\Support\Facades\Cache;

/**
 * Flush tenant-scoped metadata schema cache when metadata_options change.
 */
class MetadataOptionObserver
{
    public function saved(MetadataOption $option): void
    {
        $this->flushForOption($option);
    }

    public function deleted(MetadataOption $option): void
    {
        $this->flushForOption($option);
    }

    private function flushForOption(MetadataOption $option): void
    {
        if (! method_exists(Cache::getStore(), 'tags')) {
            return;
        }

        $field = $option->relationLoaded('metadataField')
            ? $option->metadataField
            : \App\Models\MetadataField::find($option->metadata_field_id);
        $tenantId = $field?->tenant_id;

        if ($tenantId !== null) {
            Cache::tags(MetadataCache::tags($tenantId))->flush();
            return;
        }

        MetadataCache::flushGlobal();
    }
}
