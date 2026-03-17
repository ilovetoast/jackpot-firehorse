<?php

namespace App\Services\BrandDNA\Extraction;

/**
 * Validates extracted values to reject junk, fragments, and low-quality OCR output.
 * Used by SectionAwareBrandGuidelinesProcessor, AutoApplyHighConfidenceSuggestions, and suggestion generation.
 */
class ExtractionQualityValidator
{
    protected const MIN_MEANINGFUL_CHARS = 12;

    protected const MIN_ALPHABETIC_TOKENS = 2;

    protected const GENERIC_PATTERNS = [
        '/^(?:n\/?a|na|none|tbd|todo|placeholder|example|lorem|test)\s*$/i',
        '/^(?:example|lorem|placeholder|test)\s+(?:text|content)\s*$/i',
        '/^within a category\.?\s*$/i',
        '/^[A-Z]{2,10}\s*$/',
        '/^[a-z]+\s+[a-z]+\s+[a-z]+\.?\s*$/',
        '/^[A-Z]+\s+within a category\.?\s*$/i',
        '/^[A-Z]+\s{2,}within a category\.?\s*$/i',
    ];

    /**
     * Reject low-quality extracted values (fragments, OCR junk, placeholders).
     */
    public static function isLowQualityExtractedValue(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }

        if (strlen($trimmed) < self::MIN_MEANINGFUL_CHARS) {
            return true;
        }

        if (preg_match('/\s{3,}/', $trimmed)) {
            return true;
        }

        $tokens = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        $alphabetic = array_filter($tokens, static fn ($t) => preg_match('/[A-Za-z]{2,}/', $t));
        if (count($alphabetic) < self::MIN_ALPHABETIC_TOKENS) {
            return true;
        }

        foreach (self::GENERIC_PATTERNS as $p) {
            if (preg_match($p, $trimmed)) {
                return true;
            }
        }

        if (preg_match('/^[^.!?]*\s+within\s+[^.!?]*\.?\s*$/i', $trimmed)) {
            return true;
        }

        if (preg_match('/^[A-Z\s]{2,}\s+[a-z]+/u', $trimmed) && ! preg_match('/[.!?]$/', $trimmed)) {
            return true;
        }

        if (preg_match('/[^\p{L}\p{N}\s.,;:!?\'\"\-&\/()]/u', $trimmed)) {
            return true;
        }

        return false;
    }
}
