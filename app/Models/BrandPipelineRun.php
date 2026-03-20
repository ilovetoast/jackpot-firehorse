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

    /**
     * Max raw PDF file size (bytes) that can be sent via Anthropic's base64 document API.
     * 32MB request limit / 1.33 base64 overhead ≈ 24MB, with margin for prompt + JSON.
     */
    public const MAX_VISION_PDF_BYTES = 20 * 1024 * 1024; // 20 MB

    /**
     * Determine the correct extraction mode for a PDF asset based on page count.
     * Large PDFs (>20MB) still use vision mode — ClaudePdfExtractionService automatically
     * routes them through Anthropic's Files API instead of inline base64.
     */
    public static function resolveExtractionMode(Asset $asset): string
    {
        try {
            $pageCount = app(\App\Services\PdfPageRenderingService::class)->getPdfPageCount($asset, true);

            return $pageCount > 1 ? self::EXTRACTION_MODE_VISION : self::EXTRACTION_MODE_TEXT;
        } catch (\Throwable $e) {
            return self::EXTRACTION_MODE_VISION;
        }
    }

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
