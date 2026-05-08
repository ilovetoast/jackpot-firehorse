<?php

namespace App\Support;

/**
 * Rules for which metadata field types may appear as sidebar / primary filters.
 *
 * @see Phase 5 Folders & Fields UX — filter eligibility (no schema changes).
 */
final class MetadataFieldFilterEligibility
{
    public static function normalizeType(?string $type): string
    {
        $t = strtolower((string) $type);

        return $t === 'multi_select' ? 'multiselect' : $t;
    }

    public static function canUseSidebarFilter(?string $type): bool
    {
        return in_array(self::normalizeType($type), ['select', 'multiselect', 'boolean', 'date'], true);
    }

    public static function canUsePrimaryFilter(?string $type): bool
    {
        return self::canUseSidebarFilter($type);
    }

    /**
     * Global metadata_fields.show_in_filters — ineligible types are always false.
     *
     * @param  mixed  $requested  Raw request value (bool or string)
     */
    public static function sanitizeGlobalShowInFilters(?string $type, $requested): bool
    {
        if (! self::canUseSidebarFilter($type)) {
            return false;
        }

        $parsed = filter_var($requested, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed === null ? true : (bool) $parsed;
    }

    /**
     * Category (or request) level: normalize show_in_filters and is_primary together.
     *
     * @return array{0: ?bool, 1: ?bool} [show_in_filters, is_primary]; null = omit / do not change column
     */
    public static function normalizeFilterAndPrimaryForSave(?string $type, ?bool $showInFilters, ?bool $isPrimary): array
    {
        if ($showInFilters === null && $isPrimary === null) {
            return [null, null];
        }

        if (! self::canUseSidebarFilter($type)) {
            return [false, false];
        }

        if ($showInFilters === false) {
            $isPrimary = false;
        }
        if ($isPrimary === true) {
            $showInFilters = true;
        }

        return [$showInFilters, $isPrimary];
    }
}
