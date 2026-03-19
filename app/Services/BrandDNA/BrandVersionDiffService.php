<?php

namespace App\Services\BrandDNA;

use App\Models\BrandModelVersion;

/**
 * Compares two BrandModelVersion payloads and returns which fields changed.
 */
class BrandVersionDiffService
{
    protected const TRACKED_FIELDS = [
        'identity.mission',
        'identity.vision',
        'identity.positioning',
        'identity.industry',
        'identity.target_audience',
        'identity.tagline',
        'identity.beliefs',
        'identity.values',
        'identity.market_category',
        'personality.primary_archetype',
        'personality.traits',
        'personality.tone_keywords',
        'personality.voice_description',
        'personality.brand_look',
        'visual.primary_colors',
        'visual.secondary_colors',
        'visual.fonts',
        'visual.photography_style',
        'visual.visual_style',
        'typography.primary_font',
        'typography.secondary_font',
        'scoring_rules.allowed_color_palette',
        'scoring_rules.allowed_fonts',
        'scoring_rules.tone_keywords',
    ];

    /**
     * Compare two versions and return changed fields.
     *
     * @return array{changed: string[], added: string[], removed: string[]}
     */
    public function compare(BrandModelVersion $versionA, BrandModelVersion $versionB): array
    {
        $payloadA = $versionA->model_payload ?? [];
        $payloadB = $versionB->model_payload ?? [];

        $changed = [];
        $added = [];
        $removed = [];

        foreach (self::TRACKED_FIELDS as $dotPath) {
            $valA = $this->getNestedValue($payloadA, $dotPath);
            $valB = $this->getNestedValue($payloadB, $dotPath);

            $emptyA = $this->isEmpty($valA);
            $emptyB = $this->isEmpty($valB);

            if ($emptyA && $emptyB) {
                continue;
            }

            if ($emptyA && ! $emptyB) {
                $added[] = $dotPath;
            } elseif (! $emptyA && $emptyB) {
                $removed[] = $dotPath;
            } elseif ($this->normalizeForComparison($valA) !== $this->normalizeForComparison($valB)) {
                $changed[] = $dotPath;
            }
        }

        return [
            'changed' => $changed,
            'added' => $added,
            'removed' => $removed,
        ];
    }

    /**
     * Shorthand: return just the list of all fields that differ.
     *
     * @return string[]
     */
    public function changedFields(BrandModelVersion $versionA, BrandModelVersion $versionB): array
    {
        $diff = $this->compare($versionA, $versionB);

        return array_values(array_unique(array_merge(
            $diff['changed'],
            $diff['added'],
            $diff['removed']
        )));
    }

    protected function getNestedValue(array $data, string $dotPath): mixed
    {
        $keys = explode('.', $dotPath);
        $current = $data;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        // Unwrap AI-wrapped values: { value, source, confidence } -> value
        if (is_array($current) && isset($current['value'], $current['source'])) {
            return $current['value'];
        }

        return $current;
    }

    protected function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    protected function normalizeForComparison(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_SORT_KEYS);
        }

        return (string) $value;
    }
}
