<?php

namespace App\Services\BrandDNA\Extraction;

/**
 * Extracts structured brand signals from Brand Guidelines PDF text.
 * Uses deterministic heuristics; no AI. Detects explicit declarations.
 */
class BrandGuidelinesProcessor
{
    protected const ARCHETYPE_HEADINGS = [
        'Brand Archetype:',
        'Archetype:',
        'Primary Archetype:',
    ];

    protected const ARCHETYPES = [
        'Creator', 'Caregiver', 'Ruler', 'Jester', 'Everyman', 'Lover',
        'Hero', 'Outlaw', 'Magician', 'Innocent', 'Sage', 'Explorer',
    ];

    public function process(string $extractedText): array
    {
        $schema = BrandExtractionSchema::empty();
        $text = trim($extractedText);
        $lines = preg_split('/\r\n|\r|\n/', $text);

        $schema['identity'] = [
            'mission' => $this->extractSection($lines, ['Mission', 'Our Mission', 'Purpose', 'Our Purpose']),
            'vision' => $this->extractSection($lines, ['Vision', 'Our Vision']),
            'positioning' => $this->extractSection($lines, ['Positioning', 'Brand Positioning', 'Promise', 'Our Promise']),
            'industry' => $this->extractSection($lines, ['Industry', 'Sector']),
            'tagline' => $this->extractSection($lines, ['Tagline', 'Slogan']),
        ];

        $archetypeResult = $this->extractExplicitArchetype($text);
        $schema['personality'] = [
            'primary_archetype' => $archetypeResult['value'],
            'traits' => $this->extractListSection($lines, ['Traits', 'Personality', 'Brand Traits', 'Characteristics']),
            'tone_keywords' => $this->extractToneKeywords($text, $lines),
        ];

        $hexColors = $this->extractHexColors($text);
        $fonts = $this->extractFonts($text);
        $schema['visual'] = [
            'primary_colors' => $hexColors,
            'secondary_colors' => [],
            'fonts' => $fonts,
            'logo_description' => null,
        ];

        $schema['explicit_signals'] = [
            'archetype_declared' => $archetypeResult['explicit'],
            'mission_declared' => $schema['identity']['mission'] !== null,
            'positioning_declared' => $schema['identity']['positioning'] !== null,
        ];

        $schema['sources']['pdf'] = ['extracted' => true];
        $schema['confidence'] = $this->computeConfidence($schema);

        return $schema;
    }

    protected function extractExplicitArchetype(string $text): array
    {
        $textLower = strtolower($text);
        foreach (self::ARCHETYPE_HEADINGS as $heading) {
            $pattern = '/\b' . preg_quote($heading, '/') . '\s*([A-Za-z]+)/i';
            if (preg_match($pattern, $text, $m)) {
                $value = trim($m[1]);
                foreach (self::ARCHETYPES as $a) {
                    if (strcasecmp($value, $a) === 0) {
                        return ['value' => $a, 'explicit' => true];
                    }
                }
            }
        }
        foreach (self::ARCHETYPES as $a) {
            if (preg_match('/\b' . preg_quote($a, '/') . '\b/i', $text)) {
                return ['value' => $a, 'explicit' => false];
            }
        }

        return ['value' => null, 'explicit' => false];
    }

    protected function extractSection(array $lines, array $headings): ?string
    {
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            foreach ($headings as $h) {
                if (stripos($trimmed, $h) === 0 && (str_ends_with($trimmed, ':') || strlen($trimmed) <= strlen($h) + 2)) {
                    $content = substr($trimmed, strlen($h));
                    $content = trim($content, ': ');
                    if ($content !== '') {
                        return $content;
                    }
                    $next = [];
                    for ($j = $i + 1; $j < count($lines) && $j < $i + 10; $j++) {
                        $n = trim($lines[$j]);
                        if ($n === '' || $this->looksLikeHeading($n)) {
                            break;
                        }
                        $next[] = $n;
                    }

                    return implode(' ', $next) ?: null;
                }
            }
        }

        return null;
    }

    protected function extractListSection(array $lines, array $headings): array
    {
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            foreach ($headings as $h) {
                if (stripos($trimmed, $h) === 0 && (str_ends_with($trimmed, ':') || strlen($trimmed) <= strlen($h) + 2)) {
                    $items = [];
                    for ($j = $i + 1; $j < count($lines) && $j < $i + 20; $j++) {
                        $n = trim($lines[$j]);
                        if ($n === '' || $this->looksLikeHeading($n)) {
                            break;
                        }
                        $parts = preg_split('/[,;]|\s+-\s+/', $n);
                        foreach ($parts as $p) {
                            $p = trim($p, " \t\n\r\0\x0B•-");
                            if ($p !== '') {
                                $items[] = $p;
                            }
                        }
                    }

                    return array_values(array_unique($items));
                }
            }
        }

        return [];
    }

    protected function extractToneKeywords(string $text, array $lines): array
    {
        $fromSection = $this->extractListSection($lines, ['Tone', 'Brand Tone', 'Tone Keywords', 'Messaging Tone']);
        if (! empty($fromSection)) {
            return $fromSection;
        }
        if (preg_match('/Tone[:\s]+([^\n]+)/i', $text, $m)) {
            $parts = preg_split('/[,;]/', trim($m[1]));
            $out = array_map('trim', $parts);

            return array_values(array_filter($out, fn ($p) => $p !== ''));
        }

        return [];
    }

    protected function looksLikeHeading(string $line): bool
    {
        return strlen($line) < 80 && preg_match('/^[A-Z][a-z]*(?:\s+[A-Z][a-z]*)*:?\s*$/', $line);
    }

    protected function extractHexColors(string $text): array
    {
        $colors = [];
        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $text, $m)) {
            foreach ($m[0] as $hex) {
                $hex = str_starts_with($hex, '#') ? $hex : '#' . $hex;
                if (! in_array($hex, $colors, true)) {
                    $colors[] = $hex;
                }
            }
        }

        return $colors;
    }

    protected function extractFonts(string $text): array
    {
        $fonts = [];
        $patterns = [
            '/Primary\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Secondary\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Typography[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Body\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m)) {
                foreach ($m[1] as $font) {
                    $font = trim($font);
                    if (strlen($font) > 0 && strlen($font) < 80 && ! in_array($font, $fonts, true)) {
                        $fonts[] = $font;
                    }
                }
            }
        }

        return array_values(array_unique($fonts));
    }

    protected function computeConfidence(array $schema): float
    {
        $signals = 0;
        $total = 0;
        foreach ($schema['identity'] as $v) {
            $total++;
            if ($v !== null && $v !== '') {
                $signals++;
            }
        }
        foreach ($schema['personality'] as $k => $v) {
            $total++;
            if (($k === 'primary_archetype' && $v) || ($k !== 'primary_archetype' && ! empty($v))) {
                $signals++;
            }
        }
        if (! empty($schema['visual']['primary_colors']) || ! empty($schema['visual']['fonts'])) {
            $signals++;
        }

        return $total > 0 ? min(1.0, $signals / max(5, $total)) : 0.0;
    }
}
