<?php

namespace App\Services\BrandIntelligence\Campaign;

/**
 * Ensures the campaign identity_payload follows the canonical shape.
 * Missing keys are filled with defaults, unexpected keys are stripped.
 * Used on save to prevent schema drift across collections.
 */
final class CampaignIdentityPayloadNormalizer
{
    private const CANONICAL_SHAPE = [
        'visual' => [
            'palette' => [],
            'accent_colors' => [],
            'style_description' => null,
            'motifs' => [],
            'composition_notes' => null,
        ],
        'typography' => [
            'fonts' => [],
            'primary_font' => null,
            'signature_font' => null,
            'direction' => null,
        ],
        'messaging' => [
            'tone' => null,
            'voice_notes' => null,
            'pillars' => [],
            'approved_phrases' => [],
            'discouraged_phrases' => [],
            'cta_direction' => null,
            'required_cta_patterns' => [],
        ],
        'rules' => [
            'required_motifs' => [],
            'required_phrases' => [],
            'discouraged_phrases' => [],
            'logo_treatment_notes' => null,
            'category_notes' => null,
        ],
    ];

    /**
     * Normalize a raw payload into the canonical shape.
     *
     * - Missing top-level sections are kept as null (not configured)
     * - Present sections have their keys enforced and unexpected keys stripped
     * - Empty strings on string fields are normalized to null
     *
     * @param  array|null  $raw
     * @return array<string, mixed>
     */
    public static function normalize(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $result = [];

        foreach (self::CANONICAL_SHAPE as $section => $sectionDefaults) {
            if (! array_key_exists($section, $raw)) {
                continue;
            }

            $rawSection = $raw[$section];

            if ($rawSection === null) {
                $result[$section] = null;
                continue;
            }

            if (! is_array($rawSection)) {
                continue;
            }

            $normalized = [];
            foreach ($sectionDefaults as $key => $default) {
                if (! array_key_exists($key, $rawSection)) {
                    $normalized[$key] = $default;
                    continue;
                }

                $value = $rawSection[$key];

                if (is_array($default)) {
                    $normalized[$key] = is_array($value) ? array_values($value) : $default;
                } elseif ($default === null) {
                    $normalized[$key] = is_string($value) && trim($value) === '' ? null : $value;
                } else {
                    $normalized[$key] = $value;
                }
            }

            $result[$section] = $normalized;
        }

        return $result;
    }

    /**
     * Check whether a section has any meaningful (non-default) content.
     */
    public static function sectionHasContent(string $section, ?array $payload): bool
    {
        if ($payload === null || ! isset($payload[$section]) || ! is_array($payload[$section])) {
            return false;
        }

        $defaults = self::CANONICAL_SHAPE[$section] ?? [];
        $data = $payload[$section];

        foreach ($defaults as $key => $default) {
            $value = $data[$key] ?? $default;
            if (is_array($default)) {
                if (! empty($value)) {
                    return true;
                }
            } elseif ($default === null) {
                if ($value !== null && $value !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
