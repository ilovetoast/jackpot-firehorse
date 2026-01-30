<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Metadata Visibility Service
 *
 * Phase C4 + Phase G: Manages tenant-level visibility overrides for metadata fields.
 *
 * ⚠️ PHASE LOCK: Phase G complete. This service is production-locked. Do not refactor.
 *
 * This service handles tenant-scoped visibility overrides that allow tenants
 * to control where and how metadata fields appear in their UI.
 *
 * Rules:
 * - Tenant overrides never modify system field definitions
 * - Overrides are additive and reversible
 * - Category suppression is tenant-scoped
 * - All changes are auditable
 */
class TenantMetadataVisibilityService
{
    /**
     * Get visibility overrides for fields at tenant level.
     *
     * @param Tenant $tenant
     * @param array $fieldIds
     * @return array Keyed by field_id
     */
    public function getFieldVisibilityOverrides(Tenant $tenant, array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        // C9.2: Explicitly select columns to avoid errors if is_edit_hidden doesn't exist yet
        $selectColumns = ['id', 'metadata_field_id', 'tenant_id', 'brand_id', 'category_id', 
                         'is_hidden', 'is_upload_hidden', 'is_filter_hidden', 'is_primary', 
                         'created_at', 'updated_at'];
        if (Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
            $selectColumns[] = 'is_edit_hidden';
        }

        $overrides = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->whereIn('metadata_field_id', $fieldIds)
            ->select($selectColumns)
            ->get()
            ->keyBy('metadata_field_id');

        $result = [];
        foreach ($fieldIds as $fieldId) {
            if (isset($overrides[$fieldId])) {
                $result[$fieldId] = $overrides[$fieldId];
            }
        }

        return $result;
    }

