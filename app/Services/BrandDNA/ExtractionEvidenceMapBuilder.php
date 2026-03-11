<?php

namespace App\Services\BrandDNA;

use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\BrandDNA\Extraction\SignalWeights;

/**
 * Builds field-level winner reporting for fusion observability.
 * For every final extracted field: which pipeline won, what page/section, why, what lost.
 * Only includes final_value if the winning candidate passed validation.
 */
class ExtractionEvidenceMapBuilder
{
    protected const SINGLE_VALUE_FIELDS = [
        'identity.mission', 'identity.vision', 'identity.positioning', 'identity.industry', 'identity.tagline',
        'personality.primary_archetype',
        'visual.logo_detected',
    ];

    /**
     * Build evidence_map from raw extractions and merged result.
     * Only includes final_value if the winning candidate passed validation.
     *
     * @param array $rawExtractions Array of extractions before merge (in merge order)
     * @param array $mergedResult The merged extraction result
     * @return array<string, array{final_value?: mixed, winning_source: string, winning_page?: int, winning_section?: string, winning_reason: string, candidates: array, unresolved?: bool}>
     */
    public function build(array $rawExtractions, array $mergedResult): array
    {
        $evidenceMap = [];
        $candidatesByField = $this->collectCandidates($rawExtractions);
        $validationService = app(FieldCandidateValidationService::class);

        foreach (self::SINGLE_VALUE_FIELDS as $fieldPath) {
            $finalValue = $this->getMergedValue($mergedResult, $fieldPath);
            if ($finalValue === null || $finalValue === '') {
                continue;
            }

            $validation = $validationService->validate([
                'path' => $fieldPath,
                'value' => $finalValue,
                'confidence' => 0.5,
            ]);

            if (! $validation['accepted']) {
                $evidenceMap[$fieldPath] = [
                    'winning_source' => 'unknown',
                    'winning_reason' => 'rejected_by_validation',
                    'candidates' => $this->formatCandidates($candidatesByField[$fieldPath] ?? []),
                    'unresolved' => true,
                ];
                continue;
            }

            $candidates = $candidatesByField[$fieldPath] ?? [];
            if (empty($candidates)) {
                $evidenceMap[$fieldPath] = [
                    'final_value' => $validation['normalized_value'] ?? $finalValue,
                    'winning_source' => 'unknown',
                    'winning_reason' => 'no_candidates_recorded',
                    'candidates' => [],
                ];
                continue;
            }

            $winner = $this->selectWinner($candidates, $finalValue);

            $winningPage = $winner['page'] ?? null;
            $winningPageType = $winner['page_type'] ?? null;
            if (($winner['source'] ?? '') === 'pdf_visual' && ($winningPage === null || $winningPageType === null)) {
                $fallback = $this->findPageFromPdfVisualCandidates($candidates, $finalValue);
                if ($fallback) {
                    $winningPage = $winningPage ?? $fallback['page'];
                    $winningPageType = $winningPageType ?? $fallback['page_type'];
                }
            }
            // Rule: pdf_visual winner must have page provenance unless no candidate had it
            if (($winner['source'] ?? '') === 'pdf_visual' && $winningPage === null && $winningPageType === null) {
                $fromPageExtractions = $this->findPageFromRawExtractions($rawExtractions, $fieldPath, $finalValue);
                if ($fromPageExtractions) {
                    $winningPage = $fromPageExtractions['page'];
                    $winningPageType = $fromPageExtractions['page_type'];
                }
            }

            $entry = [
                'final_value' => $validation['normalized_value'] ?? $finalValue,
                'winning_source' => $winner['source'] ?? 'unknown',
                'winning_page' => $winningPage,
                'winning_page_type' => $winningPageType,
                'winning_section' => $winner['section'] ?? null,
                'winning_reason' => $this->winningReason($winner, $candidates),
                'candidates' => $this->formatCandidates($candidates),
            ];
            if (! empty($winner['evidence'])) {
                $entry['evidence'] = is_array($winner['evidence']) ? $winner['evidence'] : [$winner['evidence']];
            }
            $evidenceMap[$fieldPath] = $entry;
        }

        // Add provenance for visual.primary_colors (array field) when from pdf_visual
        $this->addPrimaryColorsProvenance($evidenceMap, $rawExtractions, $mergedResult);

        return $evidenceMap;
    }

