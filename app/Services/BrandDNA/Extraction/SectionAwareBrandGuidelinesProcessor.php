<?php

namespace App\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\BrandGuidelineSectionParser;

/**
 * Section-aware extraction from Brand Guidelines PDFs.
 * Uses BrandGuidelineSectionParser to structure text, then runs targeted extraction per section.
 * Falls back to BrandGuidelinesProcessor for documents without clear sections.
 * Rejects low-quality extracted values.
 */
class SectionAwareBrandGuidelinesProcessor
{
    protected const SECTION_TO_FIELD = [
        'BRAND ARCHETYPE' => 'archetype',
        'ARCHETYPE' => 'archetype',
        'MISSION' => 'mission',
        'PURPOSE' => 'mission',
        'OUR PURPOSE' => 'mission',
        'OUR MISSION' => 'mission',
        'BRAND POSITIONING' => 'positioning',
        'POSITIONING' => 'positioning',
        'PROMISE' => 'positioning',
        'OUR PROMISE' => 'positioning',
        'BELIEFS' => 'beliefs',
        'VALUES' => 'values',
        'BRAND VOICE' => 'tone',
        'VOICE' => 'tone',
        'COLOR PALETTE' => 'colors',
        'COLORS' => 'colors',
        'TYPOGRAPHY' => 'fonts',
        'BRAND STORY' => 'story',
        'BACKGROUND' => 'story',
    ];

    protected const TRUSTED_SECTIONS = [
        'BRAND ARCHETYPE', 'ARCHETYPE', 'PURPOSE', 'PROMISE', 'OUR PURPOSE', 'OUR PROMISE',
        'BRAND POSITIONING', 'POSITIONING', 'BRAND VOICE', 'VOICE',
        'TYPOGRAPHY', 'COLOR PALETTE', 'COLORS',
    ];

    protected const MIN_SECTION_CONTENT_LENGTH = 30;

    public const MIN_QUALITY_SCORE = 0.55;

    protected const ARCHETYPES = [
        'Creator', 'Caregiver', 'Ruler', 'Jester', 'Everyman', 'Lover',
        'Hero', 'Outlaw', 'Magician', 'Innocent', 'Sage', 'Explorer',
    ];

