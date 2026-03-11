<?php

namespace App\Services\BrandDNA;

/**
 * Detects explicit archetype mentions from the allowlist.
 * Strongly prefers explicit matches over inference.
 */
class ArchetypeExplicitDetectionService
{
    protected const HEADLINE_BOOST = 0.15;

    protected const REPEATED_MENTION_BOOST = 0.08;

    protected const ATTRIBUTE_BLOCK_BOOST = 0.10;

    protected const BASE_CONFIDENCE = 0.85;

    /**
     * Detect explicit archetype from OCR text, page title, and classification.
     *
     * @param string|null $ocrText Raw OCR from page
     * @param string|null $pageTitle Page title from classification
     * @param array{title?: string, signals_present?: array} $classification
     * @return array{matched: bool, value?: string, confidence?: float, evidence?: array, source?: string}
     */
    public function detect(?string $ocrText, ?string $pageTitle, array $classification = []): array
    {
        $allowlist = config('brand_dna_archetypes.allowlist', []);
        $displayMap = config('brand_dna_archetypes.display_map', []);

        if (empty($allowlist)) {
            return ['matched' => false];
        }

        $texts = array_filter([
            $ocrText,
            $pageTitle,
            $classification['title'] ?? null,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        $combined = implode("\n", array_map('trim', $texts));
        if ($combined === '') {
            return ['matched' => false];
        }

        $evidence = [];
        $bestMatch = null;
        $bestConfidence = 0.0;
        $mentionCount = [];

        $lines = preg_split('/\r\n|\r|\n/', $combined);

        foreach ($allowlist as $key) {
            $display = $displayMap[$key] ?? ucfirst($key);
            $pattern = $this->buildPattern($key);
            $matchEvidence = [];
            $confidence = self::BASE_CONFIDENCE;
            $matchCount = 0;

            foreach ($lines as $i => $line) {
                if (preg_match_all($pattern, $line, $m)) {
                    $matchCount += count($m[0]);
                    $lineTrimmed = trim($line);
                    if ($lineTrimmed !== '' && ! in_array($lineTrimmed, $matchEvidence, true)) {
                        $matchEvidence[] = $lineTrimmed;
                    }
                    if ($i < 3 && $this->isHeadlineLike($line)) {
                        $confidence += self::HEADLINE_BOOST;
                    }
                    if ($this->isAttributeBlockLike($line)) {
                        $confidence += self::ATTRIBUTE_BLOCK_BOOST;
                    }
                }
            }

            if ($matchCount === 0) {
                continue;
            }

            if ($matchCount >= 2) {
                $confidence += self::REPEATED_MENTION_BOOST * min($matchCount - 1, 3);
            }

            $confidence = min(0.99, $confidence);

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $bestMatch = $display;
                $evidence = array_slice(array_values(array_unique($matchEvidence)), 0, 5);
            }
        }

        if ($bestMatch === null) {
            return ['matched' => false];
        }

        return [
            'matched' => true,
            'value' => $bestMatch,
            'confidence' => round($bestConfidence, 2),
            'evidence' => $evidence,
            'source' => 'explicit_detection',
        ];
    }

    protected function buildPattern(string $archetypeKey): string
    {
        $escaped = preg_quote($archetypeKey, '/');
        return '/\b' . $escaped . '\b/i';
    }

    protected function isHeadlineLike(string $line): bool
    {
        $trimmed = trim($line);
        if (strlen($trimmed) < 80 && preg_match('/^[A-Z\s]+$/u', $trimmed)) {
            return true;
        }
        return (bool) preg_match('/\bA\s+\w+\s+(?:FOR|OF|IN)\s+/i', $line);
    }

    protected function isAttributeBlockLike(string $line): bool
    {
        return (bool) preg_match('/\w+\s+ATTRIBUTES?\s*$/i', trim($line));
    }
}
