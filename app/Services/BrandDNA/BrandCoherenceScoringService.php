<?php

namespace App\Services\BrandDNA;

/**
 * Brand Coherence Scoring Engine — deterministic scoring per section.
 * No AI. Coverage + confidence → overall score.
 */
class BrandCoherenceScoringService
{
    protected const PLACEHOLDER_PATTERNS = ['test', 'lorem', 'e.g.', 'example', 'placeholder', 'todo'];

    public function score(
        array $draftPayload,
        ?array $snapshotSuggestions = null,
        ?array $snapshotRaw = null,
        $brand = null,
        int $brandMaterialCount = 0,
        ?array $conflicts = null
    ): array {
        $payload = (new BrandDnaPayloadNormalizer)->normalize($draftPayload);
        $sources = $payload['sources'] ?? [];
        $identity = $payload['identity'] ?? [];
        $personality = $payload['personality'] ?? [];
        $visual = $payload['visual'] ?? [];
        $typography = $payload['typography'] ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];
        $brandColors = null;
        if ($brand) {
            $parts = [];
            if ($this->isColorUserDefined($brand, 'primary') && $brand->primary_color) {
                $parts['primary'] = $brand->primary_color;
            }
            if ($this->isColorUserDefined($brand, 'secondary') && $brand->secondary_color) {
                $parts['secondary'] = $brand->secondary_color;
            }
            if ($this->isColorUserDefined($brand, 'accent') && $brand->accent_color) {
                $parts['accent'] = $brand->accent_color;
            }
            $brandColors = ! empty($parts) ? $parts : null;
        }

        $sections = [
            'background' => $this->scoreBackground($sources, $brandMaterialCount),
            'archetype' => $this->scoreArchetype($personality, $snapshotRaw),
            'purpose' => $this->scorePurpose($identity),
            'expression' => $this->scoreExpression($identity, $personality, $scoringRules),
            'positioning' => $this->scorePositioning($identity),
            'standards' => $this->scoreStandards($typography, $scoringRules, $visual, $brandColors, $snapshotRaw),
        ];

        $overallCoverage = array_sum(array_column($sections, 'coverage')) / max(1, count($sections));
        $overallConfidence = array_sum(array_column($sections, 'confidence')) / max(1, count($sections));
        $suggestionBoost = 0;
        if ($snapshotSuggestions && is_array($snapshotSuggestions)) {
            $suggestionBoost = min(10, count($snapshotSuggestions) * 2);
        }
        $overallConfidence = min(100, $overallConfidence + $suggestionBoost);
        $overallScore = (int) round(($overallCoverage * 0.55) + ($overallConfidence * 0.45));

        $strengths = $this->deriveStrengths($sections, $payload);
        $risks = array_merge(
            $this->deriveRisks($sections, $payload),
            $sections['standards']['snapshot_risks'] ?? []
        );

        $conflictPenalty = 0;
        if (! empty($conflicts)) {
            foreach ($conflicts as $c) {
                $field = $c['field'] ?? '';
                if ($field === 'personality.primary_archetype') {
                    $recommended = $c['recommended'] ?? null;
                    $draftArchetype = self::unwrapScalar($personality['primary_archetype'] ?? null);
                    if ($draftArchetype !== $recommended) {
                        $risks[] = ['id' => 'COH:CONFLICT_PRIMARY_ARCHETYPE', 'label' => 'Multiple sources disagree on archetype', 'detail' => 'Resolve conflict by applying recommended value.'];
                        $conflictPenalty = -3;
                    }
                    break;
                }
            }
        }
        $finalScore = max(0, min(100, $overallScore + $conflictPenalty));

