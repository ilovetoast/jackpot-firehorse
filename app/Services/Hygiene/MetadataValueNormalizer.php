<?php

namespace App\Services\Hygiene;

/**
 * Phase 5.3 — pure normalization helpers.
 *
 * Stateless, no DB. Used by:
 *   - MetadataCanonicalizationService (compute normalization_hash on alias
 *     write, equivalence checks).
 *   - MetadataDuplicateDetector (bucket options that might be duplicates).
 *   - Future quality scoring jobs.
 *
 * Strict goals:
 *   - Deterministic: same input → same output across PHP versions.
 *   - Reversible-friendly: `normalize` MUST NOT mutate display labels in
 *     storage. The original value still goes into `value_json`; the
 *     normalizer only powers MATCHING / clustering / suggestions.
 *   - Conservative: NO singularization, NO stemming, NO accent removal yet.
 *     We can add those later behind config flags. Phase 5.3 ships only the
 *     transforms whose false-positive rate is well-understood:
 *       1. Trim whitespace.
 *       2. Lowercase (mb_strtolower for unicode safety).
 *       3. Collapse separator chars (`-` `_` `/` `.` `:` `,`) to single space.
 *       4. Collapse runs of whitespace to a single space.
 *
 *   Examples:
 *     "Outdoor"     → "outdoor"
 *     "out-door"    → "out door"   (non-equivalent — preserves visual intent)
 *     "Out Door"    → "out door"   (equivalent to the dashed form)
 *     " outdoor  "  → "outdoor"
 *     "Nature/Photo"→ "nature photo"
 *     "OUTDOORS"    → "outdoors"   (NOT equivalent to "outdoor" — that's a
 *                                   duplicate-detector heuristic, not pure
 *                                   normalization)
 *
 * If callers want stricter clustering ("Outdoor" ≡ "Outdoors") that is the
 * job of {@see \App\Services\Hygiene\MetadataDuplicateDetector}, not this
 * normalizer.
 */
class MetadataValueNormalizer
{
    /** Characters that get collapsed to a single space before whitespace runs are squashed. */
    private const SEPARATOR_CLASS = '/[\-_\/\.\:\,]+/u';

    /**
     * Normalize a raw value. Empty / non-scalar inputs return an empty
     * string so callers don't have to nullcheck before hashing.
     */
    public function normalize(mixed $raw): string
    {
        if ($raw === null || $raw === false) {
            return '';
        }
        if (is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }
        if (! is_scalar($raw)) {
            return '';
        }

        $value = (string) $raw;
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace(self::SEPARATOR_CLASS, ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Two values are "equivalent" iff their normalized forms match exactly.
     * This is the relation `MetadataCanonicalizationService::addAlias` uses
     * to detect "the alias and canonical are visually the same value" and
     * the duplicate detector uses to bucket option rows.
     */
    public function equivalent(mixed $a, mixed $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        if ($na === '' && $nb === '') {
            return false; // Two empty values are not equivalent for hygiene purposes.
        }

        return $na === $nb;
    }

    /**
     * Stable hex hash suitable for use as an indexed `normalization_hash`
     * column. We pick xxh128 → 32 hex chars when available (fast, low
     * collision), and fall back to sha256 truncated to 32 chars otherwise
     * so older PHP/HHVM environments still work without code changes.
     *
     * Returns an empty string for empty input so callers can store NULL
     * or skip indexing on degenerate values.
     */
    public function hash(mixed $raw): string
    {
        $normalized = $this->normalize($raw);
        if ($normalized === '') {
            return '';
        }

        $algos = function_exists('hash_algos') ? hash_algos() : [];
        if (in_array('xxh128', $algos, true)) {
            return hash('xxh128', $normalized);
        }

        return substr(hash('sha256', $normalized), 0, 32);
    }
}
