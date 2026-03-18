<?php

namespace App\Services\BrandDNA;

/**
 * Converts raw OCR/PDF text into structured brand guideline sections.
 * Detects section headings via heuristics and known dictionary.
 * Supports Table of Contents detection for page mapping.
 * Suppresses page furniture (headers, footers, addresses) before parsing.
 */
class BrandGuidelineSectionParser
{
    protected const KNOWN_SECTIONS = [
        'BRAND STORY',
        'BACKGROUND',
        'BRAND ARCHETYPE',
        'ARCHETYPE',
        'BELIEFS',
        'VALUES',
        'PURPOSE',
        'PROMISE',
        'BRAND POSITIONING',
        'POSITIONING',
        'BRAND EXPERIENCE',
        'BRAND STYLE',
        'BRAND VOICE',
        'VOICE',
        'BRAND IDENTITY',
        'IDENTITY',
        'COLOR PALETTE',
        'COLORS',
        'TYPOGRAPHY',
        'LOGO USAGE',
        'LOGO',
        'TABLE OF CONTENTS',
        'CONTENTS',
    ];

    protected const FURNITURE_PAGE_THRESHOLD = 3;

    protected const MIN_ESTIMATED_PAGES = 4;

    protected const QUALITY_ADEQUATE_CONTENT_LENGTH = 100;

    protected const QUALITY_MIN_CONTENT_LENGTH = 30;

    protected const QUALITY_TOC_BOOST = 0.15;

    protected const QUALITY_KNOWN_SECTION_BOOST = 0.10;

    protected const QUALITY_CONTENT_LENGTH_BOOST = 0.10;

    protected const QUALITY_SHORT_PENALTY = 0.20;

    protected const QUALITY_WEAK_HEADING_PENALTY = 0.15;

    protected const REPEATED_WEAK_THRESHOLD = 3;

    protected const REPEATED_WEAK_QUALITY_MAX = 0.35;

    protected const REPEATED_WEAK_CONTENT_LENGTH_MAX = 80;

    protected const PROTECTED_SECTIONS = [
        'BRAND STORY',
        'BRAND VOICE',
        'BRAND POSITIONING',
        'PURPOSE',
        'TYPOGRAPHY',
        'COLOR PALETTE',
        'LOGO USAGE',
        'VALUES',
        'BELIEFS',
        'MISSION',
    ];

    /**
     * Parse raw PDF text into structured sections.
     *
     * @return array{sections: array<int, array{title: string, page: ?int, content: string, source?: string, confidence?: float}>, section_count: int, full_text: string, toc_map: array<string, int>, suppressed_lines?: array<string>}
     */
    public static function parse(string $pdfText): array
    {
        $text = trim($pdfText);
        $rawLines = preg_split('/\r\n|\r|\n/', $text);

        $lineStats = self::computeLineStats($rawLines);
        $suppressedLines = [];
        $lines = [];
        foreach ($rawLines as $line) {
            $normalized = self::normalizeOcrLine($line);
            if ($normalized === '') {
                continue;
            }
            if (self::isGarbageLine($normalized)) {
                if (self::looksLikeAddressOrFurniture($normalized)) {
                    $suppressedLines[$normalized] = true;
                }
                continue;
            }
            if (self::isRepeatedPageFurniture($normalized, $lineStats)) {
                $suppressedLines[$normalized] = true;
                continue;
            }
            $lines[] = $normalized;
        }

        $tocMap = self::detectTableOfContents($lines);
        $sections = self::parseSections($lines, $tocMap);

        $titleStats = self::computeTitleStats($sections);
        [$usableSections, $suppressedSections, $collapsedSections] = self::suppressAndCollapseRepeatedWeakSections($sections, $titleStats);

        $result = [
            'sections' => $usableSections,
            'section_count' => count($usableSections),
            'section_count_raw' => count($sections),
            'section_count_suppressed' => count($sections) - count($usableSections),
            'full_text' => implode("\n", $lines),
            'toc_map' => $tocMap,
        ];
        if (! empty($suppressedLines)) {
            $result['suppressed_lines'] = array_keys($suppressedLines);
        }
        if (! empty($suppressedSections)) {
            $result['suppressed_sections'] = $suppressedSections;
        }
        if (! empty($collapsedSections)) {
            $result['collapsed_sections'] = $collapsedSections;
        }

        return $result;
    }

