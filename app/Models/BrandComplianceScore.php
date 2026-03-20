<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Table `brand_compliance_scores` is frozen for audit/migration only — do not read or write in application code.
 *             Use {@see BrandIntelligenceScore} / {@see \App\Services\BrandIntelligence\BrandIntelligenceEngine}.
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
        'evaluation_status',
        'alignment_confidence',
        'debug_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'breakdown_payload' => 'array',
            'debug_snapshot' => 'array',
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