    /**
     * Set visibility override for a field at tenant level.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @param array $visibility Visibility flags:
     *   - show_on_upload: bool|null (null = no override)
     *   - show_on_edit: bool|null (null = no override)
     *   - show_in_filters: bool|null (null = no override)
     * @return void
     */
    public function setFieldVisibility(Tenant $tenant, int $fieldId, array $visibility): void
    {
        // Convert show_* flags to is_*_hidden flags
        // C9.2: is_hidden is ONLY for category suppression, NOT for edit visibility
        // Use is_edit_hidden for edit visibility (Quick View checkbox)
        $isUploadHidden = isset($visibility['show_on_upload']) && !$visibility['show_on_upload'];
        $isEditHidden = isset($visibility['show_on_edit']) && !$visibility['show_on_edit'];
        $isFilterHidden = isset($visibility['show_in_filters']) && !$visibility['show_in_filters'];

        // Check if override already exists
        $existing = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->first();

        if ($existing) {
            // Update existing override
            DB::table('metadata_field_visibility')
                ->where('id', $existing->id)
                ->update([
                    'is_upload_hidden' => $isUploadHidden,
                    'is_edit_hidden' => $isEditHidden, // C9.2: Use is_edit_hidden, not is_hidden
                    'is_filter_hidden' => $isFilterHidden,
                    'updated_at' => now(),
                ]);
        } else {
            // Create new override
            DB::table('metadata_field_visibility')->insert([
                'metadata_field_id' => $fieldId,
                'tenant_id' => $tenant->id,
                'brand_id' => null,
                'category_id' => null,
                'is_upload_hidden' => $isUploadHidden,
                'is_edit_hidden' => $isEditHidden, // C9.2: Use is_edit_hidden, not is_hidden
                'is_filter_hidden' => $isFilterHidden,
                'is_hidden' => false, // C9.2: is_hidden is only for category suppression, default to false
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::info('Tenant metadata visibility override set', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
            'visibility' => $visibility,
        ]);
    }

    /**
     * Remove visibility override for a field at tenant level.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @return void
     */
    public function removeFieldVisibility(Tenant $tenant, int $fieldId): void
    {
        DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->delete();

        Log::info('Tenant metadata visibility override removed', [
            'tenant_id' => $tenant->id,
            'field_id' => $fieldId,
        ]);
    }

    /**
     * Suppress a field for a category at tenant level.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @param Category $category
     * @return void
     */
    public function suppressForCategory(Tenant $tenant, int $fieldId, Category $category): void
    {
        // Categories are brand-specific, so create brand-specific suppression records
        $brandId = $category->brand_id;
        
        // Check if suppression already exists (brand-specific or tenant-level)
        $existingQuery = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('category_id', $category->id);
        
        if ($brandId) {
            $existing = $existingQuery->where('brand_id', $brandId)->first();
        } else {
            $existing = $existingQuery->whereNull('brand_id')->first();
        }

        if ($existing) {
            // Update to ensure hidden
            DB::table('metadata_field_visibility')
                ->where('id', $existing->id)
                ->update([
                    'is_hidden' => true,
                    'is_upload_hidden' => true,
                    'is_filter_hidden' => true,
                    'updated_at' => now(),
                ]);
        } else {
            // Create new suppression
            DB::table('metadata_field_visibility')->insert([
                'metadata_field_id' => $fieldId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brandId, // Use category's brand_id
                'category_id' => $category->id,
                'is_hidden' => true,
                'is_upload_hidden' => true,
                'is_filter_hidden' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::info('Tenant metadata field suppressed for category', [
            'tenant_id' => $tenant->id,
            'brand_id' => $brandId,
            'field_id' => $fieldId,
            'category_id' => $category->id,
        ]);
    }

    /**
     * Unsuppress a field for a category at tenant level.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @param Category $category
     * @return void
     */
    public function unsuppressForCategory(Tenant $tenant, int $fieldId, Category $category): void
    {
        // Categories are brand-specific, so delete brand-specific suppression records
        $brandId = $category->brand_id;
        
        $query = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('category_id', $category->id);
        
        if ($brandId) {
            $query->where('brand_id', $brandId);
        } else {
            $query->whereNull('brand_id');
        }
        
        $query->delete();

        Log::info('Tenant metadata field unsuppressed for category', [
            'tenant_id' => $tenant->id,
            'brand_id' => $brandId,
            'field_id' => $fieldId,
            'category_id' => $category->id,
        ]);
    }

    /**
     * Get category suppressions for a field at tenant level.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @return array Array of category IDs where field is suppressed
     */
    public function getSuppressedCategories(Tenant $tenant, int $fieldId, ?int $brandId = null): array
    {
        $query = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('category_id')
            ->where('is_hidden', true);
        
        // Check brand-specific records if brand is provided, otherwise check tenant-level
        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        } else {
            $query->whereNull('brand_id');
        }
        
        return $query->pluck('category_id')->toArray();
    }

    /**
     * Check if a field is visible for a category at tenant level.
     *
     * @param Tenant $tenant
     * @param int $fieldId
     * @param Category|null $category
     * @return bool
     */
    public function isVisibleForCategory(Tenant $tenant, int $fieldId, ?Category $category): bool
    {
        if ($category === null) {
            // Check tenant-level override (no category)
            $override = DB::table('metadata_field_visibility')
                ->where('metadata_field_id', $fieldId)
                ->where('tenant_id', $tenant->id)
                ->whereNull('brand_id')
                ->whereNull('category_id')
                ->first();

            // If no override, field is visible
            if (!$override) {
                return true;
            }

            // Check if hidden at tenant level
            return !$override->is_hidden;
        }

        // Check category-specific suppression
        // Categories are brand-specific, so check brand-specific records first, then tenant-level
        $suppressionQuery = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->where('category_id', $category->id)
            ->where('is_hidden', true);
        
        // If category has a brand, check brand-specific records first
        if ($category->brand_id) {
            $suppression = $suppressionQuery
                ->where('brand_id', $category->brand_id)
                ->exists();
            
            if ($suppression) {
                return false;
            }
        }
        
        // Also check tenant-level suppression (brand_id is NULL)
        $suppression = $suppressionQuery
            ->whereNull('brand_id')
            ->exists();

        // If suppressed for this category, not visible
        if ($suppression) {
            return false;
        }

        // Check tenant-level override
        $override = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->first();

        // If no override, field is visible
        if (!$override) {
            return true;
        }

        // Check if hidden at tenant level
        return !$override->is_hidden;
    }

    /**
     * Get category-specific overrides for a field (including is_primary).
     * 
     * Returns category-level visibility overrides including primary filter placement.
     * 
     * ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
     * A field may be primary in Photography but secondary in Logos.
     * 
     * @param Tenant $tenant
     * @param int $fieldId
     * @param int|null $brandId
     * @return array Keyed by category_id, containing override data including is_primary
     */
    public function getCategoryOverrides(Tenant $tenant, int $fieldId, ?int $brandId = null): array
    {
        $query = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $fieldId)
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('category_id');
        
        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }
        
        $selectColumns = ['category_id', 'is_hidden', 'is_upload_hidden', 'is_filter_hidden', 'is_primary'];
        if (Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
            $selectColumns[] = 'is_edit_hidden';
        }
        $overrides = $query->select($selectColumns)->get();

        $result = [];
        foreach ($overrides as $override) {
            $row = [
                'is_hidden' => (bool) $override->is_hidden,
                'is_upload_hidden' => (bool) $override->is_upload_hidden,
                'is_filter_hidden' => (bool) $override->is_filter_hidden,
                'is_primary' => isset($override->is_primary) ? ($override->is_primary === 1 || $override->is_primary === true) : null,
                'show_on_upload' => ! (bool) $override->is_upload_hidden,
                'show_on_edit' => isset($override->is_edit_hidden) ? ! (bool) $override->is_edit_hidden : true,
                'show_in_filters' => ! (bool) $override->is_filter_hidden,
            ];
            if (isset($override->is_edit_hidden)) {
                $row['is_edit_hidden'] = (bool) $override->is_edit_hidden;
            }
            $result[$override->category_id] = $row;
        }

        return $result;
    }
}
