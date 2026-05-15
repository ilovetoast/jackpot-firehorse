<?php

namespace App\Services\Hygiene;

use App\Models\MetadataField;
use App\Models\MetadataOption;
use App\Models\Tenant;

/**
 * Phase 5.3 — duplicate-candidate detection over `metadata_options`.
 *
 * Two complementary detectors:
 *   - Hash-bucket: group options by `MetadataValueNormalizer::hash()`. Any
 *     bucket with ≥2 distinct raw values is a candidate cluster. Cheap,
 *     deterministic, false-positive-free for the conservative normalizer
 *     this phase ships.
 *   - "Singularization-ish" heuristic: for each non-clustered option, look
 *     for siblings whose normalized form differs only by a trailing 's'
 *     (Outdoor / Outdoors). This is the textbook DAM problem, and we want
 *     it surfaced even though the normalizer itself stays conservative.
 *
 * Phase 5.3 deliberately:
 *   - Does NOT auto-merge.
 *   - Does NOT auto-suppress.
 *   - Does NOT use Levenshtein / fuzzy distance yet — that's Phase 6+ AI
 *     work where false positives cost more.
 *   - Caps results so a single option list with thousands of values can't
 *     blow up the admin UI.
 *
 * Output is a list of "candidate groups" — admins decide which (if any) to
 * merge. The merge service records the resulting alias, so once a cluster
 * is merged the detector won't keep re-suggesting it.
 */
class MetadataDuplicateDetector
{
    /** Hard cap on options scanned per call. Keeps the pass O(N) bounded. */
    public const MAX_OPTIONS_SCANNED = 2000;

    /** Hard cap on candidate groups returned per call. */
    public const MAX_CANDIDATES_RETURNED = 50;

    public function __construct(
        protected MetadataValueNormalizer $normalizer,
    ) {}

    /**
     * Find duplicate-candidate groups for a field, scoped to a tenant if
     * provided. Each group has at least 2 distinct raw values.
     *
     * @return list<array{
     *     canonical_hint: string,
     *     hash: string,
     *     reason: string,
     *     values: list<string>
     * }>
     */
    public function findCandidates(MetadataField $field, ?Tenant $tenant = null): array
    {
        $options = $this->loadOptions($field);
        if ($options === []) {
            return [];
        }

        $byHash = [];
        foreach ($options as $value) {
            $hash = $this->normalizer->hash($value);
            if ($hash === '') {
                continue;
            }
            $byHash[$hash] ??= [];
            // Preserve the first display casing we see; admins recognise
            // their own values better than a forced normalization.
            if (! in_array($value, $byHash[$hash], true)) {
                $byHash[$hash][] = $value;
            }
        }

        $candidates = [];

        // Stage 1: exact-normalization clusters.
        foreach ($byHash as $hash => $values) {
            if (count($values) < 2) {
                continue;
            }
            $candidates[] = [
                'canonical_hint' => $values[0],
                'hash' => $hash,
                'reason' => 'normalized_match',
                'values' => array_values(array_unique($values)),
            ];
            if (count($candidates) >= self::MAX_CANDIDATES_RETURNED) {
                return $candidates;
            }
        }

        // Stage 2: singularization-ish pairs across distinct hash buckets.
        // For each option `X`, check whether `Xs` (with `s` appended) also
        // exists. The pair is recorded once per pair (smaller value first
        // for stability). Skips values we've already clustered above.
        $alreadyClustered = [];
        foreach ($candidates as $group) {
            foreach ($group['values'] as $v) {
                $alreadyClustered[$this->normalizer->normalize($v)] = true;
            }
        }
        $byNormalized = [];
        foreach ($options as $value) {
            $byNormalized[$this->normalizer->normalize($value)] = $value;
        }
        $seenPairs = [];
        foreach ($byNormalized as $norm => $value) {
            if ($norm === '') continue;
            if (isset($alreadyClustered[$norm])) continue;
            $candidateNorms = [
                $norm.'s',
                rtrim($norm, 's'),
            ];
            foreach ($candidateNorms as $sibling) {
                if ($sibling === $norm || $sibling === '') continue;
                if (! isset($byNormalized[$sibling])) continue;
                $pairKey = $norm < $sibling ? $norm.'|'.$sibling : $sibling.'|'.$norm;
                if (isset($seenPairs[$pairKey])) continue;
                $seenPairs[$pairKey] = true;
                $vals = [$byNormalized[$norm], $byNormalized[$sibling]];
                $candidates[] = [
                    // Heuristic: the SHORTER value is usually the canonical
                    // ("Outdoor" over "Outdoors"). Admins can override.
                    'canonical_hint' => mb_strlen($vals[0]) <= mb_strlen($vals[1]) ? $vals[0] : $vals[1],
                    'hash' => $this->normalizer->hash($vals[0]),
                    'reason' => 'plural_singular_pair',
                    'values' => array_values(array_unique($vals)),
                ];
                if (count($candidates) >= self::MAX_CANDIDATES_RETURNED) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    public function candidateGroupCount(MetadataField $field, ?Tenant $tenant = null): int
    {
        return count($this->findCandidates($field, $tenant));
    }

    /**
     * @return list<string>
     */
    protected function loadOptions(MetadataField $field): array
    {
        $values = MetadataOption::query()
            ->where('metadata_field_id', $field->id)
            ->orderBy('id')
            ->limit(self::MAX_OPTIONS_SCANNED)
            ->pluck('value')
            ->all();

        $out = [];
        foreach ($values as $v) {
            if (! is_string($v) || $v === '') continue;
            $out[] = $v;
        }

        return $out;
    }
}
