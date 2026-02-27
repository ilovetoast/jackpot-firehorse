<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfTextAiStructure extends Model
{
    protected $fillable = [
        'asset_id',
        'pdf_text_extraction_id',
        'ai_model',
        'structured_json',
        'summary',
        'confidence_score',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'structured_json' => 'array',
            'confidence_score' => 'float',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(PdfTextExtraction::class, 'pdf_text_extraction_id');
    }

    public function isGuideline(): bool
    {
        return ($this->structured_json['document_type'] ?? null) === 'brand_guideline';
    }
}
