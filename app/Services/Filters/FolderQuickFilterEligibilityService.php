<?php

namespace App\Services\Filters;

use App\Models\MetadataField;

/**
 * Eligibility for "Folder Quick Filters" — Phase 1 (foundation only).
 *
 * Folder Quick Filters are a future product surface where, once a folder is
 * selected, a small nested list of shortcut filters appears under it. They
 * are NOT a separate filter engine: they reuse the existing metadata filter
 * system. This service answers a single question — given a metadata filter
 * (a row from `metadata_fields`, in product language a "filter"), is it
 * permitted to show as a folder quick filter?
 *
 * This Phase 1 implementation:
 *   - reads from config('categories.folder_quick_filters')
 *   - does NOT change any existing filter behavior
 *   - has NO side effects (no DB writes, no cache mutation, no I/O)
 *   - is safe to call from controllers, services, jobs, or tests
 *
 * Sibling, intentionally separate concept:
 *   {@see \App\Support\MetadataFieldFilterEligibility} answers "may this field
 *   appear as a sidebar / primary filter?". That ruleset is broader (e.g. it
 *   permits `date`). Folder quick filters are a stricter subset by product
 *   design (single_select / multi_select / boolean only).
 */
class FolderQuickFilterEligibilityService
{
    public const REASON_FEATURE_DISABLED = 'feature_disabled';
    public const REASON_TYPE_NOT_ALLOWED = 'type_not_allowed';
    public const REASON_DISABLED = 'disabled';
    public const REASON_ARCHIVED = 'archived';
    public const REASON_DEPRECATED = 'deprecated';
    public const REASON_INTERNAL = 'internal';
    public const REASON_NOT_FILTERABLE = 'not_filterable';
    public const REASON_NO_TYPE = 'no_type';
    public const REASON_INVALID_INPUT = 'invalid_input';

    /**
     * Canonical type aliases.
     *
     * Config accepts the underscored "single_select" / "multi_select" spellings
     * the Phase 1 spec was written against. The DB column `metadata_fields.type`
     * uses the unsuffixed forms (`select`, `multiselect`). We normalise both
     * sides into a single canonical key so callers don't have to care.
     */
    private const TYPE_CANONICAL_MAP = [
        'select' => 'single_select',
        'single_select' => 'single_select',
        'multiselect' => 'multi_select',
        'multi_select' => 'multi_select',
        'boolean' => 'boolean',
        'bool' => 'boolean',
    ];

    /**
     * Canonical types that are explicitly disallowed for folder quick filters.
     *
     * Anything not in TYPE_CANONICAL_MAP — `text`, `textarea`, `rich_text`,
     * `date`, `date_range`, `number`, `number_range`, `file`, `url`, `computed`,
     * `rating`, etc. — is rejected before reaching this list. The list exists
     * only so reasonIneligible() can tell the admin which specific kind of
     * filter was rejected when the input was a recognised disallowed type.
     */
    private const KNOWN_DISALLOWED_TYPES = [
        'text', 'textarea', 'rich_text',
        'date', 'date_range',
        'number', 'number_range',
        'file', 'url',
        'rating',
        'computed',
    ];

    public function isEligible(mixed $filter): bool
    {
        return $this->reasonIneligible($filter) === null;
    }

    /**
     * @return string|null Stable machine reason code, or null when eligible.
     *                     Use {@see explainReason()} for an admin-facing string.
     */
    public function reasonIneligible(mixed $filter): ?string
    {
        if (! $this->isFeatureEnabled()) {
            return self::REASON_FEATURE_DISABLED;
        }

        $row = $this->normalizeInput($filter);
        if ($row === null) {
            return self::REASON_INVALID_INPUT;
        }

        if (($row['is_active'] ?? true) === false) {
            return self::REASON_DISABLED;
        }

        if (! empty($row['archived_at'])) {
            return self::REASON_ARCHIVED;
        }

        if (! empty($row['deprecated_at'])) {
            return self::REASON_DEPRECATED;
        }

        if (! empty($row['is_internal_only'])) {
            return self::REASON_INTERNAL;
        }

        $rawType = $row['type'] ?? null;
        if ($rawType === null || $rawType === '') {
            return self::REASON_NO_TYPE;
        }

        if (! $this->isAllowedType($rawType)) {
            return self::REASON_TYPE_NOT_ALLOWED;
        }

        // Filter must be visible/filterable in the metadata system. Either of
        // the two existing flags counts: `is_filterable` (legacy registry flag)
        // or `show_in_filters` (current visibility flag). When both are
        // explicitly false, the filter is opted-out of all filter surfaces and
        // therefore cannot be a quick filter either.
        $isFilterable = (bool) ($row['is_filterable'] ?? true);
        $showInFilters = array_key_exists('show_in_filters', $row)
            ? (bool) $row['show_in_filters']
            : true;
        if (! $isFilterable && ! $showInFilters) {
            return self::REASON_NOT_FILTERABLE;
        }

        return null;
    }