    public function process(string $extractedText): array
    {
        $parseResult = BrandGuidelineSectionParser::parse($extractedText);
        $sections = $parseResult['sections'] ?? [];
        $rejectedValues = [];

        if (empty($sections)) {
            return (new BrandGuidelinesProcessor)->process($extractedText);
        }

        $schema = BrandExtractionSchema::empty();
        $schema['_sections'] = array_map(static fn ($s) => [
            'title' => $s['title'] ?? '',
            'page' => $s['page'] ?? null,
            'source' => $s['source'] ?? 'heuristic',
            'confidence' => $s['confidence'] ?? 0.7,
            'content_length' => $s['content_length'] ?? 0,
            'quality_score' => $s['quality_score'] ?? 0.5,
        ], $sections);

        $candidatesByField = $this->groupSectionsByField($sections);
        $schema['_parse_result'] = [
            'section_count' => $parseResult['section_count'] ?? 0,
            'section_count_raw' => $parseResult['section_count_raw'] ?? $parseResult['section_count'] ?? 0,
            'section_count_suppressed' => $parseResult['section_count_suppressed'] ?? 0,
            'toc_map' => $parseResult['toc_map'] ?? [],
            'suppressed_lines' => $parseResult['suppressed_lines'] ?? [],
            'suppressed_sections' => $parseResult['suppressed_sections'] ?? [],
            'collapsed_sections' => $parseResult['collapsed_sections'] ?? [],
        ];

        $usedSectionTitles = [];

        foreach ($candidatesByField as $field => $candidateSections) {
            $best = $this->pickBestSectionForField($candidateSections);
            if ($best === null) {
                continue;
            }

            $section = $best['section'];
            $content = trim($section['content'] ?? '');
            $qualityScore = (float) ($section['quality_score'] ?? 0.5);

            if (strlen($content) < self::MIN_SECTION_CONTENT_LENGTH || $qualityScore < self::MIN_QUALITY_SCORE) {
                continue;
            }

            $usedSectionTitles[$section['title'] ?? ''] = true;

            if ($field === 'archetype') {
                $result = $this->extractArchetypeFromSection($content);
                if ($result['value'] !== null) {
                    $schema['personality']['primary_archetype'] = $result['value'];
                    $schema['explicit_signals']['archetype_declared'] = $result['explicit'];
                    $schema['_section_sources']['personality.primary_archetype'] = $section['title'];
                    $schema['_section_quality']['personality.primary_archetype'] = $qualityScore;
                }
            } elseif ($field === 'mission') {
                $val = $this->extractMissionFromSection($content);
                if ($val !== null && ! ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                    $schema['identity']['mission'] = $val;
                    $schema['explicit_signals']['mission_declared'] = true;
                    $schema['_section_sources']['identity.mission'] = $section['title'];
                    $schema['_section_quality']['identity.mission'] = $qualityScore;
                } elseif ($val !== null) {
                    $rejectedValues[] = ['path' => 'identity.mission', 'value' => $val, 'reason' => 'low_quality_fragment'];
                }
            } elseif ($field === 'positioning') {
                $val = $this->extractPositioningFromSection($content);
                if ($val !== null && ! ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                    $schema['identity']['positioning'] = $val;
                    $schema['explicit_signals']['positioning_declared'] = true;
                    $schema['_section_sources']['identity.positioning'] = $section['title'];
                    $schema['_section_quality']['identity.positioning'] = $qualityScore;
                } elseif ($val !== null) {
                    $rejectedValues[] = ['path' => 'identity.positioning', 'value' => $val, 'reason' => 'low_quality_fragment'];
                }
            } elseif ($field === 'beliefs') {
                $items = $this->extractListFromSection($content);
                if (! empty($items)) {
                    $schema['identity']['beliefs'] = $items;
                    $schema['_section_sources']['identity.beliefs'] = $section['title'];
                    $schema['_section_quality']['identity.beliefs'] = $qualityScore;
                }
            } elseif ($field === 'values') {
                $items = $this->extractListFromSection($content);
                if (! empty($items)) {
                    $schema['identity']['values'] = $items;
                    $schema['_section_sources']['identity.values'] = $section['title'];
                    $schema['_section_quality']['identity.values'] = $qualityScore;
                }
            } elseif ($field === 'tone') {
                $items = $this->extractToneFromSection($content);
                if (! empty($items)) {
                    $schema['personality']['tone_keywords'] = $items;
                    $schema['_section_sources']['personality.tone_keywords'] = $section['title'];
                    $schema['_section_quality']['personality.tone_keywords'] = $qualityScore;
                }
            } elseif ($field === 'colors') {
                $colors = $this->extractHexColorsFromSection($content);
                if (! empty($colors)) {
                    $schema['visual']['primary_colors'] = array_merge(
                        $schema['visual']['primary_colors'] ?? [],
                        $colors
                    );
                    $schema['_section_sources']['visual.primary_colors'] = $section['title'];
                    $schema['_section_quality']['visual.primary_colors'] = $qualityScore;
                }
            } elseif ($field === 'fonts') {
                $fonts = $this->extractFontsFromSection($content);
                if (! empty($fonts)) {
                    $schema['visual']['fonts'] = array_values(array_unique(array_merge(
                        $schema['visual']['fonts'] ?? [],
                        $fonts
                    )));
                    $schema['_section_sources']['visual.fonts'] = $section['title'];
                    $schema['_section_quality']['visual.fonts'] = $qualityScore;
                }
            }
        }

        $schema['_used_section_titles'] = array_keys($usedSectionTitles);

        $schema['_rejected_values'] = $rejectedValues;

        $fallback = (new BrandGuidelinesProcessor)->process($extractedText);
        $schema = $this->mergeWithFallback($schema, $fallback);
        $schema['sources']['pdf'] = ['extracted' => true, 'section_aware' => true];
        $schema['confidence'] = $this->computeConfidence($schema);

        $schema['sections'] = $schema['_sections'] ?? [];
        $schema['section_sources'] = $schema['_section_sources'] ?? [];
        $schema['toc_map'] = $schema['_parse_result']['toc_map'] ?? [];
        $usedTitles = $schema['_used_section_titles'] ?? [];
        $sectionQuality = $schema['_section_quality'] ?? [];
        $parseResult = $schema['_parse_result'] ?? [];
        $schema['_extraction_debug'] = [
            'suppressed_lines' => $parseResult['suppressed_lines'] ?? [],
            'suppressed_sections' => $parseResult['suppressed_sections'] ?? [],
            'collapsed_sections' => $parseResult['collapsed_sections'] ?? [],
            'section_count_raw' => $parseResult['section_count_raw'] ?? $parseResult['section_count'] ?? 0,
            'section_count_usable' => $parseResult['section_count'] ?? 0,
            'section_count_suppressed' => $parseResult['section_count_suppressed'] ?? 0,
            'rejected_values' => $schema['_rejected_values'] ?? [],
            'section_quality_by_path' => $sectionQuality,
            'section_metadata' => array_map(static function ($s) use ($usedTitles) {
                $title = $s['title'] ?? '';
                return [
                    'title' => $title,
                    'source' => $s['source'] ?? 'heuristic',
                    'confidence' => $s['confidence'] ?? 0.7,
                    'content_length' => $s['content_length'] ?? 0,
                    'quality_score' => $s['quality_score'] ?? 0.5,
                    'used_for_extraction' => in_array($title, $usedTitles, true),
                ];
            }, $schema['_sections'] ?? []),
        ];
        unset($schema['_sections'], $schema['_parse_result'], $schema['_section_sources'], $schema['_section_quality'], $schema['_rejected_values'], $schema['_used_section_titles']);

        return $schema;
    }

