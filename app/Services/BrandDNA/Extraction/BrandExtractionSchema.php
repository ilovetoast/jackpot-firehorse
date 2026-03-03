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
