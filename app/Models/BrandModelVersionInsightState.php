<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandModelVersionInsightState extends Model
{
    protected $fillable = [
        'brand_model_version_id',
        'source_snapshot_id',
        'dismissed',
        'accepted',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed' => 'array',
            'accepted' => 'array',
            'viewed_at' => 'datetime',
        ];
    }

    public function brandModelVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class);
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(BrandResearchSnapshot::class, 'source_snapshot_id');
    }
}