    /**
     * Add winning_page / winning_page_type for primary_colors when from pdf_visual.
     */
    protected function addPrimaryColorsProvenance(array &$evidenceMap, array $rawExtractions, array $mergedResult): void
    {
        $colors = $mergedResult['visual']['primary_colors'] ?? [];
        if (empty($colors)) {
            return;
        }
        $firstHex = null;
        if (is_array($colors)) {
            $first = $colors[0] ?? null;
            $firstHex = is_string($first) ? $first : ($first['hex'] ?? ($first['value'] ?? null));
        }
        if ($firstHex === null || $firstHex === '') {
            return;
        }

        foreach ($rawExtractions as $ext) {
            $pageExtractions = $ext['page_extractions_json'] ?? [];
            if (empty($pageExtractions)) {
                continue;
            }
            foreach ($pageExtractions as $pageData) {
                foreach ($pageData['extractions'] ?? [] as $ex) {
                    $path = (string) ($ex['path'] ?? $ex['field'] ?? '');
                    if (! str_contains($path, 'primary_colors') && ! str_contains($path, 'allowed_color_palette')) {
                        continue;
                    }
                    $val = $ex['value'] ?? null;
                    $hexes = is_array($val) ? $val : [$val];
                    foreach ($hexes as $h) {
                        $hex = is_string($h) ? $h : ($h['hex'] ?? $h['value'] ?? null);
                        if ($hex && $this->valuesEqual($hex, $firstHex)) {
                            $evidenceMap['visual.primary_colors'] = [
                                'final_value' => $colors,
                                'winning_source' => 'pdf_visual',
                                'winning_page' => $ex['page'] ?? $pageData['page'] ?? null,
                                'winning_page_type' => $ex['page_type'] ?? $pageData['page_type'] ?? null,
                                'winning_reason' => 'first_matching_color',
                                'candidates' => [],
                            ];
                            return;
                        }
                    }
                }
            }
        }
    }

    protected function collectCandidates(array $rawExtractions): array
    {
        $byField = [];

        foreach ($rawExtractions as $ext) {
            $source = $this->deriveSource($ext);
            $sectionSources = $ext['section_sources'] ?? [];
            $sectionQuality = $ext['_extraction_debug']['section_quality_by_path'] ?? [];
            $pageExtractions = $ext['page_extractions_json'] ?? [];

            foreach (['identity', 'personality', 'visual'] as $section) {
                $data = $ext[$section] ?? [];
                if (! is_array($data)) {
                    continue;
                }
                foreach ($data as $key => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $fieldPath = "{$section}.{$key}";
                    if (! in_array($fieldPath, self::SINGLE_VALUE_FIELDS, true)) {
                        continue;
                    }

                    $unwrapped = is_array($value) && isset($value['value']) ? $value['value'] : $value;
                    $weight = $this->computeWeight($value, $ext, $section, $key, $source, $sectionQuality[$fieldPath] ?? null);

                    $byField[$fieldPath] = $byField[$fieldPath] ?? [];

                    $candidate = [
                        'source' => $source,
                        'value' => $unwrapped,
                        'confidence' => $weight,
                        'weight' => $weight,
                        'source_type' => (is_array($value) && isset($value['source_type'])) ? $value['source_type'] : null,
                        'evidence' => (is_array($value) && isset($value['evidence'])) ? $value['evidence'] : null,
                    ];

                    if ($source === 'pdf_text') {
                        $candidate['section'] = $sectionSources[$fieldPath] ?? null;
                    }
                    if ($source === 'pdf_visual') {
                        $pageInfo = $this->findPageForField($fieldPath, $pageExtractions);
                        if ($pageInfo) {
                            $candidate['page'] = $pageInfo['page'];
                            $candidate['page_type'] = $pageInfo['page_type'];
                        }
                    }
                    $byField[$fieldPath][] = $candidate;
                }
            }
        }

        return $byField;
    }

    protected function deriveSource(array $ext): string
    {
        if (! empty($ext['page_classifications_json']) || ! empty($ext['page_extractions_json'])) {
            return 'pdf_visual';
        }
        if (! empty($ext['sources']['pdf']['extracted'] ?? false) && ! empty($ext['sources']['pdf']['section_aware'] ?? false)) {
            return 'pdf_text';
        }
        if (! empty($ext['sources']['pdf']['extracted'] ?? false)) {
            return 'pdf_visual';
        }
        if (! empty($ext['sources']['website'] ?? [])) {
            return 'website';
        }
        if (! empty($ext['sources']['materials'] ?? [])) {
            return 'materials';
        }

        return 'unknown';
    }

