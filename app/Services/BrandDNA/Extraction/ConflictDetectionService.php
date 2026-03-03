<?php

namespace App\Services\BrandDNA\Extraction;

/**
 * Detects conflicts when multiple extraction sources disagree on the same canonical field.
 * Values must differ and both weights > 0.6 to create a conflict record.
 */
class ConflictDetectionService
{
    protected const SINGLE_VALUE_FIELDS = [
        'identity.mission', 'identity.vision', 'identity.positioning', 'identity.industry', 'identity.tagline',
        'personality.primary_archetype',
        'visual.logo_detected',
    ];

    public function detect(array $extractions): array
    {
        $signalsByField = [];

        foreach ($extractions as $ext) {
            $this->collectSignals($ext, $signalsByField);
        }

        $conflicts = [];
        foreach ($signalsByField as $fieldPath => $signals) {
            $conflict = $this->buildConflict($fieldPath, $signals);
            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    protected function collectSignals(array $ext, array &$signalsByField): void
    {
        foreach (['identity', 'personality', 'visual'] as $section) {
            if (! isset($ext[$section]) || ! is_array($ext[$section])) {
                continue;
            }
            foreach ($ext[$section] as $key => $val) {
                $fieldPath = "{$section}.{$key}";
                if (! in_array($fieldPath, self::SINGLE_VALUE_FIELDS)) {
                    continue;
                }
                if ($val === null || $val === '') {
                    continue;
                }
                $wrapped = is_array($val) && isset($val['value'], $val['weight'])
                    ? $val
                    : SignalWeights::wrap(
                        $this->inferSource($ext),
                        ($ext['explicit_signals'][$key === 'primary_archetype' ? 'archetype_declared' : ($key === 'mission' ? 'mission_declared' : 'positioning_declared')] ?? false) ? 'explicit' : 'inferred',
                        $this->inferWeight($ext, $key),
                        $val
                    );
                $value = SignalWeights::unwrap($wrapped);
                $weight = SignalWeights::getWeight($wrapped);
                $signalsByField[$fieldPath][] = [
                    'value' => $value,
                    'source' => $wrapped['source'] ?? 'unknown',
                    'weight' => $weight,
                ];
            }
        }
    }

    protected function buildConflict(string $fieldPath, array $signals): ?array
    {
        $filtered = array_filter($signals, fn ($s) => $s['weight'] > 0.6);
        if (count($filtered) < 2) {
            return null;
        }

        $unique = [];
        foreach ($filtered as $s) {
            $key = is_scalar($s['value']) ? (string) $s['value'] : json_encode($s['value']);
            if (! isset($unique[$key])) {
                $unique[$key] = $s;
            } elseif ($s['weight'] > ($unique[$key]['weight'] ?? 0)) {
                $unique[$key] = $s;
            }
        }

        if (count($unique) < 2) {
            return null;
        }

        $candidates = array_values($unique);
        usort($candidates, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        $recommended = $candidates[0];

        return [
            'field' => $fieldPath,
            'candidates' => $candidates,
            'recommended' => $recommended['value'],
            'recommended_weight' => $recommended['weight'],
        ];
    }

    protected function inferSource(array $ext): string
    {
        return ! empty($ext['sources']['pdf']['extracted'] ?? false) ? 'pdf'
            : (! empty($ext['sources']['website']) ? 'website' : 'materials');
    }

    protected function inferWeight(array $ext, string $key): float
    {
        $source = $this->inferSource($ext);
        $explicit = $ext['explicit_signals'] ?? [];
        $isExplicit = ($key === 'primary_archetype' && ($explicit['archetype_declared'] ?? false))
            || ($key === 'mission' && ($explicit['mission_declared'] ?? false))
            || ($key === 'positioning' && ($explicit['positioning_declared'] ?? false));
        return $source === 'pdf' ? ($isExplicit ? SignalWeights::PDF_EXPLICIT : SignalWeights::PDF_INFERRED)
            : ($source === 'website' ? SignalWeights::WEBSITE_DETERMINISTIC : SignalWeights::MATERIALS_EXPLICIT);
    }
}
