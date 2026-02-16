<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand Compliance Score — deterministic scoring against Brand DNA rules.
 * One row per brand + asset. Upserted on metadata update / AI tagging completion.
 */
class BrandComplianceScore extends Model
{
    protected $fillable = [
        'brand_id',
        'asset_id',
        'overall_score',
        'color_score',
        'typography_score',
        'tone_score',
        'imagery_score',
        'breakdown_payload',
    ];

    protected function casts(): array
    {
        return [
            'breakdown_payload' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
