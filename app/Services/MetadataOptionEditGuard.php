<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Metadata Option Edit Guard
 *
 * Product integrity rule: System fields that use custom rendering do NOT support manual option editing.
 * These fields are not customizable option fields.
 *
 * Used by: MetadataFieldModal (frontend), addValue endpoint, TenantMetadataFieldService.
 */
class MetadataOptionEditGuard
{
    /**
     * Check if a field is restricted from option editing.
     *
     * Restricted when ALL of:
     * - Field is system-scoped (scope=system or tenant_id null)
     * - AND any of: key in restricted list, type=rating, or has custom display_widget
     *
     * @param object|array $field Must have: key, scope or tenant_id, type, display_widget (optional)
     * @return bool True if option editing is not allowed
     */
    public static function isRestricted(object|array $field): bool
    {
        $get = fn ($k) => is_array($field) ? ($field[$k] ?? null) : ($field->{$k} ?? null);
        $key = $get('key');
        $scope = $get('scope');
        $tenantId = $get('tenant_id');
        $type = $get('type');
        $displayWidget = $get('display_widget');

        // Must be system field
        $isSystem = ($scope === 'system') || ($tenantId === null && $scope !== 'tenant');
        if (!$isSystem) {
            return false;
        }

        // Key in hardcoded restricted list
        $restrictedKeys = Config::get('metadata_category_defaults.restricted_option_edit_keys', []);
        if ($key && in_array($key, $restrictedKeys, true)) {
            return true;
        }

        // Type rating
        if ($type === 'rating') {
            return true;
        }

        // Custom display_widget (non-null, non-select = custom rendering)
        if ($displayWidget !== null && $displayWidget !== '' && $displayWidget !== 'select') {
            return true;
        }

        return false;
    }
}
