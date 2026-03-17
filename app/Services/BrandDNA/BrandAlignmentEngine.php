<?php

namespace App\Services\BrandDNA;

/**
 * Brand Alignment Engine — cross-field validations.
 * Deterministic rules; produces findings with severity and suggestions.
 */
class BrandAlignmentEngine
{
    protected const ARCHETYPE_TONE_MAP = [
        'Ruler' => ['decisive', 'authoritative', 'precise', 'commanding', 'confident'],
        'Creator' => ['imaginative', 'innovative', 'expressive', 'artistic', 'original'],
        'Caregiver' => ['warm', 'supportive', 'nurturing', 'compassionate', 'gentle'],
        'Jester' => ['playful', 'humorous', 'witty', 'fun', 'lighthearted'],
        'Everyman' => ['friendly', 'relatable', 'down-to-earth', 'approachable', 'honest'],
        'Lover' => ['passionate', 'sensual', 'romantic', 'intimate', 'devoted'],
        'Hero' => ['courageous', 'determined', 'inspiring', 'bold', 'strong'],
        'Outlaw' => ['rebellious', 'edgy', 'disruptive', 'bold', 'unconventional'],
        'Magician' => ['transformative', 'visionary', 'charismatic', 'mysterious', 'innovative'],
        'Innocent' => ['pure', 'optimistic', 'simple', 'trustworthy', 'hopeful'],
        'Sage' => ['wise', 'knowledgeable', 'thoughtful', 'analytical', 'insightful'],
        'Explorer' => ['adventurous', 'independent', 'pioneering', 'curious', 'free'],
    ];

    public function analyze(array $draftPayload): array
    {
        $payload = (new BrandDnaPayloadNormalizer)->normalize($draftPayload);
        $payload = self::deepUnwrapAiValues($payload);
        $findings = [];

        $this->checkArchetypeTone($payload, $findings);
        $this->checkPurposeAudience($payload, $findings);
        $this->checkStandardsMood($payload, $findings);
        $this->checkTypographyVoice($payload, $findings);
        $this->checkPositioningComplete($payload, $findings);

        $score = 100 - (count($findings) * 15);
        $score = max(0, min(100, $score));
        $confidence = max(0, 100 - (count($findings) * 20));

        return [
            'summary' => [
                'score' => $score,
                'confidence' => $confidence,
            ],
            'findings' => $findings,
        ];
    }

    protected function checkArchetypeTone(array $payload, array &$findings): void
    {
        $personality = $payload['personality'] ?? [];
        $primary = $personality['primary_archetype'] ?? $personality['archetype'] ?? null;
        $candidates = $personality['candidate_archetypes'] ?? [];
        $archetype = $primary ?: (is_array($candidates) && count($candidates) > 0 ? $candidates[0] : null);
        if (! $archetype) {
            return;
        }

        $scoringRules = $payload['scoring_rules'] ?? [];
        $toneKeywords = $scoringRules['tone_keywords'] ?? $personality['tone_keywords'] ?? [];
        if (empty($toneKeywords) || ! is_array($toneKeywords)) {
            return;
        }

        $expected = self::ARCHETYPE_TONE_MAP[ucfirst($archetype)] ?? [];
        if (empty($expected)) {
            return;
        }

        $toneLower = array_map('strtolower', array_map('strval', $toneKeywords));
        $expectedLower = array_map('strtolower', $expected);
        $overlap = count(array_intersect($toneLower, $expectedLower));
        if ($overlap === 0 && count($toneKeywords) >= 2) {
            $findings[] = [
                'id' => 'ALIGN:ARCHETYPE_TONE_MISMATCH',
                'severity' => 'med',
                'title' => 'Archetype and tone may not align',
                'detail' => sprintf('Archetype "%s" typically aligns with tones like: %s. Consider adding some of these to your tone keywords.', $archetype, implode(', ', array_slice($expected, 0, 3))),
                'affected_paths' => ['personality.primary_archetype', 'scoring_rules.tone_keywords'],
                'suggestion' => [
                    'path' => 'scoring_rules.tone_keywords',
                    'value' => array_unique(array_merge($toneKeywords, array_slice($expected, 0, 2))),
                    'rationale' => 'Suggested tone keywords that align with your archetype.',
                ],
            ];
        }
    }

