<?php

namespace App\Enums;

/**
 * Families of evidence that can feed a DimensionResult.
 *
 * Diversity tracking uses this to dampen overall confidence when a result
 * is built from only one kind of evidence (e.g. three correlated VLM fields
 * all mapping to PIXEL_VISUAL). See AlignmentScoreDeriver for the caps.
 *
 * The taxonomy is deliberately small -- four families -- to keep the
 * diversity signal interpretable by humans in the admin trace.
 */
enum SignalFamily: string
{
    /** Text we actually read from the asset (OCR, pdftotext, transcript). */
    case TEXT_DERIVED = 'text_derived';

    /** Pixel-level analysis of the asset: VLM vision outputs, palette extraction, logo detection. */
    case PIXEL_VISUAL = 'pixel_visual';

    /** CLIP / embedding similarity against approved brand references. */
    case REFERENCE_SIMILARITY = 'reference_similarity';

    /** Readiness-only signals: brand DNA config, campaign override, file metadata. */
    case METADATA_CONFIG = 'metadata_config';

    /**
     * Map an EvidenceSource to its signal family.
     *
     * Note: {@see EvidenceSource::NOT_EVALUABLE} has no meaningful family and
     * returns METADATA_CONFIG by convention. Callers normally filter out
     * readiness-only evidence before counting diversity.
     */
    public static function fromEvidenceSource(EvidenceSource $source): self
    {
        return match ($source) {
            EvidenceSource::EXTRACTED_TEXT,
            EvidenceSource::TRANSCRIPT => self::TEXT_DERIVED,

            EvidenceSource::VISUAL_DETECTION,
            EvidenceSource::PALETTE_EXTRACTION,
            EvidenceSource::AI_ANALYSIS => self::PIXEL_VISUAL,

            EvidenceSource::VISUAL_SIMILARITY => self::REFERENCE_SIMILARITY,

            EvidenceSource::METADATA_HINT,
            EvidenceSource::CONFIGURATION_ONLY,
            EvidenceSource::NOT_EVALUABLE => self::METADATA_CONFIG,
        };
    }
}
