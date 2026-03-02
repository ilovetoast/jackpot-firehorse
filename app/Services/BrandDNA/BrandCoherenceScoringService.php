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
        int $brandMaterialCount = 0
    ): array {
        $payload = (new BrandDnaPayloadNormalizer)->normalize($draftPayload);
        $sources = $payload['sources'] ?? [];
        $identity = $payload['identity'] ?? [];
        $personality = $payload['personality'] ?? [];
        $visual = $payload['visual'] ?? [];
        $typography = $payload['typography'] ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];
        $brandColors = $brand?->primary_color ? [
            'primary' => $brand->primary_color,
            'secondary' => $brand->secondary_color,
            'accent' => $brand->accent_color,
        ] : null;

        $sections = [
            'background' => $this->scoreBackground($sources, $brandMaterialCount),
            'archetype' => $this->scoreArchetype($personality),
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

        return [
            'overall' => [
                'score' => min(100, max(0, $overallScore)),
                'confidence' => (int) round($overallConfidence),
                'coverage' => (int) round($overallCoverage),
            ],
            'sections' => $sections,
            'strengths' => array_slice($strengths, 0, 5),
            'risks' => array_slice($risks, 0, 5),
        ];
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

    protected function scoreArchetype(array $personality): array
    {
        $primary = $personality['primary_archetype'] ?? null;
        $candidates = $personality['candidate_archetypes'] ?? [];
        $selected = array_filter(array_merge(
            $primary ? [$primary] : [],
            is_array($candidates) ? $candidates : []
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
        $mission = trim((string) ($identity['mission'] ?? ''));
        $positioning = trim((string) ($identity['positioning'] ?? ''));
        $hasMission = strlen($mission) >= 20 && ! $this->isPlaceholder($mission);
        $hasPositioning = strlen($positioning) >= 20 && ! $this->isPlaceholder($positioning);
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
        $brandLook = trim((string) ($personality['brand_look'] ?? ''));
        $voiceDescription = trim((string) ($personality['voice_description'] ?? ''));
        $toneKeywords = $scoringRules['tone_keywords'] ?? $personality['tone_keywords'] ?? [];
        $traits = $personality['traits'] ?? [];
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
        $industry = ! empty(trim((string) ($identity['industry'] ?? '')));
        $audience = ! empty(trim((string) ($identity['target_audience'] ?? '')));
        $marketCategory = ! empty(trim((string) ($identity['market_category'] ?? '')));
        $competitivePosition = ! empty(trim((string) ($identity['competitive_position'] ?? '')));
        $tagline = ! empty(trim((string) ($identity['tagline'] ?? '')));
        $beliefs = $identity['beliefs'] ?? [];
        $values = $identity['values'] ?? [];
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
        $hasTypography = ! empty(trim((string) ($typography['primary_font'] ?? ''))) || ! empty(trim((string) ($typography['secondary_font'] ?? '')));
        $hasRefs = ! empty($visual['approved_references'] ?? []);
        $count = ($hasPalette || $hasBrandColors ? 1 : 0) + ($hasTypography ? 1 : 0) + ($hasRefs ? 1 : 0);
        $coverage = min(100, $count * 40);
        $confidence = $count >= 2 ? 85 : ($count >= 1 ? 55 : 0);

        $standardsScore = (int) round(($coverage * 0.55) + ($confidence * 0.45));
        $snapshotAdjustment = 0;
        $snapshotRisks = [];

        if ($snapshotRaw && is_array($snapshotRaw)) {
            $draftColors = $this->normalizePaletteToHex($scoringRules['allowed_color_palette'] ?? []);
            $snapshotColors = array_map(fn ($c) => strtolower(trim((string) $c)), $snapshotRaw['primary_colors'] ?? []);
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

            $primaryFont = trim((string) ($typography['primary_font'] ?? ''));
            $detectedFonts = array_map(fn ($f) => strtolower(trim((string) $f)), $snapshotRaw['detected_fonts'] ?? []);
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
}
