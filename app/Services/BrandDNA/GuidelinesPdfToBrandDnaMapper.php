<?php

namespace App\Services\BrandDNA;

/**
 * Maps extracted PDF text (from pdftotext) into Brand DNA draft patch structure.
 * Deterministic heuristics; no AI. Used by Brand Guidelines Builder prefill flow.
 */
class GuidelinesPdfToBrandDnaMapper
{
    /**
     * Map extracted text into a draft patch matching builder step sections.
     *
     * @return array{sources: array, identity: array, personality: array, typography: array, scoring_rules: array, visual: array, brand_colors?: array}
     */
    public function map(string $extractedText, string|int $assetId): array
    {
        $text = trim($extractedText);
        $lines = preg_split('/\r\n|\r|\n/', $text);

        $patch = [
            'sources' => [
                'notes' => "Imported from guidelines PDF (asset_id={$assetId}). Please review.",
                'website_url' => $this->extractWebsiteUrl($text),
                'social_urls' => $this->extractSocialUrls($text),
            ],
            'identity' => [
                'mission' => $this->extractSection($lines, ['Mission', 'Purpose', 'Our Purpose', 'Why we exist']),
                'positioning' => $this->extractSection($lines, ['Positioning', 'Brand Positioning', 'Promise', 'What we do', 'Our Promise']),
                'tagline' => $this->extractSection($lines, ['Tagline', 'Slogan']),
                'industry' => $this->extractSection($lines, ['Industry', 'Sector']),
                'target_audience' => $this->extractSection($lines, ['Audience', 'Target Audience', 'Who we serve']),
                'beliefs' => $this->extractListSection($lines, ['Beliefs', 'Our Beliefs']),
                'values' => $this->extractListSection($lines, ['Values', 'Core Values', 'Our Values']),
            ],
            'personality' => [
                'primary_archetype' => $this->extractArchetype($text),
                'candidate_archetypes' => [],
                'tone' => $this->extractSection($lines, ['Tone', 'Brand Tone', 'Messaging Tone']),
                'traits' => $this->extractListSection($lines, ['Traits', 'Personality', 'Brand Traits', 'Characteristics']),
                'voice_description' => $this->extractSection($lines, ['Voice', 'Brand Voice', 'Messaging', 'How we sound']),
            ],
            'typography' => [
                'primary_font' => $this->extractFont($text, 'primary'),
                'secondary_font' => $this->extractFont($text, 'secondary'),
                'heading_style' => $this->extractSection($lines, ['Heading', 'Headings', 'Heading Style']),
                'body_style' => $this->extractSection($lines, ['Body', 'Body Text', 'Body Style']),
            ],
            'scoring_rules' => [
                'allowed_color_palette' => $this->extractHexColors($text),
            ],
            'visual' => [
                'approved_references' => [],
            ],
        ];

        $brandColors = $this->extractBrandColors($text);
        if (! empty($brandColors)) {
            $patch['brand_colors'] = $brandColors;
        }

        return $this->stripEmptyLeaves($patch);
    }

    protected function extractWebsiteUrl(string $text): ?string
    {
        if (preg_match('/(https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]*\.?[a-zA-Z0-9][-a-zA-Z0-9]*\.[a-zA-Z]{2,}(?:\/[^\s]*)?)/', $text, $m)) {
            $url = trim($m[1]);
            if (! preg_match('/\.(jpg|jpeg|png|gif|pdf)(\?|$)/i', $url)) {
                return $url;
            }
        }

        return null;
    }

    protected function extractSocialUrls(string $text): array
    {
        $urls = [];
        $patterns = [
            '/(https?:\/\/(?:www\.)?instagram\.com\/[^\s\)]+)/i',
            '/(https?:\/\/(?:www\.)?facebook\.com\/[^\s\)]+)/i',
            '/(https?:\/\/(?:www\.)?tiktok\.com\/[^\s\)]+)/i',
            '/(https?:\/\/(?:www\.)?linkedin\.com\/[^\s\)]+)/i',
            '/(https?:\/\/(?:www\.)?twitter\.com\/[^\s\)]+)/i',
            '/(https?:\/\/(?:www\.)?x\.com\/[^\s\)]+)/i',
            '/(https?:\/\/(?:www\.)?youtube\.com\/[^\s\)]+)/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m)) {
                foreach ($m[1] as $u) {
                    $u = trim($u, '.,;');
                    if ($u && ! in_array($u, $urls, true)) {
                        $urls[] = $u;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    protected function extractSection(array $lines, array $headings): ?string
    {
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            foreach ($headings as $h) {
                if (stripos($trimmed, $h) === 0 && (str_ends_with($trimmed, ':') || strlen($trimmed) === strlen($h))) {
                    $content = substr($trimmed, strlen($h));
                    $content = ltrim($content, ': ');
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
                if (stripos($trimmed, $h) === 0 && (str_ends_with($trimmed, ':') || strlen($trimmed) === strlen($h))) {
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

    protected function looksLikeHeading(string $line): bool
    {
        return strlen($line) < 80 && preg_match('/^[A-Z][a-z]*(?:\s+[A-Z][a-z]*)*:?\s*$/', $line);
    }

    protected function extractArchetype(string $text): ?string
    {
        $archetypes = [
            'Creator', 'Caregiver', 'Ruler', 'Jester', 'Everyman', 'Lover',
            'Hero', 'Outlaw', 'Magician', 'Innocent', 'Sage', 'Explorer',
        ];
        foreach ($archetypes as $a) {
            if (preg_match('/\b' . preg_quote($a, '/') . '\b/i', $text)) {
                return $a;
            }
        }

        return null;
    }

    protected function extractFont(string $text, string $which): ?string
    {
        $patterns = $which === 'primary'
            ? ['/Primary\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i', '/Font[:\s]+([A-Za-z0-9\s\-]+)/i', '/Typography[:\s]+([A-Za-z0-9\s\-]+)/i']
            : ['/Secondary\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i', '/Body\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i'];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $font = trim($m[1]);
                if (strlen($font) > 0 && strlen($font) < 80) {
                    return $font;
                }
            }
        }

        return null;
    }

    protected function extractHexColors(string $text): array
    {
        $colors = [];
        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $text, $m)) {
            foreach ($m[0] as $hex) {
                $hex = str_starts_with($hex, '#') ? $hex : '#' . $hex;
                if (! in_array($hex, $colors, true)) {
                    $colors[] = ['hex' => $hex, 'role' => null];
                }
            }
        }

        return $colors;
    }

    protected function extractBrandColors(string $text): array
    {
        $result = [];
        if (preg_match('/Primary\s+Color[:\s]+#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})/i', $text, $m)) {
            $result['primary_color'] = str_starts_with($m[1], '#') ? $m[1] : '#' . $m[1];
        }
        if (preg_match('/Secondary\s+Color[:\s]+#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})/i', $text, $m)) {
            $result['secondary_color'] = str_starts_with($m[1], '#') ? $m[1] : '#' . $m[1];
        }
        if (preg_match('/Accent\s+Color[:\s]+#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})/i', $text, $m)) {
            $result['accent_color'] = str_starts_with($m[1], '#') ? $m[1] : '#' . $m[1];
        }

        return $result;
    }

    protected function stripEmptyLeaves(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $filtered = $this->stripEmptyLeaves($v);
                if ($filtered !== []) {
                    $out[$k] = $filtered;
                }
            } elseif ($v !== null && $v !== '' && $v !== []) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
