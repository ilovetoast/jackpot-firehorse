<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandResearchSnapshot extends Model
{
    protected $fillable = [
        'brand_id',
        'brand_model_version_id',
        'source_url',
        'status',
        'snapshot',
        'suggestions',
        'coherence',
        'alignment',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'suggestions' => 'array',
            'coherence' => 'array',
            'alignment' => 'array',
        ];
    }

    /**
     * Get the brand that owns this snapshot.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the brand model version associated with this snapshot (nullable).
     */
    public function brandModelVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class);
    }
}
