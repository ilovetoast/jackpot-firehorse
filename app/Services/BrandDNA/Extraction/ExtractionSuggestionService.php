<?php

namespace App\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\SuggestionConfidenceTier;

/**
 * Confidence thresholds:
 * >= 0.85 → auto_apply (high confidence, auto-fill)
 * 0.60 - 0.85 → suggestion (medium, show below field)
 * < 0.60 → ignore (low confidence)
 * >= 0.75 → safe to apply (Apply All Safe Suggestions)
 */
class ExtractionSuggestionService
{
    public const CONFIDENCE_AUTO_APPLY = 0.85;

    public const CONFIDENCE_SAFE_APPLY = 0.75;

    public const CONFIDENCE_MIN = 0.60;

    public function generateSuggestions(array $extraction, array $conflicts = [], array $activeSources = []): array
    {
        $suggestions = [];
        $explicit = $extraction['explicit_signals'] ?? [];
        $conflictFields = array_column($conflicts, 'field');
        $sources = $this->deriveSources($extraction, $activeSources);
        $sectionSources = $extraction['section_sources'] ?? [];

        $evidenceContext = $this->deriveEvidenceContext($extraction);

        foreach ($conflicts as $conflict) {
            $field = $conflict['field'] ?? '';
            $recommended = $conflict['recommended'] ?? null;
            $weight = $conflict['recommended_weight'] ?? 0.5;
            if ($weight < self::CONFIDENCE_MIN) {
                continue;
            }
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:conflict.' . $field,
                'path' => $this->conflictFieldToPath($field),
                'type' => 'informational',
                'value' => $recommended,
                'reason' => sprintf('Multiple sources disagree. Recommended: %s.', is_scalar($recommended) ? $recommended : 'see value'),
                'confidence' => $weight,
                'weight' => $weight,
                'source' => $sources,
                'evidence' => $evidenceContext['conflict'] ?? [],
            ], $sectionSources, $extraction);
        }

        if (($explicit['archetype_declared'] ?? false) && ! empty($extraction['personality']['primary_archetype']) && ! in_array('personality.primary_archetype', $conflictFields)) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:personality.primary_archetype',
                'path' => 'personality.primary_archetype',
                'type' => 'update',
                'value' => SignalWeights::unwrap($extraction['personality']['primary_archetype']),
                'reason' => 'Declared explicitly in Brand Guidelines.',
                'confidence' => 0.95,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => array_merge($sources, ['pdf']),
                'evidence' => $this->evidenceForArchetype($extraction),
            ], $sectionSources, $extraction);
        }

        if (($explicit['mission_declared'] ?? false) && ! empty($extraction['identity']['mission']) && ! in_array('identity.mission', $conflictFields)) {
            $val = SignalWeights::unwrap($extraction['identity']['mission']);
            if (is_string($val) && ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                // Skip low-quality mission
            } else {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:identity.mission',
                    'path' => 'identity.mission',
                    'type' => 'update',
                    'value' => $val,
                'reason' => 'Extracted from Brand Guidelines.',
                'confidence' => 0.9,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => array_merge($sources, ['pdf']),
                'evidence' => $this->evidenceForMission($extraction),
            ], $sectionSources, $extraction);
            }
        }

        if (($explicit['positioning_declared'] ?? false) && ! empty($extraction['identity']['positioning']) && ! in_array('identity.positioning', $conflictFields)) {
            $val = SignalWeights::unwrap($extraction['identity']['positioning']);
            if (is_string($val) && ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                // Skip low-quality positioning
            } else {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:identity.positioning',
                    'path' => 'identity.positioning',
                    'type' => 'update',
                    'value' => $val,
                'reason' => 'Extracted from Brand Guidelines.',
                'confidence' => 0.9,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => array_merge($sources, ['pdf']),
                'evidence' => $this->evidenceForPositioning($extraction),
            ], $sectionSources, $extraction);
            }
        }

        if (! empty($extraction['visual']['primary_colors'])) {
            $colors = $extraction['visual']['primary_colors'];
            $palette = [];
            foreach (is_array($colors) ? $colors : [$colors] as $c) {
                if (is_string($c)) {
                    $palette[] = ['hex' => $c];
                } elseif (is_array($c) && isset($c['hex'])) {
                    $palette[] = $c;
                } elseif (is_array($c) && isset($c['value'])) {
                    $palette[] = is_string($c['value']) ? ['hex' => $c['value']] : $c['value'];
                }
            }
            $conf = (float) ($extraction['confidence'] ?? 0.7);
            if ($conf >= self::CONFIDENCE_MIN) {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:standards.allowed_color_palette',
                    'path' => 'scoring_rules.allowed_color_palette',
                    'type' => 'update',
                    'value' => $palette,
                    'reason' => 'Colors extracted from source materials.',
                    'confidence' => $conf,
                    'weight' => SignalWeights::WEBSITE_DETERMINISTIC,
                    'source' => $sources,
                    'evidence' => $this->evidenceForColors($extraction),
                ], $sectionSources, $extraction);
            }
        }

        if (! empty($extraction['visual']['fonts'])) {
            $fonts = $extraction['visual']['fonts'];
            $primaryFont = is_array($fonts) ? ($fonts[0] ?? null) : $fonts;
            if ($primaryFont) {
                $primaryFont = is_array($primaryFont) && isset($primaryFont['value']) ? $primaryFont['value'] : $primaryFont;
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:typography.primary_font',
                    'path' => 'typography.primary_font',
                    'type' => 'update',
                    'value' => $primaryFont,
                    'reason' => 'Typography extracted from source materials.',
                    'confidence' => 0.8,
                    'weight' => SignalWeights::WEBSITE_DETERMINISTIC,
                    'source' => $sources,
                    'evidence' => $this->evidenceForFonts($extraction),
                ], $sectionSources, $extraction);
            }
        }

        if (! empty($extraction['personality']['tone_keywords'])) {
            $tone = $extraction['personality']['tone_keywords'];
            $toneVal = is_array($tone) ? implode(', ', array_slice($tone, 0, 5)) : (string) $tone;
            if ($toneVal && ! in_array('scoring_rules.tone_keywords', $conflictFields)) {
                $conf = 0.75;
                if ($conf >= self::CONFIDENCE_MIN) {
                    $suggestions[] = $this->normalizeSuggestion([
                        'key' => 'SUG:scoring_rules.tone_keywords',
                        'path' => 'scoring_rules.tone_keywords',
                        'type' => 'update',
                        'value' => is_array($tone) ? $tone : array_filter(array_map('trim', explode(',', $toneVal))),
                        'reason' => 'Tone keywords extracted from source materials.',
                        'confidence' => $conf,
                        'weight' => 0.75,
                        'source' => $sources,
                        'evidence' => $this->evidenceForTone($extraction),
                    ], $sectionSources, $extraction);
                }
            }
        }

        return $suggestions;
    }

    protected function deriveSources(array $extraction, array $activeSources): array
    {
        if (! empty($activeSources)) {
            return array_values(array_unique($activeSources));
        }
        $out = [];
        if (! empty($extraction['sources']['pdf'] ?? [])) {
            $out[] = 'pdf';
        }
        if (! empty($extraction['sources']['website'] ?? [])) {
            $out[] = 'website';
        }
        if (! empty($extraction['sources']['materials'] ?? [])) {
            $out[] = 'materials';
        }

        return $out ?: ['extraction'];
    }

    public const MIN_SECTION_QUALITY_AUTO_APPLY = 0.6;

    protected function normalizeSuggestion(array $s, ?array $sectionSources = null, array $extraction = []): array
    {
        $confidence = (float) ($s['confidence'] ?? $s['weight'] ?? 0);
        $sectionMetadata = $extraction['_extraction_debug']['section_metadata'] ?? [];
        $sectionQualityByPath = $extraction['_extraction_debug']['section_quality_by_path'] ?? [];
        $sectionTitle = ($sectionSources !== null && isset($s['path'], $sectionSources[$s['path']])) ? $sectionSources[$s['path']] : null;

        if ($sectionTitle !== null) {
            $s['section'] = $sectionTitle;
            foreach ($sectionMetadata as $meta) {
                if (strtoupper($meta['title'] ?? '') === strtoupper($sectionTitle)) {
                    $s['section_source'] = $meta['source'] ?? 'heuristic';
                    $s['section_confidence'] = (float) ($meta['confidence'] ?? 0.7);
                    $s['section_quality_score'] = (float) ($meta['quality_score'] ?? 0.5);
                    break;
                }
            }
            $path = $s['path'] ?? '';
            if (isset($sectionQualityByPath[$path])) {
                $s['section_quality_score'] = (float) $sectionQualityByPath[$path];
            }
        }

        $val = $s['value'] ?? null;
        $path = $s['path'] ?? '';
        if (is_string($val) && in_array($path, ['identity.mission', 'identity.positioning'], true) && ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
            $confidence = min($confidence, self::CONFIDENCE_SAFE_APPLY - 0.05);
        }
        if ($sectionTitle !== null && ($s['section_source'] ?? 'heuristic') === 'heuristic' && ($s['section_confidence'] ?? 0) < 0.8) {
            $confidence = min($confidence, self::CONFIDENCE_AUTO_APPLY - 0.05);
        }
        if ($sectionTitle !== null && ($s['section_source'] ?? null) === 'toc') {
            $confidence = min(1.0, $confidence + 0.05);
        }

        $qualityScore = (float) ($s['section_quality_score'] ?? 1.0);
        if ($sectionTitle !== null && $qualityScore < self::MIN_SECTION_QUALITY_AUTO_APPLY) {
            $confidence = min($confidence, self::CONFIDENCE_AUTO_APPLY - 0.05);
        }
        if ($sectionTitle !== null && $qualityScore >= 0.8) {
            $confidence = min(1.0, $confidence + 0.03);
        }

        $s['confidence'] = $confidence;
        $s['confidence_tier'] = SuggestionConfidenceTier::fromWeight($confidence);
        $s['source'] = $s['source'] ?? [];
        $s['auto_apply'] = $confidence >= self::CONFIDENCE_AUTO_APPLY;
        $s['evidence'] = $s['evidence'] ?? [];

        return $s;
    }

    protected function deriveEvidenceContext(array $extraction): array
    {
        return [
            'conflict' => ['Multiple sources provided different values.'],
        ];
    }

    protected function evidenceForArchetype(array $extraction): array
    {
        $evidence = [];
        $headlines = $extraction['sources']['website']['hero_headlines'] ?? [];
        foreach (array_slice(is_array($headlines) ? $headlines : [], 0, 3) as $h) {
            if (is_string($h) && trim($h) !== '') {
                $evidence[] = 'headline: ' . $h;
            }
        }
        $bio = $extraction['sources']['website']['brand_bio'] ?? $extraction['identity']['positioning'] ?? null;
        if (is_string($bio) && strlen(trim($bio)) > 20) {
            $evidence[] = 'section: ' . substr(trim($bio), 0, 80) . (strlen($bio) > 80 ? '…' : '');
        }
        if (empty($evidence)) {
            $evidence[] = 'Declared in Brand Guidelines PDF';
        }

        return $evidence;
    }

    protected function evidenceForMission(array $extraction): array
    {
        $evidence = [];
        $bio = $extraction['sources']['website']['brand_bio'] ?? $extraction['identity']['positioning'] ?? null;
        if (is_string($bio) && trim($bio) !== '') {
            $evidence[] = 'brand_bio: ' . (strlen($bio) > 100 ? substr($bio, 0, 100) . '…' : $bio);
        }
        if (empty($evidence)) {
            $evidence[] = 'Extracted from Brand Guidelines';
        }

        return $evidence;
    }

    protected function evidenceForPositioning(array $extraction): array
    {
        $evidence = [];
        $bio = $extraction['sources']['website']['brand_bio'] ?? null;
        if (is_string($bio) && trim($bio) !== '') {
            $evidence[] = 'brand_bio: ' . (strlen($bio) > 100 ? substr($bio, 0, 100) . '…' : $bio);
        }
        $headlines = $extraction['sources']['website']['hero_headlines'] ?? [];
        if (count($headlines) > 0) {
            $evidence[] = 'Competitive language across product pages';
        }
        if (empty($evidence)) {
            $evidence[] = 'Extracted from Brand Guidelines';
        }

        return $evidence;
    }

    protected function evidenceForColors(array $extraction): array
    {
        $sources = [];
        if (! empty($extraction['sources']['pdf'] ?? [])) {
            $sources[] = 'Brand guidelines PDF';
        }
        if (! empty($extraction['sources']['website'] ?? [])) {
            $sources[] = 'Website CSS and imagery';
        }
        if (! empty($extraction['sources']['materials'] ?? [])) {
            $sources[] = 'Uploaded brand materials';
        }

        return $sources ?: ['Extracted from source materials'];
    }

    protected function evidenceForFonts(array $extraction): array
    {
        return $this->evidenceForColors($extraction);
    }

    protected function evidenceForTone(array $extraction): array
    {
        $evidence = [];
        $mission = $extraction['identity']['mission'] ?? null;
        if (is_string($mission) && strlen(trim($mission)) > 15) {
            $evidence[] = 'mission: ' . substr(trim($mission), 0, 60) . (strlen($mission) > 60 ? '…' : '');
        }
        if (empty($evidence)) {
            $evidence[] = 'Tone keywords extracted from source materials';
        }

        return $evidence;
    }

    protected function conflictFieldToPath(string $field): string
    {
        $map = [
            'personality.primary_archetype' => 'personality.primary_archetype',
            'identity.mission' => 'identity.mission',
            'identity.positioning' => 'identity.positioning',
        ];

        return $map[$field] ?? $field;
    }
}
