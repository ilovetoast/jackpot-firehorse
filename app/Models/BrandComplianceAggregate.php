<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: Brand compliance aggregates for execution alignment overview.
 * One row per brand. High score >= 85, low score < 60.
 */
class BrandComplianceAggregate extends Model
{
    protected $fillable = [
        'brand_id',
        'avg_score',
        'execution_count',
        'high_score_count',
        'low_score_count',
        'last_scored_at',
    ];

    protected function casts(): array
    {
        return [
            'avg_score' => 'float',
            'last_scored_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
