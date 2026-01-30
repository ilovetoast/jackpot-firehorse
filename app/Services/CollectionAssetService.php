<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Collection Asset Service (Collections C5).
 * Attach/detach assets to collections; validates tenant + brand match.
 * Does not touch CollectionAssetQueryService.
 */
class CollectionAssetService
{
    public function attach(Collection $collection, Asset $asset): void
    {
        if ($asset->tenant_id !== $collection->tenant_id || $asset->brand_id !== $collection->brand_id) {
            throw ValidationException::withMessages([
                'asset_id' => ['The asset must belong to the same tenant and brand as the collection.'],
            ]);
        }

        $collection->assets()->syncWithoutDetaching([$asset->id]);
    }

    public function detach(Collection $collection, Asset $asset): void
    {
        $collection->assets()->detach($asset->id);
    }
}
