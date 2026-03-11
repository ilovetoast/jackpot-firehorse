<?php

namespace App\Services\BrandDNA;

use App\Models\BrandModelVersion;
use App\Services\BrandDNA\Extraction\ExtractionQualityValidator;
use App\Services\BrandDNA\Extraction\ExtractionSuggestionService;
use App\Services\BrandDNA\Extraction\SectionAwareBrandGuidelinesProcessor;

/**
 * Auto-applies high-confidence suggestions to draft model_payload.
 * Only fills empty fields; never overwrites user-entered values.
 * Stores AI metadata (value, source, confidence) for UI display.
 * Does not auto-apply low-quality values or suggestions from weak sections.
 */
class AutoApplyHighConfidenceSuggestions
{
    public const CONFIDENCE_THRESHOLD = 0.85;

    /**
     * @return array{0: BrandModelVersion, 1: array<int, array{path: string, reason: string}>}
     */
    public static function apply(BrandModelVersion $draft, array $suggestions): array
    {
        $payload = $draft->model_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        $patches = [];
        $blockedReasons = [];
        foreach ($suggestions as $s) {
            if (! ($s['auto_apply'] ?? false)) {
                continue;
            }
            $path = $s['path'] ?? null;
            $value = $s['value'] ?? null;
            if (! $path || $value === null) {
                continue;
            }

            $blocked = self::getAutoApplyBlockedReason($s);
            if ($blocked !== null) {
                $blockedReasons[] = ['path' => $path, 'reason' => $blocked];
                continue;
            }

            $current = self::getAtPath($payload, $path);
            if (self::isEmptyOrAiOverridable($current)) {
                $aiValue = [
                    'value' => $value,
                    'source' => 'ai',
                    'confidence' => (float) ($s['confidence'] ?? 0),
                    'sources' => $s['source'] ?? [],
                ];
                $patches[$path] = $aiValue;
            }
        }

        if (empty($patches)) {
            return [$draft, $blockedReasons];
        }

        $merged = $payload;
        foreach ($patches as $path => $aiValue) {
            $merged = self::setAtPath($merged, $path, $aiValue);
        }

        $draft->update(['model_payload' => $merged]);

        return [$draft->fresh(), $blockedReasons];
    }

    protected static function getAutoApplyBlockedReason(array $s): ?string
    {
        $confidence = (float) ($s['confidence'] ?? 0);
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return 'confidence_below_threshold';
        }

        $value = $s['value'] ?? null;
        if (is_string($value) && ExtractionQualityValidator::isLowQualityExtractedValue($value)) {
            return 'low_quality_value';
        }

        $sectionTitle = $s['section'] ?? null;
        if ($sectionTitle !== null && ! SectionAwareBrandGuidelinesProcessor::isTrustedSection($sectionTitle)) {
            return 'section_not_trusted';
        }

        $sectionSource = $s['section_source'] ?? 'heuristic';
        $sectionConfidence = (float) ($s['section_confidence'] ?? 0);
        if ($sectionSource === 'heuristic' && $sectionConfidence < 0.8) {
            return 'heuristic_section_weak';
        }

        $sectionQuality = (float) ($s['section_quality_score'] ?? 1.0);
        if ($sectionTitle !== null && $sectionQuality < ExtractionSuggestionService::MIN_SECTION_QUALITY_AUTO_APPLY) {
            return 'section_quality_too_low';
        }

        return null;
    }

    protected static function getAtPath(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        $current = $data;
        foreach ($parts as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    protected static function setAtPath(array $data, string $path, mixed $value): array
    {
        $parts = explode('.', $path);
        $root = &$data;
        foreach (array_slice($parts, 0, -1) as $key) {
            if (! isset($root[$key]) || ! is_array($root[$key])) {
                $root[$key] = [];
            }
            $root = &$root[$key];
        }
        $root[$parts[count($parts) - 1]] = $value;

        return $data;
    }

    /**
     * Field is empty or has AI value (can be overwritten by new AI).
     * User values must never be overwritten:
     * - Plain scalars (string, number) = user-entered (form sends plain values)
     * - Array with source=user = explicitly user
     * - Array with source=ai = AI-filled, can overwrite
     */
    protected static function isEmptyOrAiOverridable(mixed $current): bool
    {
        if ($current === null || $current === '' || $current === []) {
            return true;
        }
        if (is_array($current) && isset($current['source'])) {
            return $current['source'] !== 'user';
        }
        // Plain scalar (string, number, bool) = user-entered; do not overwrite
        if (! is_array($current)) {
            return false;
        }

        return true;
    }

    /**
     * Extract display value from field (handles both plain and AI-wrapped format).
     */
    public static function unwrapValue(mixed $field): mixed
    {
        if (is_array($field) && isset($field['value'])) {
            return $field['value'];
        }

        return $field;
    }

    /**
     * Check if field was populated by AI.
     */
    public static function isAiPopulated(mixed $field): bool
    {
        return is_array($field) && ($field['source'] ?? null) === 'ai';
    }
}
