<?php

namespace App\Enums;

/**
 * How a piece of brand-alignment evidence was obtained.
 *
 * Hard sources (visual_detection, visual_similarity, extracted_text, palette_extraction)
 * can drive "aligned" status.  Readiness sources (metadata_hint, configuration_only) cannot.
 */
enum EvidenceSource: string
{
    case VISUAL_DETECTION = 'visual_detection';
    case VISUAL_SIMILARITY = 'visual_similarity';
    case EXTRACTED_TEXT = 'extracted_text';
    case PALETTE_EXTRACTION = 'palette_extraction';
    case METADATA_HINT = 'metadata_hint';
    case CONFIGURATION_ONLY = 'configuration_only';
    case TRANSCRIPT = 'transcript';
    case AI_ANALYSIS = 'ai_analysis';
    case NOT_EVALUABLE = 'not_evaluable';

    public function defaultWeight(): EvidenceWeight
    {
        return match ($this) {
            self::VISUAL_DETECTION,
            self::VISUAL_SIMILARITY,
            self::EXTRACTED_TEXT,
            self::PALETTE_EXTRACTION => EvidenceWeight::HARD,

            self::TRANSCRIPT,
            self::AI_ANALYSIS => EvidenceWeight::SOFT,

            self::METADATA_HINT,
            self::CONFIGURATION_ONLY,
            self::NOT_EVALUABLE => EvidenceWeight::READINESS,
        };
    }

    public function canDriveAligned(): bool
    {
        return match ($this) {
            self::VISUAL_DETECTION,
            self::VISUAL_SIMILARITY,
            self::EXTRACTED_TEXT,
            self::PALETTE_EXTRACTION => true,
            default => false,
        };
    }
}
