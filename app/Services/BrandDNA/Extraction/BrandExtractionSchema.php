<?php

namespace App\Services\BrandDNA\Extraction;

class BrandExtractionSchema
{
    protected const SINGLE_VALUE_FIELDS = [
        'identity.mission', 'identity.vision', 'identity.positioning', 'identity.industry', 'identity.tagline',
        'personality.primary_archetype',
        'visual.logo_detected',
    ];

    public static function empty(): array
    {
        return [
            'identity' => [
                'mission' => null,
                'vision' => null,
                'positioning' => null,
                'industry' => null,
                'tagline' => null,
                'beliefs' => [],
                'values' => [],
            ],
            'personality' => [
                'primary_archetype' => null,
                'traits' => [],
                'tone_keywords' => [],
            ],
            'visual' => [
                'primary_colors' => [],
                'secondary_colors' => [],
                'fonts' => [],
                'logo_detected' => null,
            ],
            'explicit_signals' => [
                'archetype_declared' => false,
                'mission_declared' => false,
                'positioning_declared' => false,
            ],
            'sources' => [
                'pdf' => [],
                'website' => [],
                'materials' => [],
            ],
            'confidence' => 0.0,
            'conflicts' => [],
        ];
    }

    /**
     * Merge extractions. Higher weight wins for single-value fields.
     * Losing candidates stored in conflicts array.
     */
    public static function merge(array ...$extractions): array
    {
        $result = self::empty();
        $conflicts = [];
        $winners = [];

        foreach ($extractions as $ext) {
            $merged = self::mergeOne($result, $ext, $conflicts, $winners);
            $result = $merged['result'];
            $conflicts = $merged['conflicts'];
            $winners = $merged['winners'];
        }

        $result['conflicts'] = array_values($conflicts);
        return $result;
    }

