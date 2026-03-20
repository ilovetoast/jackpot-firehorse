<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandIntelligenceScore extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'execution_id',
        'asset_id',
        'overall_score',
        'confidence',
        'level',
        'breakdown_json',
        'ai_used',
        'engine_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'breakdown_json' => 'array',
            'confidence' => 'float',
            'ai_used' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function isExecutionScore(): bool
    {
        return $this->execution_id !== null;
    }

    public function isAssetScore(): bool
    {
        return $this->asset_id !== null;
    }
}
