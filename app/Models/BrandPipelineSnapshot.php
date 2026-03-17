<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPipelineSnapshot extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'brand_pipeline_run_id',
        'brand_id',
        'brand_model_version_id',
        'snapshot',
        'suggestions',
        'coherence',
        'alignment',
        'report',
        'sections_json',
        'status',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'suggestions' => 'array',
            'coherence' => 'array',
            'alignment' => 'array',
            'report' => 'array',
            'sections_json' => 'array',
        ];
    }

    public function brandPipelineRun(): BelongsTo
    {
        return $this->belongsTo(BrandPipelineRun::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function brandModelVersion(): BelongsTo
    {
        return $this->belongsTo(BrandModelVersion::class);
    }
}