    protected static function mergeOne(array $base, array $ext, array $conflicts, array $winners): array
    {
        foreach (['identity', 'personality', 'visual'] as $section) {
            if (! isset($ext[$section]) || ! is_array($ext[$section])) {
                continue;
            }
            foreach ($ext[$section] as $key => $incoming) {
                $fieldPath = "{$section}.{$key}";
                $isSingleValue = in_array($fieldPath, self::SINGLE_VALUE_FIELDS);

                if ($incoming === null || $incoming === []) {
                    continue;
                }

                if ($isSingleValue) {
                    $incomingWrapped = self::ensureWrapped($incoming, $ext, $section, $key);
                    $incomingWeight = SignalWeights::getWeight($incomingWrapped);
                    $incomingValue = SignalWeights::unwrap($incomingWrapped);

                    $existingWrapped = $winners[$fieldPath] ?? null;
                    if ($existingWrapped !== null) {
                        $existingWeight = SignalWeights::getWeight($existingWrapped);
                        $existingValue = SignalWeights::unwrap($existingWrapped);

                        if (self::valuesDiffer($existingValue, $incomingValue)) {
                            $winner = $incomingWeight >= $existingWeight ? $incomingWrapped : $existingWrapped;
                            $loser = $incomingWeight >= $existingWeight ? $existingWrapped : $incomingWrapped;
                            $winners[$fieldPath] = $winner;
                            $base[$section][$key] = SignalWeights::unwrap($winner);
                            if ($incomingWeight > 0.6 && $existingWeight > 0.6) {
                                $conflicts[$fieldPath] = [
                                    'field' => $fieldPath,
                                    'candidates' => [
                                        ['value' => SignalWeights::unwrap($winner), 'source' => $winner['source'] ?? 'unknown', 'weight' => $winner['weight'] ?? 0],
                                        ['value' => SignalWeights::unwrap($loser), 'source' => $loser['source'] ?? 'unknown', 'weight' => $loser['weight'] ?? 0],
                                    ],
                                    'recommended' => SignalWeights::unwrap($winner),
                                    'recommended_weight' => SignalWeights::getWeight($winner),
                                ];
                            }
                            continue;
                        }
                        continue;
                    }

                    $winners[$fieldPath] = $incomingWrapped;
                    $base[$section][$key] = $incomingValue;
                } else {
                    $baseArr = $base[$section][$key] ?? [];
                    $incomingArr = is_array($incoming) ? $incoming : [$incoming];
                    $baseArr = array_values(array_unique(array_merge($baseArr, self::unwrapArray($incomingArr))));
                    $base[$section][$key] = $baseArr;
                }
            }
        }

        foreach (['explicit_signals', 'sources'] as $section) {
            if (! isset($ext[$section]) || ! is_array($ext[$section])) {
                continue;
            }
            foreach ($ext[$section] as $key => $value) {
                if ($value === null && $section === 'explicit_signals') {
                    continue;
                }
                if (is_array($value) && isset($base[$section][$key]) && is_array($base[$section][$key]) && $section === 'sources') {
                    $base[$section][$key] = array_merge($base[$section][$key], $value);
                } else {
                    $base[$section][$key] = $value ?? $base[$section][$key] ?? null;
                }
            }
        }

        if (isset($ext['confidence']) && is_numeric($ext['confidence'])) {
            $base['confidence'] = max($base['confidence'], (float) $ext['confidence']);
        }

        if (! empty($ext['sections'])) {
            $base['sections'] = $ext['sections'];
        }
        if (! empty($ext['section_sources'])) {
            $base['section_sources'] = array_merge($base['section_sources'] ?? [], $ext['section_sources']);
        }
        if (! empty($ext['toc_map'])) {
            $base['toc_map'] = array_merge($base['toc_map'] ?? [], $ext['toc_map']);
        }
        if (! empty($ext['_extraction_debug'])) {
            $baseDebug = $base['_extraction_debug'] ?? [];
            $extDebug = $ext['_extraction_debug'];
            $base['_extraction_debug'] = [
                'suppressed_lines' => array_values(array_unique(array_merge(
                    $baseDebug['suppressed_lines'] ?? [],
                    $extDebug['suppressed_lines'] ?? []
                ))),
                'suppressed_sections' => $extDebug['suppressed_sections'] ?? $baseDebug['suppressed_sections'] ?? [],
                'collapsed_sections' => $extDebug['collapsed_sections'] ?? $baseDebug['collapsed_sections'] ?? [],
                'section_count_raw' => $extDebug['section_count_raw'] ?? $baseDebug['section_count_raw'] ?? null,
                'section_count_usable' => $extDebug['section_count_usable'] ?? $baseDebug['section_count_usable'] ?? null,
                'section_count_suppressed' => $extDebug['section_count_suppressed'] ?? $baseDebug['section_count_suppressed'] ?? null,
                'rejected_values' => array_merge($baseDebug['rejected_values'] ?? [], $extDebug['rejected_values'] ?? []),
                'section_metadata' => $extDebug['section_metadata'] ?? $baseDebug['section_metadata'] ?? [],
                'section_quality_by_path' => array_merge($baseDebug['section_quality_by_path'] ?? [], $extDebug['section_quality_by_path'] ?? []),
                'auto_apply_blocked' => array_merge($baseDebug['auto_apply_blocked'] ?? [], $extDebug['auto_apply_blocked'] ?? []),
            ];
        }

        return ['result' => $base, 'conflicts' => $conflicts, 'winners' => $winners];
    }

    protected static function ensureWrapped(mixed $val, array $ext, string $section, string $key): array
    {
        if (is_array($val) && isset($val['value'], $val['source'], $val['weight'])) {
            return $val;
        }
        $source = ! empty($ext['sources']['pdf']['extracted'] ?? false) ? 'pdf'
            : (! empty($ext['sources']['website']) ? 'website' : 'materials');
        $explicit = $ext['explicit_signals'] ?? [];
        $isExplicit = ($key === 'primary_archetype' && ($explicit['archetype_declared'] ?? false))
            || ($key === 'mission' && ($explicit['mission_declared'] ?? false))
            || ($key === 'positioning' && ($explicit['positioning_declared'] ?? false));
        $weight = $source === 'pdf' ? ($isExplicit ? SignalWeights::PDF_EXPLICIT : SignalWeights::PDF_INFERRED)
            : ($source === 'website' ? SignalWeights::WEBSITE_DETERMINISTIC : SignalWeights::MATERIALS_EXPLICIT);
        return SignalWeights::wrap($source, $isExplicit ? 'explicit' : 'inferred', $weight, $val);
    }

    protected static function valuesDiffer(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a !== (string) $b;
        }
        return json_encode($a) !== json_encode($b);
    }

    protected static function unwrapArray(array $arr): array
    {
        $out = [];
        foreach ($arr as $item) {
            $out[] = is_array($item) && isset($item['value']) ? $item['value'] : $item;
        }
        return $out;
    }
}