    protected static function computeLineStats(array $lines): array
    {
        $normalizedToPages = [];
        $estimatedCharsPerPage = 3000;
        $totalChars = 0;
        foreach ($lines as $line) {
            $n = self::normalizeOcrLine($line);
            if ($n === '') {
                continue;
            }
            $totalChars += strlen($line) + 1;
            $page = max(1, (int) ceil($totalChars / $estimatedCharsPerPage));
            if (! isset($normalizedToPages[$n])) {
                $normalizedToPages[$n] = [];
            }
            $normalizedToPages[$n][$page] = true;
        }

        $stats = [];
        foreach ($normalizedToPages as $norm => $pages) {
            $stats[$norm] = count($pages);
        }

        return $stats;
    }

    protected static function isRepeatedPageFurniture(string $line, array $lineStats): bool
    {
        $norm = self::normalizeOcrLine($line);
        if ($norm === '') {
            return true;
        }

        $pageCount = $lineStats[$norm] ?? 0;

        if (preg_match('/^[A-Z][A-Z\s&\/\-]+$/', $norm) && strlen($norm) <= 60 && $pageCount >= self::REPEATED_WEAK_THRESHOLD) {
            return false;
        }

        if ($pageCount >= self::FURNITURE_PAGE_THRESHOLD) {
            return true;
        }

        if (preg_match('/\b\d{5}(?:-\d{4})?\b/', $norm)) {
            return true;
        }
        if (preg_match('/\b(?:HWY|HIGHWAY|US\s+HWY|US\s+HIGHWAY|STREET|AVE|BLVD|RD|USA)\b/i', $norm)) {
            return true;
        }
        if (str_contains($norm, '•') && $pageCount >= 2) {
            return true;
        }
        if (preg_match('/^[A-Z\s•\-]+$/', $norm) && strlen($norm) > 20 && $pageCount >= 2) {
            return true;
        }

        return false;
    }

    protected static function normalizeOcrLine(string $line): string
    {
        $s = trim($line);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = self::collapseLetterSpacedText($s);
        $s = preg_replace('/^[\s\-\.\*•]+|[\s\-\.\*•]+$/', '', $s);
        $s = preg_replace('/^[\d]+$/m', '', $s);
        $s = trim($s);
        if (strlen($s) < 2 && ! preg_match('/^\d+$/', $s)) {
            return '';
        }

        return $s;
    }

    /**
     * Collapse letter-spaced uppercase text from PDF typography.
     * "N A R R A T I V E" → "NARRATIVE", "B R A N D" → "BRAND"
     * Handles mixed groups like "N A R R AT I V E" (1-2 char groups).
     */
    public static function collapseLetterSpacedText(string $text): string
    {
        return preg_replace_callback(
            '/(?<![A-Za-z])([A-Z]{1,2}(?:\s[A-Z]{1,2}){3,})(?![A-Za-z])/',
            function (array $m) {
                return str_replace(' ', '', $m[1]);
            },
            $text
        );
    }

    protected static function isGarbageLine(string $line): bool
    {
        if (strlen($line) < 2) {
            return true;
        }
        if (preg_match('/^[^\p{L}\p{N}]+$/u', $line)) {
            return true;
        }
        if (self::looksLikeAddressOrFurniture($line)) {
            return true;
        }
        if (preg_match('/^[\.\-_\*•\s]+$/', $line)) {
            return true;
        }

        return false;
    }