    protected function checkPurposeAudience(array $payload, array &$findings): void
    {
        $identity = $payload['identity'] ?? [];
        $mission = strtolower(trim((string) ($identity['mission'] ?? '')));
        $audience = strtolower(trim((string) ($identity['target_audience'] ?? '')));
        if (strlen($mission) < 10 || strlen($audience) < 3) {
            return;
        }
        if (str_contains($mission, 'everyone') && strlen($audience) > 5 && ! str_contains($audience, 'everyone')) {
            $findings[] = [
                'id' => 'ALIGN:PURPOSE_AUDIENCE_MISMATCH',
                'severity' => 'low',
                'title' => 'Mission vs audience scope',
                'detail' => 'Mission suggests broad appeal ("everyone") but target audience is narrow. Consider aligning scope.',
                'affected_paths' => ['identity.mission', 'identity.target_audience'],
                'suggestion' => null,
            ];
        }
    }

    protected function checkStandardsMood(array $payload, array &$findings): void
    {
        $scoringRules = $payload['scoring_rules'] ?? [];
        $toneKeywords = array_map('strtolower', array_map('strval', $scoringRules['tone_keywords'] ?? []));
        $palette = $scoringRules['allowed_color_palette'] ?? [];
        $paletteCount = is_array($palette) ? count($palette) : 0;
        if (in_array('minimal', $toneKeywords) && $paletteCount > 8) {
            $findings[] = [
                'id' => 'ALIGN:STANDARDS_MOOD_COLORS',
                'severity' => 'med',
                'title' => 'Too many colors for minimal tone',
                'detail' => 'Tone suggests "minimal" but color palette has many colors. Consider reducing to a smaller set.',
                'affected_paths' => ['scoring_rules.tone_keywords', 'scoring_rules.allowed_color_palette'],
                'suggestion' => [
                    'path' => 'scoring_rules.allowed_color_palette',
                    'value' => array_slice($palette, 0, 5),
                    'rationale' => 'A smaller palette aligns better with minimal tone.',
                ],
            ];
        }
    }

    protected function checkTypographyVoice(array $payload, array &$findings): void
    {
        $typography = $payload['typography'] ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];
        $personality = $payload['personality'] ?? [];
        $toneKeywords = array_map('strtolower', array_map('strval', $scoringRules['tone_keywords'] ?? $personality['tone_keywords'] ?? []));
        $primaryFont = strtolower(trim((string) ($typography['primary_font'] ?? '')));
        $utilitarianFonts = ['arial', 'helvetica', 'system-ui', 'sans-serif'];
        if (! in_array('luxury', $toneKeywords) && ! in_array('premium', $toneKeywords)) {
            return;
        }
        if (in_array($primaryFont, $utilitarianFonts) || $primaryFont === '') {
            $findings[] = [
                'id' => 'ALIGN:TYPOGRAPHY_VOICE',
                'severity' => 'low',
                'title' => 'Typography and luxury tone',
                'detail' => 'Tone suggests luxury/premium. Consider exploring serif or high-contrast fonts for a more distinctive voice.',
                'affected_paths' => ['typography.primary_font', 'scoring_rules.tone_keywords'],
                'suggestion' => [
                    'path' => 'typography.primary_font',
                    'value' => 'Georgia',
                    'rationale' => 'Serif fonts often convey luxury and premium feel.',
                ],
            ];
        }
    }

    protected function checkPositioningComplete(array $payload, array &$findings): void
    {
        $identity = $payload['identity'] ?? [];
        $industry = ! empty(trim((string) ($identity['industry'] ?? '')));
        $audience = ! empty(trim((string) ($identity['target_audience'] ?? '')));
        $marketCategory = ! empty(trim((string) ($identity['market_category'] ?? '')));
        $competitivePosition = ! empty(trim((string) ($identity['competitive_position'] ?? '')));
        $count = ($industry ? 1 : 0) + ($audience ? 1 : 0) + ($marketCategory ? 1 : 0) + ($competitivePosition ? 1 : 0);
        if ($count === 0) {
            $findings[] = [
                'id' => 'ALIGN:POSITIONING_INCOMPLETE',
                'severity' => 'med',
                'title' => 'Positioning incomplete',
                'detail' => 'Add industry, target audience, market category, or competitive position for stronger alignment.',
                'affected_paths' => ['identity.industry', 'identity.target_audience'],
                'suggestion' => null,
            ];
        }
        if (! $competitivePosition && $count > 0) {
            $findings[] = [
                'id' => 'ALIGN:COMPETITIVE_POSITION_MISSING',
                'severity' => 'med',
                'title' => 'Positioning incomplete',
                'detail' => 'Competitive position is not defined. Add how you differentiate from competitors.',
                'affected_paths' => ['identity.competitive_position'],
                'suggestion' => null,
            ];
        }
    }

    protected static function deepUnwrapAiValues(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['value'], $value['source'])) {
                $result[$key] = $value['value'];
            } elseif (is_array($value)) {
                $result[$key] = self::deepUnwrapAiValues($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
