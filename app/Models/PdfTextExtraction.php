<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfTextExtraction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_PDFTOTEXT = 'pdftotext';
    public const SOURCE_TESSERACT = 'tesseract';
    public const SOURCE_TEXTRACT = 'textract';

    /** Maximum characters to return for AI/LLM stage; beyond this text should be truncated. */
    public const AI_MAX_CHARS = 500_000;

    protected $fillable = [
        'asset_id',
        'asset_version_id',
        'extracted_text',
        'character_count',
        'extraction_source',
        'status',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'character_count' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assetVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'asset_version_id');
    }

    public function aiStructures(): HasMany
    {
        return $this->hasMany(PdfTextAiStructure::class, 'pdf_text_extraction_id');
    }

    /**
     * Text suitable for AI/LLM stage: truncated to AI_MAX_CHARS when over limit.
     */
    public function getTextForAi(int $maxChars = self::AI_MAX_CHARS): string
    {
        $text = $this->extracted_text ?? '';
        $len = mb_strlen($text);

        return $len > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING || $this->status === self::STATUS_PROCESSING;
    }
}
