<?php

namespace App\Services\BrandDNA;

/**
 * Compiles snapshot, suggestions, coherence, and alignment into a single normalized research report.
 * Sits between extraction and UI — provides a consistent structure for consumption.
 */
class BrandResearchReportBuilder
{
    protected const PATH_TO_CONFIDENCE_KEY = [
        'scoring_rules.allowed_color_palette' => 'colors',
        'typography.primary_font' => 'fonts',
        'personality.primary_archetype' => 'archetype',
        'identity.mission' => 'mission',
        'identity.positioning' => 'positioning',
        'scoring_rules.tone_keywords' => 'tone_keywords',
    ];

    /**
     * Build a normalized research report from raw extraction outputs.
     *
     * @param  array{primary_colors?: array, detected_fonts?: array, brand_bio?: string, hero_headlines?: array, logo_url?: string}  $snapshot
     * @param  array<int, array{path?: string, value?: mixed, confidence?: float, source?: array}>  $suggestions
     * @param  array{overall?: array{score?: int, coverage?: float, confidence?: float}, sections?: array, risks?: array}  $coherence
     * @param  array{summary?: array{score?: int, confidence?: int}, findings?: array}  $alignment
     * @param  array<string>  $sources
     */
    public static function build(
        array $snapshot,
        array $suggestions,
        array $coherence,
        array $alignment,
        array $sources = []
    ): array {
        $transformed = SuggestionViewTransformer::forFrontend($suggestions, $snapshot);
        $confidenceMap = self::buildConfidenceMap($suggestions, $snapshot, $coherence);

        $overallConfidence = ($coherence['overall']['confidence'] ?? 0) / 100;
        if ($overallConfidence <= 0 && ! empty($confidenceMap)) {
            $overallConfidence = array_sum($confidenceMap) / max(1, count($confidenceMap));
        }

        $detectedConfidently = self::buildDetectedConfidently($snapshot);
        $suggestedForReview = self::buildSuggestedForReview($transformed, $detectedConfidently);
        $notConfidentlyDetected = self::buildNotConfidentlyDetected($snapshot, $detectedConfidently, $suggestedForReview);

        return [
            'summary' => [
                'brand_description' => self::unwrapBrandBio($snapshot['brand_bio'] ?? null),
                'confidence' => round($overallConfidence, 2),
            ],
            'visual_identity' => [
                'colors' => self::normalizeColors($snapshot['primary_colors'] ?? []),
                'typography' => self::normalizeTypography($snapshot['detected_fonts'] ?? []),
            ],
            'strategy' => [
                'recommended_archetypes' => self::normalizeArchetypes($transformed['recommended_archetypes'] ?? []),
                'mission' => $transformed['mission_suggestion'] ?? '',
                'positioning' => $transformed['positioning_suggestion'] ?? '',
            ],
            'voice' => [
                'tone_keywords' => $transformed['tone_suggestion'] ?? [],
            ],
            'confidence_map' => $confidenceMap,
            'sources' => array_values(array_unique(array_merge($sources, self::deriveSourcesFromSuggestions($suggestions)))),
            'detected_confidently' => $detectedConfidently,
            'suggested_for_review' => $suggestedForReview,
            'not_confidently_detected' => $notConfidentlyDetected,
            'explicit_signals' => $snapshot['explicit_signals'] ?? [],
        ];
    }

    protected static function buildDetectedConfidently(array $snapshot): array
    {
        $out = [];
        $evidenceMap = $snapshot['evidence_map'] ?? [];
        $explicit = $snapshot['explicit_signals'] ?? [];

        $archetype = $evidenceMap['personality.primary_archetype']['final_value'] ?? null;
        if ($archetype && (($explicit['archetype_declared'] ?? false) || ($evidenceMap['personality.primary_archetype']['winning_reason'] ?? '') === 'explicit_archetype_match')) {
            $out['archetype'] = is_string($archetype) ? $archetype : ($archetype['value'] ?? (string) $archetype);
        }

        $colors = $snapshot['primary_colors'] ?? [];
        if (! empty($colors) && ($explicit['colors_declared'] ?? false)) {
            $out['primary_colors'] = self::normalizeColors($colors);
        } elseif (! empty($colors) && ! empty($evidenceMap['visual.primary_colors']['winning_source'] ?? '')) {
            $out['primary_colors'] = self::normalizeColors($colors);
        }

        return $out;
    }

