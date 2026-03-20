<?php

namespace App\Services\BrandDNA\Extraction;

use App\Support\BrandDNA\HeadlineAppearanceCatalog;

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

        if (! empty($extraction['identity']['mission']) && ! in_array('identity.mission', $conflictFields)) {
            $val = SignalWeights::unwrap($extraction['identity']['mission']);
            $isExplicit = $explicit['mission_declared'] ?? false;
            if (is_string($val) && ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                // Skip low-quality mission
            } else {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:identity.mission',
                    'path' => 'identity.mission',
                    'type' => 'update',
                    'value' => $val,
                    'reason' => $isExplicit ? 'Purpose explicitly declared in Brand Guidelines.' : 'Purpose inferred from Brand Guidelines.',
                    'confidence' => $isExplicit ? 0.9 : 0.85,
                    'weight' => $isExplicit ? SignalWeights::PDF_EXPLICIT : SignalWeights::PDF_INFERRED,
                    'source' => array_merge($sources, ['pdf']),
                    'evidence' => $this->evidenceForMission($extraction),
                ], $sectionSources, $extraction);
            }
        }

        if (! empty($extraction['identity']['positioning']) && ! in_array('identity.positioning', $conflictFields)) {
            $val = SignalWeights::unwrap($extraction['identity']['positioning']);
            $isExplicit = $explicit['positioning_declared'] ?? false;
            if (is_string($val) && ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                // Skip low-quality positioning
            } else {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:identity.positioning',
                    'path' => 'identity.positioning',
                    'type' => 'update',
                    'value' => $val,
                    'reason' => $isExplicit ? 'Brand promise explicitly stated in Brand Guidelines.' : 'Brand promise inferred from Brand Guidelines.',
                    'confidence' => $isExplicit ? 0.9 : 0.85,
                    'weight' => $isExplicit ? SignalWeights::PDF_EXPLICIT : SignalWeights::PDF_INFERRED,
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
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:scoring_rules.tone_keywords',
                    'path' => 'scoring_rules.tone_keywords',
                    'type' => 'update',
                    'value' => is_array($tone) ? $tone : array_filter(array_map('trim', explode(',', $toneVal))),
                    'reason' => 'Tone keywords extracted from Brand Guidelines.',
                    'confidence' => 0.85,
                    'weight' => SignalWeights::PDF_EXPLICIT,
                    'source' => array_merge($sources, ['pdf']),
                    'evidence' => $this->evidenceForTone($extraction),
                ], $sectionSources, $extraction);
            }
        }

        $this->generateIdentityFieldSuggestions($extraction, $conflictFields, $sources, $sectionSources, $suggestions);
        $this->generatePersonalityFieldSuggestions($extraction, $conflictFields, $sources, $sectionSources, $suggestions);
        $this->generateVisualFieldSuggestions($extraction, $sources, $sectionSources, $suggestions);
        $this->generateTypographyFieldSuggestions($extraction, $sources, $sectionSources, $suggestions);

        return $suggestions;
    }

    protected function generateIdentityFieldSuggestions(array $extraction, array $conflictFields, array $sources, ?array $sectionSources, array &$suggestions): void
    {
        $identity = $extraction['identity'] ?? [];
        $pdfSources = array_merge($sources, ['pdf']);

        $fields = [
            'tagline' => ['path' => 'identity.tagline', 'reason' => 'Tagline extracted from Brand Guidelines.', 'conf' => 0.9],
            'industry' => ['path' => 'identity.industry', 'reason' => 'Industry identified from Brand Guidelines.', 'conf' => 0.85],
            'target_audience' => ['path' => 'identity.target_audience', 'reason' => 'Target audience identified from Brand Guidelines.', 'conf' => 0.85],
            'vision' => ['path' => 'identity.vision', 'reason' => 'Vision statement extracted from Brand Guidelines.', 'conf' => 0.85],
        ];

        foreach ($fields as $key => $meta) {
            $val = SignalWeights::unwrap($identity[$key] ?? null);
            if (empty($val) || ! is_string($val) || in_array($meta['path'], $conflictFields)) {
                continue;
            }
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:' . $meta['path'],
                'path' => $meta['path'],
                'type' => 'update',
                'value' => $val,
                'reason' => $meta['reason'],
                'confidence' => $meta['conf'],
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => $pdfSources,
                'evidence' => ['Extracted from Brand Guidelines PDF'],
            ], $sectionSources, $extraction);
        }

        $arrayFields = [
            'beliefs' => ['path' => 'identity.beliefs', 'reason' => 'Core beliefs extracted from Brand Guidelines.', 'conf' => 0.85],
            'values' => ['path' => 'identity.values', 'reason' => 'Brand values extracted from Brand Guidelines.', 'conf' => 0.85],
        ];

        foreach ($arrayFields as $key => $meta) {
            $val = $identity[$key] ?? [];
            if (empty($val) || ! is_array($val) || in_array($meta['path'], $conflictFields)) {
                continue;
            }
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:' . $meta['path'],
                'path' => $meta['path'],
                'type' => 'update',
                'value' => $val,
                'reason' => $meta['reason'],
                'confidence' => $meta['conf'],
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => $pdfSources,
                'evidence' => ['Extracted from Brand Guidelines PDF'],
            ], $sectionSources, $extraction);
        }
    }

    protected function generatePersonalityFieldSuggestions(array $extraction, array $conflictFields, array $sources, ?array $sectionSources, array &$suggestions): void
    {
        $personality = $extraction['personality'] ?? [];
        $pdfSources = array_merge($sources, ['pdf']);

        $traits = $personality['traits'] ?? [];
        if (! empty($traits) && is_array($traits) && ! in_array('personality.traits', $conflictFields)) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:personality.traits',
                'path' => 'personality.traits',
                'type' => 'update',
                'value' => $traits,
                'reason' => 'Personality traits extracted from Brand Guidelines.',
                'confidence' => 0.85,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => $pdfSources,
                'evidence' => ['Extracted from Brand Guidelines PDF'],
            ], $sectionSources, $extraction);
        }

        $toneKw = $personality['tone_keywords'] ?? [];
        if (! empty($toneKw) && is_array($toneKw) && ! in_array('personality.tone_keywords', $conflictFields)) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:personality.tone_keywords',
                'path' => 'personality.tone_keywords',
                'type' => 'update',
                'value' => $toneKw,
                'reason' => 'Tone keywords extracted from Brand Guidelines.',
                'confidence' => 0.85,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => $pdfSources,
                'evidence' => ['Extracted from Brand Guidelines PDF'],
            ], $sectionSources, $extraction);
        }

        $voiceDesc = $personality['voice_description'] ?? null;
        if (! empty($voiceDesc) && is_string($voiceDesc)) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:personality.voice_description',
                'path' => 'personality.voice_description',
                'type' => 'update',
                'value' => $voiceDesc,
                'reason' => 'Brand voice synthesized from Brand Guidelines.',
                'confidence' => 0.85,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => $pdfSources,
                'evidence' => ['Synthesized from Brand Guidelines PDF'],
            ], $sectionSources, $extraction);
        }

        $brandLook = $personality['brand_look'] ?? null;
        if (! empty($brandLook) && is_string($brandLook)) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:personality.brand_look',
                'path' => 'personality.brand_look',
                'type' => 'update',
                'value' => $brandLook,
                'reason' => 'Brand look synthesized from Brand Guidelines.',
                'confidence' => 0.85,
                'weight' => SignalWeights::PDF_EXPLICIT,
                'source' => $pdfSources,
                'evidence' => ['Synthesized from Brand Guidelines PDF'],
            ], $sectionSources, $extraction);
        }
    }

    protected function generateVisualFieldSuggestions(array $extraction, array $sources, ?array $sectionSources, array &$suggestions): void
    {
        $visual = $extraction['visual'] ?? [];
        $pdfSources = array_merge($sources, ['pdf']);

        if (! empty($visual['secondary_colors']) && is_array($visual['secondary_colors'])) {
            $palette = [];
            foreach ($visual['secondary_colors'] as $c) {
                if (is_string($c)) {
                    $palette[] = ['hex' => $c];
                } elseif (is_array($c) && isset($c['hex'])) {
                    $palette[] = $c;
                }
            }
            if (! empty($palette)) {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:standards.secondary_color_palette',
                    'path' => 'scoring_rules.secondary_color_palette',
                    'type' => 'update',
                    'value' => $palette,
                    'reason' => 'Secondary colors extracted from Brand Guidelines.',
                    'confidence' => 0.8,
                    'weight' => 0.7,
                    'source' => $pdfSources,
                    'evidence' => ['Brand guidelines PDF'],
                ], $sectionSources, $extraction);
            }
        }

        $stringVisualFields = [
            'photography_style' => ['path' => 'visual.photography_style', 'reason' => 'Photography direction extracted from Brand Guidelines.'],
            'visual_style' => ['path' => 'visual.visual_style', 'reason' => 'Visual design direction extracted from Brand Guidelines.'],
        ];

        foreach ($stringVisualFields as $key => $meta) {
            $val = $visual[$key] ?? null;
            if (empty($val) || ! is_string($val)) {
                continue;
            }
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:' . $meta['path'],
                'path' => $meta['path'],
                'type' => 'update',
                'value' => $val,
                'reason' => $meta['reason'],
                'confidence' => 0.8,
                'weight' => 0.7,
                'source' => $pdfSources,
                'evidence' => ['Brand guidelines PDF'],
            ], $sectionSources, $extraction);
        }

        $allColors = array_merge(
            is_array($visual['primary_colors'] ?? null) ? $visual['primary_colors'] : [],
            is_array($visual['secondary_colors'] ?? null) ? $visual['secondary_colors'] : []
        );
        $hexColors = [];
        foreach ($allColors as $c) {
            $hex = is_string($c) ? $c : ($c['hex'] ?? ($c['value'] ?? null));
            if (is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
                $hexColors[] = strtoupper($hex);
            }
        }
        $hexColors = array_values(array_unique($hexColors));

        if (count($hexColors) >= 1) {
            $roles = [
                ['key' => 'primary_color', 'label' => 'Primary'],
                ['key' => 'secondary_color', 'label' => 'Secondary'],
                ['key' => 'accent_color', 'label' => 'Accent'],
            ];
            foreach ($roles as $i => $role) {
                if (isset($hexColors[$i])) {
                    $suggestions[] = $this->normalizeSuggestion([
                        'key' => "SUG:brand_colors.{$role['key']}",
                        'path' => "brand_colors.{$role['key']}",
                        'type' => 'update',
                        'value' => $hexColors[$i],
                        'reason' => "{$role['label']} brand color from Brand Guidelines palette.",
                        'confidence' => 0.82,
                        'weight' => SignalWeights::PDF_EXPLICIT,
                        'source' => $pdfSources,
                        'evidence' => ['Brand color palette from guidelines PDF'],
                    ], $sectionSources, $extraction);
                }
            }
        }

        if (! empty($visual['design_cues']) && is_array($visual['design_cues'])) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:visual.design_cues',
                'path' => 'visual.design_cues',
                'type' => 'update',
                'value' => $visual['design_cues'],
                'reason' => 'Design cues extracted from Brand Guidelines.',
                'confidence' => 0.75,
                'weight' => 0.7,
                'source' => $pdfSources,
                'evidence' => ['Brand guidelines PDF'],
            ], $sectionSources, $extraction);
        }
    }

    protected function generateTypographyFieldSuggestions(array $extraction, array $sources, ?array $sectionSources, array &$suggestions): void
    {
        $typo = $extraction['typography'] ?? [];
        $pdfSources = array_merge($sources, ['pdf']);

        $secondaryFont = $typo['secondary_font'] ?? null;
        if (! empty($secondaryFont) && is_string($secondaryFont)) {
            $suggestions[] = $this->normalizeSuggestion([
                'key' => 'SUG:typography.secondary_font',
                'path' => 'typography.secondary_font',
                'type' => 'update',
                'value' => $secondaryFont,
                'reason' => 'Body font extracted from Brand Guidelines.',
                'confidence' => 0.8,
                'weight' => 0.7,
                'source' => $pdfSources,
                'evidence' => ['Brand guidelines PDF'],
            ], $sectionSources, $extraction);
        }

        foreach (['heading_style', 'headline_treatment', 'body_style'] as $key) {
            $val = $typo[$key] ?? null;
            if (! empty($val) && is_string($val)) {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:typography.' . $key,
                    'path' => 'typography.' . $key,
                    'type' => 'update',
                    'value' => $val,
                    'reason' => 'Typography style extracted from Brand Guidelines.',
                    'confidence' => 0.75,
                    'weight' => 0.7,
                    'source' => $pdfSources,
                    'evidence' => ['Brand guidelines PDF'],
                ], $sectionSources, $extraction);
            }
        }

        $headlineFeatures = $typo['headline_appearance_features'] ?? null;
        if (is_array($headlineFeatures) && count($headlineFeatures) > 0) {
            $normalized = HeadlineAppearanceCatalog::normalizeFeatures($headlineFeatures);
            if (count($normalized) > 0) {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:typography.headline_appearance_features',
                    'path' => 'typography.headline_appearance_features',
                    'type' => 'update',
                    'value' => $normalized,
                    'reason' => 'Headline appearance patterns extracted from Brand Guidelines.',
                    'confidence' => 0.72,
                    'weight' => 0.65,
                    'source' => $pdfSources,
                    'evidence' => ['Brand guidelines PDF'],
                ], $sectionSources, $extraction);
            }
        }

        $fontDetails = $typo['font_details'] ?? [];
        if (is_array($fontDetails) && count($fontDetails) > 0) {
            $fonts = [];
            foreach ($fontDetails as $fd) {
                if (! is_array($fd) || empty($fd['name'])) {
                    continue;
                }
                $fonts[] = [
                    'name' => (string) $fd['name'],
                    'role' => (string) ($fd['role'] ?? 'other'),
                    'source' => 'unknown',
                    'styles' => is_array($fd['styles'] ?? null) ? $fd['styles'] : [],
                    'heading_use' => $fd['heading_use'] ?? null,
                    'body_use' => $fd['body_use'] ?? null,
                    'usage_notes' => $fd['usage_notes'] ?? null,
                    'purchase_url' => null,
                    'file_urls' => [],
                ];
            }
            if (count($fonts) > 0) {
                $suggestions[] = $this->normalizeSuggestion([
                    'key' => 'SUG:typography.fonts',
                    'path' => 'typography.fonts',
                    'type' => 'update',
                    'value' => $fonts,
                    'reason' => 'Font details extracted from Brand Guidelines.',
                    'confidence' => 0.85,
                    'weight' => SignalWeights::PDF_EXPLICIT,
                    'source' => $pdfSources,
                    'evidence' => ['Brand guidelines typography section'],
                ], $sectionSources, $extraction);
            }
        }
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
