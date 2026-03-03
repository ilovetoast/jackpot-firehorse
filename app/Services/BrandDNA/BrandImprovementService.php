<?php

namespace App\Services\BrandDNA;

/**
 * Improve My Score — targeted suggestions for lowest-scoring section.
 * No draft mutation. Returns structured suggestions only.
 */
class BrandImprovementService
{
    /**
     * Identify lowest scoring section and generate targeted suggestions for that section only.
     *
     * @return array{suggestions: array, lowest_section: string|null}
     */
    public function suggestImprovements(array $draftPayload, array $coherence): array
    {
        $sections = $coherence['sections'] ?? [];
        if (empty($sections)) {
            return ['suggestions' => [], 'lowest_section' => null];
        }

        $lowest = null;
        $lowestScore = 101;
        foreach ($sections as $key => $section) {
            $score = (int) ($section['score'] ?? 0);
            if ($score < $lowestScore) {
                $lowestScore = $score;
                $lowest = $key;
            }
        }

        if ($lowest === null) {
            return ['suggestions' => [], 'lowest_section' => null];
        }

        $suggestions = $this->suggestionsForSection($lowest, $draftPayload, $sections[$lowest] ?? []);

        return [
            'suggestions' => $suggestions,
            'lowest_section' => $lowest,
        ];
    }

    protected function suggestionsForSection(string $sectionKey, array $payload, array $sectionData): array
    {
        $notes = $sectionData['notes'] ?? [];
        $suggestions = [];

        switch ($sectionKey) {
            case 'background':
                $suggestions[] = [
                    'key' => 'SUG:improve.background',
                    'path' => 'sources',
                    'type' => 'informational',
                    'value' => null,
                    'reason' => implode(' ', $notes) ?: 'Add website URL, social URLs, or brand materials.',
                    'confidence' => 0.7,
                ];
                break;
            case 'archetype':
                $suggestions[] = [
                    'key' => 'SUG:improve.archetype',
                    'path' => 'personality.primary_archetype',
                    'type' => 'informational',
                    'value' => null,
                    'reason' => implode(' ', $notes) ?: 'Select at least one archetype.',
                    'confidence' => 0.7,
                ];
                break;
            case 'purpose':
                $suggestions[] = [
                    'key' => 'SUG:improve.purpose',
                    'path' => 'identity',
                    'type' => 'informational',
                    'value' => null,
                    'reason' => implode(' ', $notes) ?: 'Define mission and positioning.',
                    'confidence' => 0.7,
                ];
                break;
            case 'expression':
                $suggestions[] = [
                    'key' => 'SUG:improve.expression',
                    'path' => 'personality',
                    'type' => 'informational',
                    'value' => null,
                    'reason' => implode(' ', $notes) ?: 'Add Brand Look, Brand Voice, tone keywords, or traits.',
                    'confidence' => 0.7,
                ];
                break;
            case 'positioning':
                $suggestions[] = [
                    'key' => 'SUG:improve.positioning',
                    'path' => 'identity',
                    'type' => 'informational',
                    'value' => null,
                    'reason' => implode(' ', $notes) ?: 'Add industry, audience, beliefs, values, or tagline.',
                    'confidence' => 0.7,
                ];
                break;
            case 'standards':
                $suggestions[] = [
                    'key' => 'SUG:improve.standards',
                    'path' => 'scoring_rules',
                    'type' => 'informational',
                    'value' => null,
                    'reason' => implode(' ', $notes) ?: 'Add colors, typography, or visual references.',
                    'confidence' => 0.7,
                ];
                break;
            default:
                break;
        }

        return $suggestions;
    }
}