    protected function findPageForField(string $fieldPath, array $pageExtractions): ?array
    {
        $searchParts = explode('.', $fieldPath);
        $searchKey = end($searchParts);

        foreach ($pageExtractions as $pageData) {
            $pageNum = $pageData['page'] ?? null;
            $pageType = $pageData['page_type'] ?? null;

            foreach ($pageData['extractions'] ?? [] as $ex) {
                $exPath = (string) ($ex['path'] ?? $ex['field'] ?? '');
                if (str_contains($exPath, $searchKey) || str_contains($exPath, $fieldPath)) {
                    return [
                        'page' => $ex['page'] ?? $pageNum,
                        'page_type' => $ex['page_type'] ?? $pageType,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Fallback: when winner is pdf_visual but lacks page/page_type, get from raw page_extractions_json.
     */
    protected function findPageFromRawExtractions(array $rawExtractions, string $fieldPath, mixed $finalValue): ?array
    {
        $searchKey = explode('.', $fieldPath);
        $searchKey = end($searchKey);

        foreach ($rawExtractions as $ext) {
            $pageExtractions = $ext['page_extractions_json'] ?? [];
            foreach ($pageExtractions as $pageData) {
                $pageNum = $pageData['page'] ?? null;
                $pageType = $pageData['page_type'] ?? null;
                foreach ($pageData['extractions'] ?? [] as $ex) {
                    $exPath = (string) ($ex['path'] ?? $ex['field'] ?? '');
                    if (str_contains($exPath, $searchKey) || str_contains($exPath, $fieldPath)) {
                        $exValue = $ex['value'] ?? null;
                        if ($this->valuesEqual($exValue, $finalValue)) {
                            return [
                                'page' => $ex['page'] ?? $pageNum,
                                'page_type' => $ex['page_type'] ?? $pageType,
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Fallback: when winner is pdf_visual but lacks page/page_type, get from first matching candidate.
     */
    protected function findPageFromPdfVisualCandidates(array $candidates, mixed $finalValue): ?array
    {
        foreach ($candidates as $c) {
            if (($c['source'] ?? '') !== 'pdf_visual') {
                continue;
            }
            if (! $this->valuesEqual($c['value'] ?? null, $finalValue)) {
                continue;
            }
            if (isset($c['page']) || isset($c['page_type'])) {
                return [
                    'page' => $c['page'] ?? null,
                    'page_type' => $c['page_type'] ?? null,
                ];
            }
        }

        return null;
    }

    protected function getMergedValue(array $merged, string $fieldPath): mixed
    {
        if (! str_contains($fieldPath, '.')) {
            return null;
        }
        [$section, $key] = explode('.', $fieldPath, 2);
        $val = $merged[$section][$key] ?? null;
        if (is_array($val) && isset($val['value'])) {
            return $val['value'];
        }
        return $val;
    }

    protected function selectWinner(array $candidates, mixed $finalValue): array
    {
        $matching = array_filter($candidates, fn ($c) => $this->valuesEqual($c['value'] ?? null, $finalValue));
        if (empty($matching)) {
            return $candidates[0] ?? [];
        }
        usort($matching, fn ($a, $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));
        return $matching[0];
    }

    protected function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }
        return json_encode($a) === json_encode($b);
    }

    protected function winningReason(array $winner, array $allCandidates): string
    {
        if (($winner['source_type'] ?? '') === 'explicit') {
            return 'explicit_archetype_match';
        }
        $reasons = [];
        if (($winner['weight'] ?? 0) >= 0.9) {
            $reasons[] = 'high confidence';
        }
        if (! empty($winner['page_type'])) {
            $reasons[] = 'page type match';
        }
        if (! empty($winner['section'])) {
            $reasons[] = 'section match';
        }
        if (count($allCandidates) === 1) {
            $reasons[] = 'single candidate';
        } elseif (count($allCandidates) > 1) {
            $reasons[] = 'higher weight';
        }

        return implode(' + ', $reasons ?: ['merged']);
    }

    protected function computeWeight(mixed $value, array $ext, string $section, string $key, string $source, ?float $sectionQuality): float
    {
        if (is_array($value) && isset($value['weight'])) {
            return (float) $value['weight'];
        }
        if ($source === 'pdf_text' && $sectionQuality !== null) {
            return $sectionQuality;
        }
        if ($source === 'pdf_visual') {
            return 0.85;
        }
        if ($source === 'website') {
            return SignalWeights::WEBSITE_DETERMINISTIC;
        }
        if ($source === 'materials') {
            return SignalWeights::MATERIALS_EXPLICIT;
        }
        return 0.5;
    }

    protected function formatCandidates(array $candidates): array
    {
        return array_map(function ($c) {
            $out = [
                'source' => $c['source'] ?? 'unknown',
                'confidence' => $c['confidence'] ?? $c['weight'] ?? 0.5,
            ];
            if (! empty($c['source_type'])) {
                $out['source_type'] = $c['source_type'];
            }
            if (! empty($c['section'])) {
                $out['section'] = $c['section'];
            }
            if (isset($c['page'])) {
                $out['page'] = $c['page'];
            }
            if (! empty($c['page_type'])) {
                $out['page_type'] = $c['page_type'];
            }
            if (! empty($c['evidence'])) {
                $out['evidence'] = $c['evidence'];
            }
            return $out;
        }, $candidates);
    }
}