    protected static function buildSuggestedForReview(array $transformed, array $detectedConfidently): array
    {
        $out = [];
        if (! empty($transformed['recommended_archetypes']) && empty($detectedConfidently['archetype'] ?? null)) {
            $out['archetype'] = array_map(fn ($a) => is_string($a) ? $a : ($a['label'] ?? $a['archetype'] ?? ''), $transformed['recommended_archetypes']);
        }
        if (! empty($transformed['mission_suggestion'])) {
            $out['mission'] = $transformed['mission_suggestion'];
        }
        if (! empty($transformed['positioning_suggestion'])) {
            $out['positioning'] = $transformed['positioning_suggestion'];
        }
        if (! empty($transformed['tone_suggestion'])) {
            $out['tone_keywords'] = $transformed['tone_suggestion'];
        }
        return $out;
    }

    protected static function buildNotConfidentlyDetected(array $snapshot, array $detectedConfidently, array $suggestedForReview): array
    {
        $out = [];
        if (empty($detectedConfidently['archetype'] ?? null) && empty($suggestedForReview['archetype'] ?? [])) {
            $out[] = 'archetype';
        }
        if (empty($snapshot['primary_colors'] ?? []) && empty($detectedConfidently['primary_colors'] ?? [])) {
            $out[] = 'primary_colors';
        }
        if (empty($snapshot['brand_bio'] ?? null)) {
            $out[] = 'brand_description';
        }
        if (empty($suggestedForReview['mission'] ?? null) && empty($snapshot['brand_bio'] ?? null)) {
            $out[] = 'mission';
        }
        if (empty($suggestedForReview['positioning'] ?? null)) {
            $out[] = 'positioning';
        }
        return array_values(array_unique($out));
    }

    protected static function buildConfidenceMap(array $suggestions, array $snapshot, array $coherence): array
    {
        $map = [];

        foreach ($suggestions as $s) {
            if (! is_array($s)) {
                continue;
            }
            $path = $s['path'] ?? null;
            $confidence = (float) ($s['confidence'] ?? 0);
            if ($path && isset(self::PATH_TO_CONFIDENCE_KEY[$path])) {
                $key = self::PATH_TO_CONFIDENCE_KEY[$path];
                if (! isset($map[$key]) || $confidence > $map[$key]) {
                    $map[$key] = round($confidence, 2);
                }
            }
        }

        if (empty($map['colors']) && ! empty($snapshot['primary_colors'])) {
            $map['colors'] = 0.85;
        }
        if (empty($map['fonts']) && ! empty($snapshot['detected_fonts'])) {
            $map['fonts'] = 0.80;
        }

        $sections = $coherence['sections'] ?? [];
        if (empty($map['archetype']) && isset($sections['archetype']['confidence'])) {
            $map['archetype'] = round(($sections['archetype']['confidence'] ?? 0) / 100, 2);
        }

        return $map;
    }

    protected static function unwrapBrandBio(mixed $val): string
    {
        if ($val === null) {
            return '';
        }
        if (is_string($val)) {
            return $val;
        }
        if (is_array($val) && isset($val['value']) && is_string($val['value'])) {
            return $val['value'];
        }

        return '';
    }

    protected static function normalizeColors(array $colors): array
    {
        $out = [];
        foreach ($colors as $c) {
            if (is_string($c)) {
                $out[] = ['hex' => $c];
            } elseif (is_array($c) && isset($c['hex'])) {
                $out[] = $c;
            } elseif (is_array($c) && isset($c['value'])) {
                $out[] = is_string($c['value']) ? ['hex' => $c['value']] : $c['value'];
            }
        }

        return $out;
    }

    protected static function normalizeTypography(array $fonts): array
    {
        if (! is_array($fonts)) {
            return [];
        }
        $out = [];
        foreach ($fonts as $f) {
            $name = is_array($f) ? ($f['value'] ?? $f['name'] ?? $f) : $f;
            if ($name !== null && $name !== '') {
                $out[] = is_string($name) ? $name : (string) $name;
            }
        }

        return array_values(array_unique($out));
    }

    protected static function normalizeArchetypes(array $archetypes): array
    {
        $out = [];
        foreach ($archetypes as $a) {
            if (is_string($a)) {
                $out[] = ['label' => $a, 'confidence' => null];
            } elseif (is_array($a)) {
                $out[] = [
                    'label' => $a['label'] ?? $a['archetype'] ?? $a['value'] ?? '',
                    'confidence' => $a['confidence'] ?? null,
                ];
            }
        }

        return $out;
    }

    protected static function deriveSourcesFromSuggestions(array $suggestions): array
    {
        $sources = [];
        foreach ($suggestions as $s) {
            if (is_array($s) && ! empty($s['source'])) {
                $src = $s['source'];
                $sources = array_merge($sources, is_array($src) ? $src : [$src]);
            }
        }

        return array_values(array_unique($sources));
    }
}
