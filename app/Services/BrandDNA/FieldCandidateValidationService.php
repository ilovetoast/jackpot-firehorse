<?php

namespace App\Services\BrandDNA;

/**
 * Validates and normalizes extracted field candidates before fusion.
 * Prefer missing over wrong.
 */
class FieldCandidateValidationService
{

    protected const LABEL_PATTERNS = [
        '/^OF VOICE$/i',
        '/^BRAND EXAMPLES$/i',
        '/^TYPOGRAPHY$/i',
        '/^COLOR PALETTE$/i',
        '/^BODY COPY$/i',
        '/^HEADLINE$/i',
        '/^TONE$/i',
        '/^VOICE$/i',
        '/^BRAND VOICE$/i',
        '/^FONTS?$/i',
        '/^PRIMARY COLOUR$/i',
        '/^SECONDARY COLOUR$/i',
        '/^\d+\s+PHOTOGRAPHY/i',
        '/PHOTOGRAPHY\s+premier/i',
    ];

    protected const FRAGMENTARY_ENDINGS = [
        'within a category',
        'of voice',
        'in the market',
    ];

    protected const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been',
        'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need',
    ];

    protected const MAX_FONT_CHARS = 60;

    protected const MAX_POSITIONING_CHARS = 500;

    protected const MIN_POSITIONING_TOKENS = 3;

    /**
     * Validate a single extraction candidate.
     *
     * @param array{path: string, value: mixed, confidence: float, evidence?: string, page?: int, page_type?: string} $candidate
     * @return array{accepted: bool, reason?: string, normalized_value?: mixed}
     */
    public function validate(array $candidate): array
    {
        $path = $candidate['path'] ?? '';
        $value = $candidate['value'] ?? null;

        if ($path === '' || $value === null) {
            return ['accepted' => false, 'reason' => 'empty_candidate', 'normalized_value' => null];
        }

        $method = $this->validatorForPath($path);
        if ($method) {
            return $this->{$method}($value, $candidate);
        }

        return ['accepted' => true, 'normalized_value' => $this->genericNormalize($value)];
    }

    /**
     * Sanitize merged extraction: clear fields that fail validation.
     * Only validated values may influence snapshot, suggestions, auto-apply.
     */
    public function sanitizeMergedExtraction(array $extraction): array
    {
        $pathsToCheck = [
            ['identity', 'mission'],
            ['identity', 'positioning'],
            ['identity', 'vision'],
            ['identity', 'industry'],
            ['identity', 'tagline'],
            ['personality', 'primary_archetype'],
            ['visual', 'logo_description'],
        ];

        foreach ($pathsToCheck as [$section, $key]) {
            $value = $extraction[$section][$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $path = "{$section}.{$key}";
            $result = $this->validate(['path' => $path, 'value' => $value, 'confidence' => 0.5]);
            if (! $result['accepted']) {
                $extraction[$section][$key] = null;
            } elseif (isset($result['normalized_value'])) {
                $extraction[$section][$key] = $result['normalized_value'];
            }
        }

        if (isset($extraction['visual']['fonts']) && is_array($extraction['visual']['fonts'])) {
            $result = $this->validate(['path' => 'visual.fonts', 'value' => $extraction['visual']['fonts'], 'confidence' => 0.5]);
            if (! $result['accepted']) {
                $extraction['visual']['fonts'] = [];
            } elseif (isset($result['normalized_value'])) {
                $extraction['visual']['fonts'] = $result['normalized_value'];
            }
        }

        if (isset($extraction['visual']['primary_colors']) && is_array($extraction['visual']['primary_colors'])) {
            $result = $this->validate(['path' => 'visual.primary_colors', 'value' => $extraction['visual']['primary_colors'], 'confidence' => 0.5]);
            if (! $result['accepted']) {
                $extraction['visual']['primary_colors'] = [];
            } elseif (isset($result['normalized_value'])) {
                $extraction['visual']['primary_colors'] = $result['normalized_value'];
            }
        }

        if (isset($extraction['personality']['tone_keywords']) && is_array($extraction['personality']['tone_keywords'])) {
            $result = $this->validate(['path' => 'personality.tone_keywords', 'value' => $extraction['personality']['tone_keywords'], 'confidence' => 0.5]);
            if (! $result['accepted']) {
                $extraction['personality']['tone_keywords'] = [];
            } elseif (isset($result['normalized_value'])) {
                $extraction['personality']['tone_keywords'] = $result['normalized_value'];
            }
        }

        return $extraction;
    }

    /**
     * Validate and filter a list of candidates. Returns [accepted, rejected].
     *
     * @param array<int, array> $candidates
     * @return array{0: array, 1: array}
     */
    public function validateMany(array $candidates): array
    {
        $accepted = [];
        $rejected = [];

        foreach ($candidates as $c) {
            $result = $this->validate($c);
            if ($result['accepted']) {
                $c['value'] = $result['normalized_value'] ?? $c['value'];
                $accepted[] = $c;
            } else {
                $rejected[] = array_merge($c, [
                    'reason' => $result['reason'] ?? 'rejected',
                ]);
            }
        }

        return [$accepted, $rejected];
    }

    protected function validatorForPath(string $path): ?string
    {
        return match (true) {
            str_contains($path, 'primary_font') || str_contains($path, 'secondary_font') => 'validateFont',
            str_contains($path, 'tone_keywords') || str_contains($path, 'personality.traits') || str_contains($path, 'scoring_rules.tone_keywords') => 'validateToneKeywords',
            str_contains($path, 'identity.positioning') || str_contains($path, 'identity.mission') => 'validateNarrative',
            str_contains($path, 'primary_archetype') => 'validateArchetype',
            str_contains($path, 'allowed_color_palette') || str_contains($path, 'primary_colors') || str_contains($path, 'secondary_colors') => 'validateColors',
            str_contains($path, 'visual.fonts') => 'validateFonts',
            default => null,
        };
    }

    protected function validateFont(mixed $value, array $candidate): array
    {
        $str = is_string($value) ? $value : (string) $value;
        $str = $this->collapseWhitespace($str);

        if ($this->isLikelyLabelText($str)) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidate', 'normalized_value' => null];
        }
        if ($this->isConjunctionHeavyOrDescriptive($str)) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidate', 'normalized_value' => null];
        }
        if ($this->isTypographyJunk($str)) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidate', 'normalized_value' => null];
        }
        if (! $this->isLikelyFontName($str)) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidate', 'normalized_value' => null];
        }
        if ($this->containsTooManyStopwords($str)) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidate', 'normalized_value' => null];
        }
        if (strlen($str) > self::MAX_FONT_CHARS) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidate', 'normalized_value' => null];
        }

        $normalized = $this->normalizeFontName($str);
        return ['accepted' => true, 'normalized_value' => $normalized];
    }

    protected function validateToneKeywords(mixed $value, array $candidate): array
    {
        $items = is_array($value) ? $value : [$value];
        $items = array_map(fn ($v) => is_string($v) ? trim($v) : (string) $v, $items);
        $items = array_filter($items, fn ($v) => $v !== '');

        $accepted = [];
        foreach ($items as $item) {
            if ($this->isLikelyLabelText($item)) {
                continue;
            }
            $words = preg_split('/\s+/', $item, -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) > 3) {
                continue;
            }
            if (strlen($item) > 50) {
                continue;
            }
            $norm = $this->normalizeToneKeyword($item);
            if ($norm !== '') {
                $accepted[] = $norm;
            }
        }

        if (empty($accepted)) {
            return ['accepted' => false, 'reason' => 'label_fragment_not_keywords', 'normalized_value' => null];
        }

        $accepted = array_values(array_unique($accepted));
        return ['accepted' => true, 'normalized_value' => $accepted];
    }

    protected function validateNarrative(mixed $value, array $candidate): array
    {
        $str = is_string($value) ? $value : (string) $value;
        $str = $this->collapseWhitespace($str);

        if ($this->isFragmentaryNarrative($str)) {
            return ['accepted' => false, 'reason' => 'fragmentary_narrative', 'normalized_value' => null];
        }
        if (! $this->isSentenceQualityText($str)) {
            return ['accepted' => false, 'reason' => 'low_quality_narrative', 'normalized_value' => null];
        }
        if ($this->isLikelyLabelText($str)) {
            return ['accepted' => false, 'reason' => 'label_not_narrative', 'normalized_value' => null];
        }

        $normalized = $this->collapseWhitespace($str);
        return ['accepted' => true, 'normalized_value' => $normalized];
    }

    protected function validateArchetype(mixed $value, array $candidate): array
    {
        $str = is_string($value) ? trim($value) : '';
        if ($str === '') {
            return ['accepted' => false, 'reason' => 'empty_archetype', 'normalized_value' => null];
        }

        $allowlist = config('brand_dna_archetypes.allowlist', []);
        $displayMap = config('brand_dna_archetypes.display_map', []);
        if (empty($allowlist)) {
            $allowlist = ['hero', 'sage', 'explorer', 'ruler', 'creator', 'caregiver', 'everyman', 'magician', 'lover', 'jester', 'innocent', 'outlaw'];
            $displayMap = array_combine($allowlist, array_map('ucfirst', $allowlist));
        }

        $lower = strtolower($str);
        foreach ($allowlist as $allowed) {
            if ($lower === $allowed || $lower === str_replace(' ', '', $allowed)) {
                $display = $displayMap[$allowed] ?? ucfirst($allowed);
                return ['accepted' => true, 'normalized_value' => $display];
            }
        }

        return ['accepted' => false, 'reason' => 'archetype_not_in_list', 'normalized_value' => null];
    }

    protected function validateColors(mixed $value, array $candidate): array
    {
        $items = is_array($value) ? $value : [$value];
        $valid = [];
        foreach ($items as $item) {
            $hex = $this->extractHex($item);
            if ($hex) {
                $valid[] = $hex;
            }
        }
        $valid = array_values(array_unique($valid));

        if (empty($valid)) {
            return ['accepted' => false, 'reason' => 'no_valid_hex_colors', 'normalized_value' => null];
        }

        return ['accepted' => true, 'normalized_value' => $valid];
    }

    protected function validateFonts(mixed $value, array $candidate): array
    {
        $items = is_array($value) ? $value : [$value];
        $accepted = [];
        foreach ($items as $item) {
            $str = is_string($item) ? $item : (string) $item;
            $str = $this->collapseWhitespace($str);
            if ($this->isConjunctionHeavyOrDescriptive($str) || $this->isTypographyJunk($str)) {
                continue;
            }
            if ($this->isLikelyFontName($str) && ! $this->isLikelyLabelText($str) && strlen($str) <= self::MAX_FONT_CHARS) {
                $accepted[] = $this->normalizeFontName($str);
            }
        }
        $accepted = array_values(array_unique($accepted));

        if (empty($accepted)) {
            return ['accepted' => false, 'reason' => 'invalid_font_candidates', 'normalized_value' => null];
        }

        return ['accepted' => true, 'normalized_value' => $accepted];
    }

    protected function genericNormalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->collapseWhitespace($value);
        }
        if (is_array($value)) {
            return array_map(fn ($v) => is_string($v) ? $this->collapseWhitespace($v) : $v, $value);
        }
        return $value;
    }

    public function isLikelyLabelText(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }
        foreach (self::LABEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }
        if (preg_match('/^[A-Z\s]{2,50}$/', $trimmed) && ! preg_match('/[a-z]/', $trimmed)) {
            return true;
        }
        return false;
    }

    public function isSentenceQualityText(string $value): bool
    {
        $str = trim($value);
        if (strlen($str) < 10) {
            return false;
        }
        $tokens = preg_split('/\s+/', $str, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) < self::MIN_POSITIONING_TOKENS) {
            return false;
        }
        if (strlen($str) > self::MAX_POSITIONING_CHARS) {
            return false;
        }
        if (preg_match('/\n{2,}/', $str)) {
            return false;
        }
        return true;
    }

    public function isLikelyFontName(string $value): bool
    {
        $str = trim($value);
        if ($str === '' || strlen($str) > self::MAX_FONT_CHARS) {
            return false;
        }
        if (preg_match('/\n/', $str)) {
            return false;
        }
        $tokens = preg_split('/\s+/', $str, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) > 4) {
            return false;
        }
        if (count($tokens) === 0) {
            return false;
        }
        $alphaRatio = 0;
        $total = 0;
        foreach (preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if (ctype_alpha($c)) {
                $alphaRatio++;
            }
            $total++;
        }
        if ($total > 0 && $alphaRatio / $total < 0.5) {
            return false;
        }
        if (preg_match('/^\d+$/', $str)) {
            return false;
        }
        if (preg_match('/\d{2,}\s+\w+/', $str)) {
            return false;
        }
        if (preg_match('/(premier|fitness|accessory|brand)\s+(premier|fitness|accessory|brand)/i', $str)) {
            return false;
        }
        return ! $this->isLikelyLabelText($str);
    }

    public function isFragmentaryNarrative(string $value): bool
    {
        $lower = strtolower(trim($value));
        foreach (self::FRAGMENTARY_ENDINGS as $ending) {
            if (str_contains($lower, $ending) || str_ends_with($lower, $ending) || $lower === $ending) {
                return true;
            }
        }
        if (preg_match('/^(within|of|in)\s+/i', $lower) && strlen($lower) < 80) {
            return true;
        }
        return false;
    }

    /**
     * Reject conjunction-heavy or descriptive phrases (e.g. "And Prominent Vg Ligature").
     */
    protected function isConjunctionHeavyOrDescriptive(string $value): bool
    {
        $lower = strtolower(trim($value));
        if (preg_match('/^(and|or|but|with|for|the|a|an)\s+/', $lower)) {
            return true;
        }
        $tokens = preg_split('/\s+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) >= 3) {
            $conjunctions = ['and', 'or', 'but', 'with', 'for', 'the', 'a', 'an'];
            $conjCount = 0;
            foreach ($tokens as $t) {
                if (in_array($t, $conjunctions, true)) {
                    $conjCount++;
                }
            }
            if ($conjCount >= 1 && $conjCount / count($tokens) >= 0.25) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reject typography junk: ligature samples, body copy, descriptive fragments.
     */
    protected function isTypographyJunk(string $value): bool
    {
        $lower = strtolower(trim($value));
        if (preg_match('/\bligature\b/i', $lower)) {
            return true;
        }
        if (preg_match('/\b(prominent|prominently|sample|example|heading|body|copy)\b/i', $lower) && strlen($value) > 15) {
            return true;
        }
        if (preg_match('/\d+\s*(pt|px|em)\b/i', $value)) {
            return true;
        }
        if (preg_match('/\n/', $value)) {
            return true;
        }
        $tokens = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) > 4) {
            return true;
        }
        return false;
    }

    public function containsTooManyStopwords(string $value): bool
    {
        $tokens = preg_split('/\s+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) < 2) {
            return false;
        }
        $stopCount = 0;
        foreach ($tokens as $t) {
            if (in_array($t, self::STOPWORDS, true)) {
                $stopCount++;
            }
        }
        return $stopCount / count($tokens) > 0.4;
    }

    protected function collapseWhitespace(string $value): string
    {
        $v = preg_replace('/\s+/', ' ', trim($value));
        return $v !== null ? $v : trim($value);
    }

    protected function normalizeFontName(string $value): string
    {
        $v = $this->collapseWhitespace($value);
        return ucwords(strtolower($v));
    }

    protected function normalizeToneKeyword(string $value): string
    {
        $v = trim($value, " \t\n\r\0\x0B.,;:!?");
        $v = $this->collapseWhitespace($v);
        return strtolower($v);
    }

    protected function extractHex(mixed $item): ?string
    {
        if (is_string($item) && preg_match('/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $item, $m)) {
            $hex = '#' . $m[1];
            if (strlen($m[1]) === 3) {
                $hex = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
            }
            return strtoupper($hex);
        }
        if (is_array($item) && isset($item['hex'])) {
            return $this->extractHex($item['hex']);
        }
        return null;
    }
}
