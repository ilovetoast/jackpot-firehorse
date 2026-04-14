<?php

namespace App\Enums;

/**
 * Strength class for a piece of brand-alignment evidence.
 *
 * HARD   – strongest, defensible (visual detection, OCR match, palette ΔE, embedding above threshold).
 * SOFT   – probabilistic / similarity-based (AI mood analysis, weak similarity, tone inference).
 * READINESS – configuration presence only; never an outcome (palette configured, fonts defined, filename match).
 */
enum EvidenceWeight: string
{
    case HARD = 'hard';
    case SOFT = 'soft';
    case READINESS = 'readiness';
}
