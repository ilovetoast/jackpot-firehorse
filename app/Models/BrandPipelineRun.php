<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPipelineRun extends Model
{
    public const STAGE_INIT = 'init';

    public const STAGE_ANALYZING = 'analyzing';

    public const STAGE_COMPLETED = 'completed';

    public const STAGE_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const EXTRACTION_MODE_TEXT = 'text';

    public const EXTRACTION_MODE_VISION = 'vision';

    protected $fillable = [
        'brand_id',
        'brand_model_version_id',
        'asset_id',
        'stage',
        'pages_total',
        'pages_processed',
        'extraction_mode',
        'status',
        'error_message',
        'merged_extraction_json',
        'raw_api_response_json',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'merged_extraction_json' => 'array',
            'raw_api_response_json' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function brandModelVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function snapshot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BrandPipelineSnapshot::class);
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->pages_total <= 0) {
            return $this->stage === self::STAGE_COMPLETED ? 100 : 0;
        }

        return (int) min(100, round(100 * $this->pages_processed / $this->pages_total));
    }
}