        return [
            'overall' => [
                'score' => $finalScore,
                'confidence' => (int) round($overallConfidence),
                'coverage' => (int) round($overallCoverage),
            ],
            'sections' => $sections,
            'strengths' => array_slice($strengths, 0, 5),
            'risks' => array_slice($risks, 0, 5),
        ];
    }

    protected function isColorUserDefined($brand, string $which): bool
    {
        $attr = $which . '_color_user_defined';
        return isset($brand->{$attr}) && $brand->{$attr} === true;
    }

    protected function scoreBackground(array $sources, int $brandMaterialCount): array
    {
        $hasWebsite = ! empty(trim((string) ($sources['website_url'] ?? '')));
        $hasSocial = ! empty($sources['social_urls'] ?? []);
        $hasMaterials = $brandMaterialCount > 0;
        $covered = $hasWebsite || $hasSocial || $hasMaterials;
        $coverage = $covered ? 100 : 0;
        $confidence = $covered ? 80 : 0;
        if ($hasWebsite && $hasMaterials) {
            $confidence = 95;
        }

        return [
            'score' => (int) round(($coverage * 0.55) + ($confidence * 0.45)),
            'coverage' => $coverage,
            'confidence' => $confidence,
            'notes' => $covered ? ['Background sources present'] : ['Add website, social URLs, or brand materials'],
        ];
    }

    protected function scoreArchetype(array $personality, ?array $snapshotRaw = null): array
    {
        $primary = self::unwrapScalar($personality['primary_archetype'] ?? null);
        if (($primary === null || $primary === '') && is_array($snapshotRaw)) {
            $evidenceArchetype = $snapshotRaw['evidence_map']['personality.primary_archetype']['final_value'] ?? null;
            if ($evidenceArchetype !== null && $evidenceArchetype !== '') {
                $primary = is_string($evidenceArchetype) ? $evidenceArchetype : ($evidenceArchetype['value'] ?? (string) $evidenceArchetype);
            }
        }
        $candidates = $personality['candidate_archetypes'] ?? [];
        $selected = array_filter(array_merge(
            $primary !== null && $primary !== '' ? [$primary] : [],
            is_array($candidates) ? array_map(fn ($c) => self::unwrapScalar($c), $candidates) : []
        ));
        $count = count(array_unique($selected));
        $valid = $count >= 1 && $count <= 2;
        $coverage = $valid ? 100 : ($count > 0 ? 50 : 0);
        $confidence = $valid ? 90 : ($count > 0 ? 60 : 0);

        return [
            'score' => (int) round(($coverage * 0.55) + ($confidence * 0.45)),
            'coverage' => $coverage,
            'confidence' => $confidence,
            'notes' => $valid ? ['Archetype selection complete'] : ($count > 2 ? ['Select 1–2 archetypes max'] : ['Select at least one archetype']),
        ];
    }

    protected function scorePurpose(array $identity): array
    {
        $mission = trim((string) self::unwrapScalar($identity['mission'] ?? ''));
        $positioning = trim((string) self::unwrapScalar($identity['positioning'] ?? ''));
        $hasMission = strlen($mission) >= 10 && ! $this->isPlaceholder($mission);
        $hasPositioning = strlen($positioning) >= 10 && ! $this->isPlaceholder($positioning);
        $coverage = ($hasMission ? 50 : 0) + ($hasPositioning ? 50 : 0);
        $confMission = $hasMission ? 85 : (strlen($mission) > 0 ? 40 : 0);
        $confPositioning = $hasPositioning ? 85 : (strlen($positioning) > 0 ? 40 : 0);
        $confidence = ($confMission + $confPositioning) / 2;

        return [
            'score' => (int) round(($coverage * 0.55) + ($confidence * 0.45)),
            'coverage' => $coverage,
            'confidence' => (int) round($confidence),
            'notes' => array_filter([
                $hasMission ? 'Mission defined' : 'Add mission (why)',
                $hasPositioning ? 'Positioning defined' : 'Add positioning (what)',
            ]),
        ];
    }

    protected function scoreExpression(array $identity, array $personality, array $scoringRules): array
    {
        $brandLook = trim((string) self::unwrapScalar($personality['brand_look'] ?? ''));
        $voiceDescription = trim((string) self::unwrapScalar($personality['voice_description'] ?? ''));
        $toneKeywords = self::unwrapArrayField($scoringRules['tone_keywords'] ?? $personality['tone_keywords'] ?? []);
        $traits = self::unwrapArrayField($personality['traits'] ?? []);
        $hasBrandLook = strlen($brandLook) >= 15 && ! $this->isPlaceholder($brandLook);
        $hasVoice = strlen($voiceDescription) >= 15 && ! $this->isPlaceholder($voiceDescription);
        $hasTone = ! empty($toneKeywords);
        $hasTraits = ! empty($traits);
        $count = ($hasBrandLook ? 1 : 0) + ($hasVoice ? 1 : 0) + ($hasTone ? 1 : 0) + ($hasTraits ? 1 : 0);
        $coverage = min(100, $count * 35);
        $confidence = $count >= 2 ? 85 : ($count >= 1 ? 60 : 0);

        return [
            'score' => (int) round(($coverage * 0.55) + ($confidence * 0.45)),
            'coverage' => $coverage,
            'confidence' => $confidence,
            'notes' => $count >= 1 ? ['Expression elements present'] : ['Add Brand Look, Brand Voice, tone keywords, or traits'],
        ];
    }

    protected function scorePositioning(array $identity): array
    {
        $industry = ! empty(trim((string) self::unwrapScalar($identity['industry'] ?? '')));
        $audience = ! empty(trim((string) self::unwrapScalar($identity['target_audience'] ?? '')));
        $marketCategory = ! empty(trim((string) self::unwrapScalar($identity['market_category'] ?? '')));
        $competitivePosition = ! empty(trim((string) self::unwrapScalar($identity['competitive_position'] ?? '')));
        $tagline = ! empty(trim((string) self::unwrapScalar($identity['tagline'] ?? '')));
        $beliefs = self::unwrapArrayField($identity['beliefs'] ?? []);
        $values = self::unwrapArrayField($identity['values'] ?? []);
        $hasBeliefs = ! empty($beliefs);
        $hasValues = ! empty($values);
        $count = ($industry ? 1 : 0) + ($audience ? 1 : 0) + ($marketCategory ? 1 : 0) + ($competitivePosition ? 1 : 0) + ($tagline ? 1 : 0) + ($hasBeliefs ? 1 : 0) + ($hasValues ? 1 : 0);
        $coverage = min(100, $count * 25);
        $confidence = $count >= 3 ? 85 : ($count >= 2 ? 70 : ($count >= 1 ? 50 : 0));

        return [
            'score' => (int) round(($coverage * 0.55) + ($confidence * 0.45)),
            'coverage' => $coverage,
            'confidence' => $confidence,
            'notes' => $count >= 2 ? ['Positioning well defined'] : ($count >= 1 ? ['Add more positioning fields'] : ['Add industry, audience, beliefs, values, or tagline']),
        ];
    }

    protected function scoreStandards(array $typography, array $scoringRules, array $visual, ?array $brandColors, ?array $snapshotRaw = null): array
    {
        $palette = $scoringRules['allowed_color_palette'] ?? [];
        $hasPalette = ! empty($palette);
        $hasBrandColors = $brandColors && (
            ! empty($brandColors['primary'] ?? '') ||
            ! empty($brandColors['secondary'] ?? '') ||
            ! empty($brandColors['accent'] ?? '')
        );
        $hasTypography = ! empty(trim((string) self::unwrapScalar($typography['primary_font'] ?? ''))) || ! empty(trim((string) self::unwrapScalar($typography['secondary_font'] ?? '')));
        $hasRefs = ! empty($visual['approved_references'] ?? []);
        $count = ($hasPalette || $hasBrandColors ? 1 : 0) + ($hasTypography ? 1 : 0) + ($hasRefs ? 1 : 0);
        $coverage = min(100, $count * 40);
        $confidence = $count >= 2 ? 85 : ($count >= 1 ? 55 : 0);

        $standardsScore = (int) round(($coverage * 0.55) + ($confidence * 0.45));
        $snapshotAdjustment = 0;
        $snapshotRisks = [];

        if ($snapshotRaw && is_array($snapshotRaw)) {
            $draftColors = $this->normalizePaletteToHex($scoringRules['allowed_color_palette'] ?? []);
            $snapshotColors = $this->normalizeSnapshotColorsToHexStrings($snapshotRaw['primary_colors'] ?? []);
            $snapshotColors = array_filter($snapshotColors, fn ($c) => $c !== '');

            if (! empty($draftColors) && ! empty($snapshotColors)) {
                $overlap = count(array_intersect($draftColors, $snapshotColors));
                if ($overlap > 0) {
                    $snapshotAdjustment += 5;
                } else {
                    $snapshotAdjustment -= 5;
                    $snapshotRisks[] = ['id' => 'COH:COLOR_MISMATCH', 'label' => 'Website colors differ from draft palette', 'detail' => 'Detected website colors do not match allowed palette.'];
                }
            }

            $primaryFont = trim((string) self::unwrapScalar($typography['primary_font'] ?? ''));
            $detectedFonts = $this->normalizeSnapshotFontsToStrings($snapshotRaw['detected_fonts'] ?? []);
            $detectedFonts = array_filter($detectedFonts, fn ($f) => $f !== '');

            if ($primaryFont !== '' && ! empty($detectedFonts)) {
                $draftFontLower = strtolower($primaryFont);
                $match = in_array($draftFontLower, $detectedFonts, true);
                if ($match) {
                    $snapshotAdjustment += 5;
                } else {
                    $snapshotAdjustment -= 5;
                    $snapshotRisks[] = ['id' => 'COH:FONT_MISMATCH', 'label' => 'Website typography differs from draft', 'detail' => 'Detected fonts do not match primary font.'];
                }
            }

            $snapshotAdjustment = max(-10, min(10, $snapshotAdjustment));
        }

        $standardsScore = max(0, min(100, $standardsScore + $snapshotAdjustment));

        return [
            'score' => $standardsScore,
            'coverage' => $coverage,
            'confidence' => $confidence,
            'notes' => $count >= 2 ? ['Standards defined'] : ($count >= 1 ? ['Add colors, typography, or visual references'] : ['Define brand standards']),
            'snapshot_risks' => $snapshotRisks,
        ];
    }

    protected function normalizePaletteToHex(array $palette): array
    {
        $hex = [];
        foreach ($palette as $item) {
            if (is_array($item) && isset($item['hex'])) {
                $h = strtolower(trim((string) $item['hex']));
                if ($h !== '') {
                    $hex[] = $h;
                }
            } elseif (is_string($item) && trim($item) !== '') {
                $hex[] = strtolower(trim($item));
            }
        }

        return array_values(array_unique($hex));
    }

    /**
     * Normalize snapshot primary_colors to hex strings. Defensive against arrays/objects.
     */
    protected function normalizeSnapshotColorsToHexStrings(array $colors): array
    {
        $out = [];
        foreach ($colors as $c) {
            $hex = null;
            if (is_string($c)) {
                $hex = strtolower(trim($c));
            } elseif (is_array($c) && isset($c['hex']) && is_string($c['hex'])) {
                $hex = strtolower(trim($c['hex']));
            } elseif (is_array($c) && isset($c['value']) && is_string($c['value'])) {
                $hex = strtolower(trim($c['value']));
            }
            if ($hex !== null && $hex !== '') {
                $out[] = $hex;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Normalize snapshot detected_fonts to strings. Defensive against arrays/objects.
     */
    protected function normalizeSnapshotFontsToStrings(array $fonts): array
    {
        $out = [];
        foreach ($fonts as $f) {
            $name = null;
            if (is_string($f)) {
                $name = strtolower(trim($f));
            } elseif (is_array($f)) {
                $v = $f['value'] ?? $f['name'] ?? null;
                $name = is_string($v) ? strtolower(trim($v)) : null;
            }
            if ($name !== null && $name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    protected function isPlaceholder(string $s): bool
    {
        $lower = strtolower($s);
        foreach (self::PLACEHOLDER_PATTERNS as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }

        return false;
    }

    protected function deriveStrengths(array $sections, array $payload): array
    {
        $out = [];
        if (($sections['purpose']['score'] ?? 0) >= 70) {
            $out[] = ['id' => 'COH:STRONG_PURPOSE', 'label' => 'Clear purpose language', 'detail' => 'Mission and positioning are well defined.'];
        }
        if (($sections['archetype']['score'] ?? 0) >= 70) {
            $out[] = ['id' => 'COH:STRONG_ARCHETYPE', 'label' => 'Archetype selected', 'detail' => 'Brand archetype is clear.'];
        }
        if (($sections['standards']['score'] ?? 0) >= 70) {
            $out[] = ['id' => 'COH:STRONG_STANDARDS', 'label' => 'Standards defined', 'detail' => 'Visual and typography standards are set.'];
        }
        if (($sections['background']['score'] ?? 0) >= 70) {
            $out[] = ['id' => 'COH:STRONG_BACKGROUND', 'label' => 'Background sources', 'detail' => 'Website or materials provided.'];
        }

        return $out;
    }

    protected function deriveRisks(array $sections, array $payload): array
    {
        $out = [];
        if (($sections['standards']['score'] ?? 0) < 50) {
            $out[] = ['id' => 'COH:WEAK_STANDARDS', 'label' => 'Standards incomplete', 'detail' => 'Add colors, typography, or visual references.'];
        }
        if (($sections['purpose']['score'] ?? 0) < 50) {
            $out[] = ['id' => 'COH:WEAK_PURPOSE', 'label' => 'Purpose unclear', 'detail' => 'Define mission and positioning.'];
        }
        if (($sections['positioning']['score'] ?? 0) < 50) {
            $out[] = ['id' => 'COH:WEAK_POSITIONING', 'label' => 'Positioning incomplete', 'detail' => 'Add industry, audience, or competitive position.'];
        }
        if (($sections['expression']['score'] ?? 0) < 50) {
            $out[] = ['id' => 'COH:WEAK_EXPRESSION', 'label' => 'Expression thin', 'detail' => 'Add Brand Look, Brand Voice, tone keywords, or traits.'];
        }

        return $out;
    }

    /**
     * Unwrap scalar from wrapped value (e.g. {value: '...'} from AI apply or extraction merge).
     */
    protected static function unwrapScalar(mixed $val): mixed
    {
        if ($val === null || is_scalar($val)) {
            return $val;
        }
        if (is_array($val) && isset($val['value'])) {
            return $val['value'];
        }

        return null;
    }

    /**
     * Unwrap an array field that may be AI-wrapped ({value: [...], source: 'ai'}).
     */
    protected static function unwrapArrayField(mixed $val): array
    {
        if (is_array($val) && isset($val['value']) && is_array($val['value'])) {
            return $val['value'];
        }

        return is_array($val) ? $val : [];
    }
}