    protected function resolveSectionField(string $title): ?string
    {
        foreach (self::SECTION_TO_FIELD as $key => $field) {
            if (str_contains($title, $key) || $title === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Group sections by the field they map to. Multiple sections can map to the same field.
     *
     * @param array<int, array{title: string, content: string, quality_score?: float, content_length?: int}> $sections
     * @return array<string, array<int, array{section: array, content_length: int}>>
     */
    protected function groupSectionsByField(array $sections): array
    {
        $byField = [];
        foreach ($sections as $section) {
            $title = strtoupper(trim($section['title'] ?? ''));
            $content = trim($section['content'] ?? '');
            if (strlen($content) < self::MIN_SECTION_CONTENT_LENGTH) {
                continue;
            }
            $field = $this->resolveSectionField($title);
            if ($field === null) {
                continue;
            }
            if (! isset($byField[$field])) {
                $byField[$field] = [];
            }
            $byField[$field][] = [
                'section' => $section,
                'content_length' => strlen($content),
            ];
        }

        return $byField;
    }

    /**
     * Pick the section with highest quality_score for a field.
     *
     * @param array<int, array{section: array, content_length: int}> $candidates
     * @return array{section: array, content_length: int}|null
     */
    protected function pickBestSectionForField(array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }
        usort($candidates, static function ($a, $b) {
            $qA = (float) ($a['section']['quality_score'] ?? 0.5);
            $qB = (float) ($b['section']['quality_score'] ?? 0.5);
            if (abs($qA - $qB) < 0.001) {
                return ($b['content_length'] ?? 0) <=> ($a['content_length'] ?? 0);
            }

            return $qB <=> $qA;
        });

        return $candidates[0];
    }

    public static function isTrustedSection(string $sectionTitle): bool
    {
        $upper = strtoupper(trim($sectionTitle));
        foreach (self::TRUSTED_SECTIONS as $trusted) {
            if (str_contains($upper, $trusted) || $upper === $trusted) {
                return true;
            }
        }

        return false;
    }

    protected function extractArchetypeFromSection(string $content): array
    {
        foreach (self::ARCHETYPES as $a) {
            if (preg_match('/\b' . preg_quote($a, '/') . '\b/i', $content)) {
                $explicit = (bool) preg_match('/(?:archetype|primary\s+archetype)[:\s]+' . preg_quote($a, '/') . '/i', $content);

                return ['value' => $a, 'explicit' => $explicit];
            }
        }

        return ['value' => null, 'explicit' => false];
    }

    protected function extractMissionFromSection(string $content): ?string
    {
        if (preg_match('/(?:purpose|mission|why)[:\s]+(.+?)(?=\n\n|\Z)/is', $content, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        $first = trim(explode("\n", $content)[0] ?? '');
        if (strlen($first) > 15 && strlen($first) < 500) {
            return $first;
        }

        return null;
    }

    protected function extractPositioningFromSection(string $content): ?string
    {
        if (preg_match('/(?:positioning|promise|what)[:\s]+(.+?)(?=\n\n|\Z)/is', $content, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        $first = trim(explode("\n", $content)[0] ?? '');
        if (strlen($first) > 15 && strlen($first) < 500) {
            return $first;
        }

        return null;
    }

    protected function extractListFromSection(string $content): array
    {
        $items = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B•-");
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/[,;]|\s+-\s+/', $line);
            foreach ($parts as $p) {
                $p = trim($p, " \t\n\r\0\x0B•-");
                if (strlen($p) > 1 && strlen($p) < 120) {
                    $items[] = $p;
                }
            }
        }

        return array_values(array_unique($items));
    }

    protected function extractToneFromSection(string $content): array
    {
        $items = $this->extractListFromSection($content);
        if (! empty($items)) {
            return $items;
        }
        if (preg_match_all('/(?:tone|voice)[:\s]+([^\n]+)/i', $content, $m)) {
            $parts = preg_split('/[,;]/', implode(', ', $m[1]));
            return array_values(array_filter(array_map('trim', $parts)));
        }

        return [];
    }

    protected function extractHexColorsFromSection(string $content): array
    {
        $colors = [];
        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $content, $m)) {
            foreach ($m[0] as $hex) {
                $hex = str_starts_with($hex, '#') ? $hex : '#' . $hex;
                if (! in_array($hex, $colors, true)) {
                    $colors[] = $hex;
                }
            }
        }

        return $colors;
    }

    protected function extractFontsFromSection(string $content): array
    {
        $fonts = [];
        $patterns = [
            '/Primary\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Secondary\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Typography[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/Body\s+Font[:\s]+([A-Za-z0-9\s\-]+)/i',
            '/font-family[:\s]*["\']?([A-Za-z0-9\s\-]+)["\']?/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $content, $m)) {
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

    protected function mergeWithFallback(array $schema, array $fallback): array
    {
        foreach (['identity', 'personality', 'visual'] as $section) {
            foreach ($fallback[$section] ?? [] as $key => $val) {
                if (($schema[$section][$key] ?? null) === null && $val !== null && $val !== '' && $val !== []) {
                    if (in_array($key, ['mission', 'positioning'], true) && is_string($val) && ExtractionQualityValidator::isLowQualityExtractedValue($val)) {
                        continue;
                    }
                    $schema[$section][$key] = $val;
                }
            }
        }
        foreach ($fallback['explicit_signals'] ?? [] as $key => $val) {
            if (! isset($schema['explicit_signals'][$key]) || ! $schema['explicit_signals'][$key]) {
                $schema['explicit_signals'][$key] = $val;
            }
        }

        return $schema;
    }

    protected function computeConfidence(array $schema): float
    {
        $signals = 0;
        $total = 0;
        foreach ($schema['identity'] ?? [] as $v) {
            $total++;
            if ($v !== null && $v !== '' && $v !== []) {
                $signals++;
            }
        }
        foreach ($schema['personality'] ?? [] as $k => $v) {
            $total++;
            if (($k === 'primary_archetype' && $v) || ($k !== 'primary_archetype' && ! empty($v))) {
                $signals++;
            }
        }
        if (! empty($schema['visual']['primary_colors'] ?? []) || ! empty($schema['visual']['fonts'] ?? [])) {
            $signals++;
        }

        return $total > 0 ? min(1.0, $signals / max(5, $total)) : 0.0;
    }
}
