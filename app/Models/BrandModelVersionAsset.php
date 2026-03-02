<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BrandModelVersionAsset extends Pivot
{
    protected $table = 'brand_model_version_assets';

    public $incrementing = true;

    protected $fillable = [
        'brand_model_version_id',
        'asset_id',
        'builder_context',
        'reference_type',
    ];

    /**
     * Get the brand model version.
     */
    public function brandModelVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class);
    }

    /**
     * Get the asset.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