    /**
     * Admin-facing explanation for an ineligibility reason. Phase 1 messages.
     */
    public function explainReason(?string $reason): ?string
    {
        return match ($reason) {
            self::REASON_FEATURE_DISABLED => 'Folder quick filters are turned off in this environment.',
            self::REASON_TYPE_NOT_ALLOWED => 'Only select and boolean filters can be shown as folder quick filters.',
            self::REASON_DISABLED => 'This filter is disabled.',
            self::REASON_ARCHIVED => 'Archived filters cannot be shown as folder quick filters.',
            self::REASON_DEPRECATED => 'Deprecated filters cannot be shown as folder quick filters.',
            self::REASON_INTERNAL => 'System/internal filters cannot be shown as folder quick filters.',
            self::REASON_NOT_FILTERABLE => 'This filter is not visible in the filter sidebar.',
            self::REASON_NO_TYPE => 'This filter has no type and cannot be evaluated.',
            self::REASON_INVALID_INPUT => 'Could not evaluate the supplied filter.',
            null => null,
            default => null,
        };
    }

    public function isFeatureEnabled(): bool
    {
        return (bool) config('categories.folder_quick_filters.enabled', false);
    }

    /**
     * Canonical types currently allowed by config, normalized.
     *
     * @return list<string>
     */
    public function allowedCanonicalTypes(): array
    {
        $configured = (array) config('categories.folder_quick_filters.allowed_types', []);
        $canonical = [];
        foreach ($configured as $type) {
            $key = self::TYPE_CANONICAL_MAP[strtolower((string) $type)] ?? null;
            if ($key !== null) {
                $canonical[$key] = true;
            }
        }

        return array_keys($canonical);
    }

    /**
     * Normalize a user-supplied type label to its canonical form.
     *
     * Returns one of `single_select`, `multi_select`, `boolean`, or null when
     * the type is not eligible for folder quick filters.
     */
    public function canonicalType(?string $rawType): ?string
    {
        if ($rawType === null) {
            return null;
        }

        return self::TYPE_CANONICAL_MAP[strtolower($rawType)] ?? null;
    }

    /**
     * @return bool True when the type maps to a canonical form AND the canonical
     *              form is in config('categories.folder_quick_filters.allowed_types').
     */
    private function isAllowedType(?string $rawType): bool
    {
        $canonical = $this->canonicalType($rawType);
        if ($canonical === null) {
            return false;
        }

        return in_array($canonical, $this->allowedCanonicalTypes(), true);
    }

    /**
     * Coerce supported inputs into a flat associative array of the columns we
     * care about, or null when the input is unusable. Accepts:
     *
     *   - {@see \App\Models\MetadataField} (or subclass)
     *   - any Eloquent model with the same attributes
     *   - associative array
     *   - stdClass / DB row object
     *
     * Returns null for scalars, empty arrays, and other unsupported shapes.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeInput(mixed $filter): ?array
    {
        if ($filter instanceof MetadataField) {
            return $filter->only([
                'type', 'is_active', 'is_filterable', 'is_internal_only',
                'show_in_filters', 'archived_at', 'deprecated_at', 'scope',
            ]);
        }

        if (is_object($filter) && method_exists($filter, 'getAttribute')) {
            return [
                'type' => $filter->getAttribute('type'),
                'is_active' => $filter->getAttribute('is_active'),
                'is_filterable' => $filter->getAttribute('is_filterable'),
                'is_internal_only' => $filter->getAttribute('is_internal_only'),
                'show_in_filters' => $filter->getAttribute('show_in_filters'),
                'archived_at' => $filter->getAttribute('archived_at'),
                'deprecated_at' => $filter->getAttribute('deprecated_at'),
                'scope' => $filter->getAttribute('scope'),
            ];
        }

        if (is_object($filter)) {
            $filter = (array) $filter;
        }

        if (is_array($filter) && $filter !== []) {
            return $filter;
        }

        return null;
    }

    /**
     * For admin tooling: list of recognised but disallowed type strings. Used
     * by tests to enumerate and assert all of them are rejected.
     *
     * @return list<string>
     */
    public static function knownDisallowedTypes(): array
    {
        return self::KNOWN_DISALLOWED_TYPES;
    }
}
