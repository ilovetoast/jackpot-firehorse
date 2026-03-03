<?php

namespace App\Services\BrandDNA;

/**
 * Archetype-based expression suggestions. When primary_archetype is selected,
 * suggests traits and tone_keywords from canonical archetype map. Deterministic.
 */
class BrandArchetypeSuggestionService
{
    protected const ARCHETYPE_MAP = [
        'Outlaw' => [
            'traits' => ['rebellious', 'edgy', 'disruptive', 'bold', 'unconventional'],
            'tone_keywords' => ['daring', 'provocative', 'anti-establishment'],
        ],
        'Hero' => [
            'traits' => ['courageous', 'driven', 'strong', 'honorable'],
            'tone_keywords' => ['inspiring', 'confident', 'empowering'],
        ],
        'Creator' => [
            'traits' => ['imaginative', 'innovative', 'expressive', 'original'],
            'tone_keywords' => ['artistic', 'visionary', 'bold'],
        ],
        'Caregiver' => [
            'traits' => ['nurturing', 'compassionate', 'supportive', 'warm'],
            'tone_keywords' => ['gentle', 'reassuring', 'empathetic'],
        ],
        'Ruler' => [
            'traits' => ['authoritative', 'decisive', 'commanding', 'precise'],
            'tone_keywords' => ['confident', 'professional', 'assertive'],
        ],
        'Sage' => [
            'traits' => ['wise', 'knowledgeable', 'thoughtful', 'analytical'],
            'tone_keywords' => ['insightful', 'educational', 'authoritative'],
        ],
        'Explorer' => [
            'traits' => ['adventurous', 'independent', 'curious', 'pioneering'],
            'tone_keywords' => ['bold', 'inspiring', 'free-spirited'],
        ],
        'Everyman' => [
            'traits' => ['relatable', 'honest', 'down-to-earth', 'authentic'],
            'tone_keywords' => ['friendly', 'approachable', 'genuine'],
        ],
        'Jester' => [
            'traits' => ['playful', 'witty', 'fun', 'lighthearted'],
            'tone_keywords' => ['humorous', 'clever', 'engaging'],
        ],
        'Lover' => [
            'traits' => ['passionate', 'sensual', 'devoted', 'intimate'],
            'tone_keywords' => ['romantic', 'warm', 'alluring'],
        ],
        'Magician' => [
            'traits' => ['transformative', 'visionary', 'charismatic', 'innovative'],
            'tone_keywords' => ['mysterious', 'captivating', 'bold'],
        ],
        'Innocent' => [
            'traits' => ['pure', 'optimistic', 'trustworthy', 'hopeful'],
            'tone_keywords' => ['simple', 'uplifting', 'gentle'],
        ],
    ];

    public function generate(array $draftPayload): array
    {
        $payload = (new BrandDnaPayloadNormalizer)->normalize($draftPayload);
        $personality = $payload['personality'] ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];

        $primaryArchetype = trim((string) ($personality['primary_archetype'] ?? $personality['archetype'] ?? ''));
        if ($primaryArchetype === '') {
            return ['suggestions' => []];
        }

        $canonical = self::ARCHETYPE_MAP[ucfirst($primaryArchetype)] ?? null;
        if (! $canonical) {
            return ['suggestions' => []];
        }

        $suggestions = [];

        $traits = $personality['traits'] ?? [];
        $traits = is_array($traits) ? $traits : [];
        if (count($traits) < 3) {
            $suggestions[] = [
                'key' => 'SUG:expression.traits',
                'path' => 'personality.traits',
                'type' => 'merge',
                'value' => $canonical['traits'],
                'reason' => 'Suggested traits for ' . $primaryArchetype . ' archetype.',
                'confidence' => 0.8,
            ];
        }

        $toneKeywords = $scoringRules['tone_keywords'] ?? $personality['tone_keywords'] ?? [];
        $toneKeywords = is_array($toneKeywords) ? $toneKeywords : [];
        if (empty($toneKeywords)) {
            $suggestions[] = [
                'key' => 'SUG:expression.tone_keywords',
                'path' => 'scoring_rules.tone_keywords',
                'type' => 'merge',
                'value' => $canonical['tone_keywords'],
                'reason' => 'Suggested tone keywords for ' . $primaryArchetype . ' archetype.',
                'confidence' => 0.7,
            ];
        }

        if (count($toneKeywords) < 3) {
            $suggestions[] = [
                'key' => 'SUG:expression.tone_keywords.reinforce',
                'path' => 'scoring_rules.tone_keywords',
                'type' => 'merge',
                'value' => $canonical['tone_keywords'],
                'reason' => 'Strengthen tone alignment with selected archetype.',
                'confidence' => 0.75,
                'weight' => 0.75,
            ];
        }

        return [
            'suggestions' => $this->normalizeSuggestions($suggestions),
        ];
    }

    protected function normalizeSuggestions(array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $s) {
            $confidence = (float) ($s['confidence'] ?? 0);
            $confidence = max(0.0, min(1.0, $confidence));
            $weight = (float) ($s['weight'] ?? $confidence);
            $out[] = [
                'key' => $s['key'] ?? '',
                'path' => $s['path'] ?? '',
                'type' => $s['type'] ?? 'merge',
                'value' => $s['value'] ?? [],
                'reason' => $s['reason'] ?? '',
                'confidence' => $confidence,
                'weight' => $weight,
                'confidence_tier' => SuggestionConfidenceTier::fromWeight($weight),
            ];
        }

        return $out;
    }
}
