<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPdfVisionExtraction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'batch_id',
        'brand_id',
        'brand_model_version_id',
        'asset_id',
        'pages_total',
        'pages_processed',
        'signals_detected',
        'early_complete',
        'extraction_json',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'extraction_json' => 'array',
            'early_complete' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