    protected static function looksLikeAddressOrFurniture(string $line): bool
    {
        return (bool) preg_match('/\b\d{5}(?:-\d{4})?\b/', $line)
            || (bool) preg_match('/\b(?:HWY|HIGHWAY|US\s+HWY|USA|STREET|AVE|BLVD)\b/i', $line);
    }

    protected static function isSectionHeading(string $line, array $tocMap): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        $upper = strtoupper($line);
        $normalized = preg_replace('/\s+/', ' ', $upper);

        if (in_array($normalized, self::KNOWN_SECTIONS, true)) {
            return true;
        }

        foreach (self::KNOWN_SECTIONS as $known) {
            if (str_starts_with($normalized, $known) || $normalized === $known) {
                return true;
            }
        }

        if (isset($tocMap[$normalized])) {
            return true;
        }
        foreach (array_keys($tocMap) as $tocKey) {
            if (str_contains($tocKey, $normalized) || str_contains($normalized, $tocKey)) {
                return true;
            }
        }

        return strlen($line) < 60
            && $upper === $line
            && preg_match('/[A-Z]/', $line)
            && ! preg_match('/[.!?]/', $line);
    }

    protected static function detectTableOfContents(array $lines): array
    {
        $tocMap = [];
        $inToc = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            $upper = strtoupper($trimmed);

            if (preg_match('/^(TABLE\s+OF\s+)?CONTENTS$/i', $upper) || $upper === 'CONTENTS') {
                $inToc = true;
                continue;
            }

            if ($inToc) {
                if (preg_match('/^\d+\s*$/', $trimmed) || preg_match('/^(INTRODUCTION|FOREWORD)/i', $trimmed)) {
                    continue;
                }
                if (preg_match('/^(?<title>[A-Z][A-Z\s&\/\-]+?)\s*\.{2,}\s*(?<page>\d+)\s*$/u', $trimmed, $m)) {
                    $title = trim(preg_replace('/\s+/', ' ', $m['title']));
                    $page = (int) $m['page'];
                    $tocMap[strtoupper($title)] = $page;
                } elseif (preg_match('/^(?<title>[A-Z][A-Z\s&\/\-]+?)\s+(?<page>\d+)\s*$/u', $trimmed, $m)) {
                    $title = trim(preg_replace('/\s+/', ' ', $m['title']));
                    $page = (int) $m['page'];
                    $tocMap[strtoupper($title)] = $page;
                } elseif (preg_match('/^([A-Za-z][A-Za-z\s\-]+?)\s*[\.\s]+\s*(\d+)\s*$/', $trimmed, $m)) {
                    $title = trim($m[1]);
                    $page = (int) $m[2];
                    $tocMap[strtoupper($title)] = $page;
                } elseif (preg_match('/^([A-Za-z][A-Za-z\s\-]+)\s+(\d+)\s*$/', $trimmed, $m)) {
                    $title = trim($m[1]);
                    $page = (int) $m[2];
                    $tocMap[strtoupper($title)] = $page;
                }
                if (strlen($trimmed) > 100 && ! preg_match('/\d+\s*$/', $trimmed)) {
                    $inToc = false;
                }
            }
        }

        return $tocMap;
    }

    protected static function parseSections(array $lines, array $tocMap): array
    {
        $sections = [];
        $currentSection = null;
        $estimatedPage = 1;
        $charsPerPage = 3000;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (self::isSectionHeading($trimmed, $tocMap)) {
                $title = $trimmed;
                $upperTitle = strtoupper($title);
                $page = $tocMap[$upperTitle] ?? null;
                foreach (array_keys($tocMap) as $tocKey) {
                    if (str_contains($tocKey, $upperTitle) || str_contains($upperTitle, $tocKey)) {
                        $page = $tocMap[$tocKey];
                        break;
                    }
                }

                $source = isset($tocMap[$upperTitle]) ? 'toc' : 'heuristic';
                $confidence = $source === 'toc' ? 0.95 : 0.7;

                $currentSection = [
                    'title' => $title,
                    'page' => $page,
                    'content' => '',
                    'source' => $source,
                    'confidence' => $confidence,
                ];
                $sections[] = &$currentSection;
            } elseif ($currentSection !== null) {
                $currentSection['content'] .= $trimmed . ' ';
            }
        }

        $sections = array_map(static fn ($s) => array_merge($s, ['content' => trim($s['content'])]), $sections);

        foreach ($sections as &$s) {
            $content = $s['content'] ?? '';
            $contentLength = strlen($content);
            $s['content_length'] = $contentLength;
            $s['quality_score'] = self::computeSectionQualityScore($s, $contentLength);
        }

        return $sections;
    }

    protected static function computeSectionQualityScore(array $section, int $contentLength): float
    {
        $score = 0.5;

        if (($section['source'] ?? 'heuristic') === 'toc') {
            $score += self::QUALITY_TOC_BOOST;
        }

        $upperTitle = strtoupper(trim($section['title'] ?? ''));
        if (in_array($upperTitle, self::KNOWN_SECTIONS, true)) {
            $score += self::QUALITY_KNOWN_SECTION_BOOST;
        } else {
            foreach (self::KNOWN_SECTIONS as $known) {
                if (str_contains($upperTitle, $known) || str_contains($known, $upperTitle)) {
                    $score += self::QUALITY_KNOWN_SECTION_BOOST;
                    break;
                }
            }
        }

        if ($contentLength >= self::QUALITY_ADEQUATE_CONTENT_LENGTH) {
            $score += self::QUALITY_CONTENT_LENGTH_BOOST;
        } elseif ($contentLength >= self::QUALITY_MIN_CONTENT_LENGTH) {
            $score += self::QUALITY_CONTENT_LENGTH_BOOST * 0.5;
        }

        if ($contentLength < self::QUALITY_MIN_CONTENT_LENGTH) {
            $score -= self::QUALITY_SHORT_PENALTY;
        }

        $confidence = (float) ($section['confidence'] ?? 0.7);
        if (($section['source'] ?? 'heuristic') === 'heuristic' && $confidence < 0.8) {
            $score -= self::QUALITY_WEAK_HEADING_PENALTY;
        }

        return round(max(0.0, min(1.0, $score)), 2);
    }

    /**
     * Build stats per normalized section title.
     *
     * @return array<string, array{count: int, sources: array<string>, avg_quality_score: float, avg_content_length: float}>
     */
    protected static function computeTitleStats(array $sections): array
    {
        $byTitle = [];
        foreach ($sections as $s) {
            $title = strtoupper(trim(preg_replace('/\s+/', ' ', $s['title'] ?? '')));
            if ($title === '') {
                continue;
            }
            if (! isset($byTitle[$title])) {
                $byTitle[$title] = [
                    'count' => 0,
                    'sources' => [],
                    'quality_scores' => [],
                    'content_lengths' => [],
                ];
            }
            $byTitle[$title]['count']++;
            $src = $s['source'] ?? 'heuristic';
            if (! in_array($src, $byTitle[$title]['sources'], true)) {
                $byTitle[$title]['sources'][] = $src;
            }
            $byTitle[$title]['quality_scores'][] = (float) ($s['quality_score'] ?? 0.5);
            $byTitle[$title]['content_lengths'][] = (int) ($s['content_length'] ?? 0);
        }

        $stats = [];
        foreach ($byTitle as $title => $data) {
            $stats[$title] = [
                'count' => $data['count'],
                'sources' => array_values(array_unique($data['sources'])),
                'avg_quality_score' => count($data['quality_scores']) > 0
                    ? round(array_sum($data['quality_scores']) / count($data['quality_scores']), 2)
                    : 0.5,
                'avg_content_length' => count($data['content_lengths']) > 0
                    ? (int) round(array_sum($data['content_lengths']) / count($data['content_lengths']))
                    : 0,
            ];
        }

        return $stats;
    }

    /**
     * Suppress repeated weak heuristic sections and optionally collapse them into synthetic debug entries.
     *
     * @return array{0: array, 1: array<int, array{title: string, count: int, reason: string}>, 2: array<int, array>}
     */
    protected static function suppressAndCollapseRepeatedWeakSections(array $sections, array $titleStats): array
    {
        $usable = [];
        $suppressed = [];
        $collapsedByTitle = [];

        foreach ($sections as $s) {
            if (self::shouldSuppressRepeatedWeakSection($s, $titleStats)) {
                $title = strtoupper(trim(preg_replace('/\s+/', ' ', $s['title'] ?? '')));
                if (! isset($collapsedByTitle[$title])) {
                    $collapsedByTitle[$title] = [
                        'title' => $s['title'] ?? $title,
                        'source' => 'collapsed_repeated_heading',
                        'content' => null,
                        'occurrences' => $titleStats[$title]['count'] ?? 1,
                        'quality_score' => $titleStats[$title]['avg_quality_score'] ?? 0.15,
                        'used_for_extraction' => false,
                    ];
                }
                continue;
            }
            $usable[] = $s;
        }

        foreach ($collapsedByTitle as $title => $collapsedEntry) {
            $suppressed[] = [
                'title' => $title,
                'count' => $collapsedEntry['occurrences'],
                'reason' => 'repeated_weak_heuristic_heading',
            ];
        }

        $collapsed = array_values($collapsedByTitle);

        return [$usable, $suppressed, $collapsed];
    }

    protected static function shouldSuppressRepeatedWeakSection(array $section, array $titleStats): bool
    {
        $title = strtoupper(trim(preg_replace('/\s+/', ' ', $section['title'] ?? '')));
        if ($title === '') {
            return false;
        }

        foreach (self::PROTECTED_SECTIONS as $protected) {
            if ($title === $protected || str_starts_with($title, $protected . ' ') || str_ends_with($title, ' ' . $protected)) {
                return false;
            }
        }

        $stat = $titleStats[$title] ?? null;
        if ($stat === null || $stat['count'] < self::REPEATED_WEAK_THRESHOLD) {
            return false;
        }

        if (($section['source'] ?? 'heuristic') !== 'heuristic') {
            return false;
        }

        $qualityScore = (float) ($section['quality_score'] ?? 0.5);
        if ($qualityScore >= self::REPEATED_WEAK_QUALITY_MAX) {
            return false;
        }

        $contentLength = (int) ($section['content_length'] ?? 0);
        if ($contentLength >= self::REPEATED_WEAK_CONTENT_LENGTH_MAX) {
            return false;
        }

        return true;
    }

    /**
     * Get content for a section by title (case-insensitive, partial match).
     */
    public static function getSectionContent(array $parseResult, string $sectionTitle): ?string
    {
        $needle = strtoupper($sectionTitle);
        foreach ($parseResult['sections'] ?? [] as $s) {
            $title = strtoupper($s['title'] ?? '');
            if ($title === $needle || str_contains($title, $needle) || str_contains($needle, $title)) {
                $content = trim($s['content'] ?? '');
                return $content !== '' ? $content : null;
            }
        }

        return null;
    }

    /**
     * Build structured text for AI: "Section: X\nContent: ..."
     */
    public static function toStructuredPrompt(array $parseResult): string
    {
        $parts = [];
        foreach ($parseResult['sections'] ?? [] as $s) {
            $content = trim($s['content'] ?? '');
            if ($content !== '') {
                $page = $s['page'] ?? null;
                $header = 'Section: ' . ($s['title'] ?? 'Unknown');
                if ($page !== null) {
                    $header .= " (page {$page})";
                }
                $parts[] = $header . "\n" . $content;
            }
        }

        return implode("\n\n", $parts) ?: $parseResult['full_text'] ?? '';
    }
}
