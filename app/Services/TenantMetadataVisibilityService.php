<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ⚠️ ARCHITECTURE RULE:
 * Schema::hasColumn() and other runtime schema introspection
 * are forbidden in request lifecycle code.
 *
 * Schema is controlled by migrations and must be assumed valid.
 *
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

        $overrides = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->whereIn('metadata_field_id', $fieldIds)
            ->select(['id', 'metadata_field_id', 'tenant_id', 'brand_id', 'category_id',
                'is_hidden', 'is_upload_hidden', 'is_filter_hidden', 'is_primary', 'is_edit_hidden',
                'created_at', 'updated_at'])
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
     * Batch-check visibility for multiple fields. Avoids N+1 when filtering many fields.
     *
     * ⚠️ Use this instead of isVisibleForCategory() when processing multiple fields in a loop.
     * Queries: 1 for tenant-level overrides; +1 for category suppression when category is set.
     *
     * @param Tenant $tenant
     * @param array $fieldIds
     * @param Category|null $category
     * @return array Keyed by field_id, value is bool (true = visible)
     */
    public function getBatchVisibilityForCategory(Tenant $tenant, array $fieldIds, ?Category $category): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        // 1. Load tenant-level overrides (brand_id NULL, category_id NULL) - single query
        $tenantOverrides = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->whereIn('metadata_field_id', $fieldIds)
            ->get()
            ->keyBy('metadata_field_id');

        // 2. When category set: load category-specific suppression - single query
        // Matches isVisibleForCategory: suppressed if (brand_id = category.brand_id) OR (brand_id IS NULL)
        $categorySuppressed = [];
        if ($category !== null) {
            $suppressionQuery = DB::table('metadata_field_visibility')
                ->where('tenant_id', $tenant->id)
                ->where('category_id', $category->id)
                ->where('is_hidden', true)
                ->whereIn('metadata_field_id', $fieldIds)
                ->where(function ($q) use ($category) {
                    if ($category->brand_id) {
                        $q->where('brand_id', $category->brand_id)->orWhereNull('brand_id');
                    } else {
                        $q->whereNull('brand_id');
                    }
                });

            $categorySuppressed = $suppressionQuery->pluck('metadata_field_id')->flip()->all();
        }

        // 3. Combine: visible = not suppressed AND (no tenant override OR !is_hidden)
        $result = [];
        foreach ($fieldIds as $fieldId) {
            if (isset($categorySuppressed[$fieldId])) {
                $result[$fieldId] = false;
                continue;
            }
            $override = $tenantOverrides[$fieldId] ?? null;
            $result[$fieldId] = $override === null || !($override->is_hidden ?? false);
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
        
        $overrides = $query->select([
            'category_id', 'is_hidden', 'is_upload_hidden', 'is_filter_hidden',
            'is_primary', 'is_edit_hidden', 'is_required',
        ])->get();

        $result = [];
        foreach ($overrides as $override) {
            $row = [
                'is_hidden' => (bool) $override->is_hidden,
                'is_upload_hidden' => (bool) $override->is_upload_hidden,
                'is_filter_hidden' => (bool) $override->is_filter_hidden,
                'is_primary' => isset($override->is_primary) ? ($override->is_primary === 1 || $override->is_primary === true) : null,
                'is_required' => isset($override->is_required) ? ($override->is_required === 1 || $override->is_required === true) : null,
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

    /**
     * Copy all category-level visibility overrides from source category to target category.
     * Source and target must belong to the same tenant; they may be same or different brands.
     *
     * @param Tenant $tenant
     * @param Category $sourceCategory
     * @param Category $targetCategory
     * @return int Number of rows copied (upserted for target)
     */
    public function copyCategoryVisibility(Tenant $tenant, Category $sourceCategory, Category $targetCategory): int
    {
        if ($sourceCategory->tenant_id !== $tenant->id || $targetCategory->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Source and target categories must belong to the tenant.');
        }

        $sourceRows = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $sourceCategory->brand_id)
            ->where('category_id', $sourceCategory->id)
            ->get();

        $targetBrandId = $targetCategory->brand_id;
        $targetCategoryId = $targetCategory->id;
        $count = 0;

        foreach ($sourceRows as $row) {
            $existing = DB::table('metadata_field_visibility')
                ->where('metadata_field_id', $row->metadata_field_id)
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $targetBrandId)
                ->where('category_id', $targetCategoryId)
                ->first();

            $data = [
                'is_hidden' => (bool) $row->is_hidden,
                'is_upload_hidden' => (bool) $row->is_upload_hidden,
                'is_filter_hidden' => (bool) $row->is_filter_hidden,
                'updated_at' => now(),
            ];
            if (isset($row->is_primary)) {
                $data['is_primary'] = $row->is_primary;
            }
            if (isset($row->is_edit_hidden)) {
                $data['is_edit_hidden'] = $row->is_edit_hidden;
            }
            if (isset($row->is_required)) {
                $data['is_required'] = $row->is_required;
            }

            if ($existing) {
                DB::table('metadata_field_visibility')->where('id', $existing->id)->update($data);
            } else {
                $data['metadata_field_id'] = $row->metadata_field_id;
                $data['tenant_id'] = $tenant->id;
                $data['brand_id'] = $targetBrandId;
                $data['category_id'] = $targetCategoryId;
                $data['created_at'] = now();
                DB::table('metadata_field_visibility')->insert($data);
            }
            $count++;
        }

        Log::info('Tenant metadata visibility copied between categories', [
            'tenant_id' => $tenant->id,
            'source_category_id' => $sourceCategory->id,
            'target_category_id' => $targetCategory->id,
            'rows_copied' => $count,
        ]);

        return $count;
    }

    /**
     * Reset a category to default: delete all category-level visibility overrides for that category.
     * Behavior then falls back to tenant-level overrides and metadata_fields defaults.
     *
     * @param Tenant $tenant
     * @param Category $category
     * @return int Number of rows deleted
     */
    public function resetCategoryVisibility(Tenant $tenant, Category $category): int
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $query = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('category_id', $category->id);

        if ($category->brand_id) {
            $query->where('brand_id', $category->brand_id);
        } else {
            $query->whereNull('brand_id');
        }

        $count = $query->count();
        $query->delete();

        Log::info('Tenant metadata visibility reset for category', [
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'rows_deleted' => $count,
        ]);

        return $count;
    }

    /**
     * Apply minimal default visibility for a new custom category.
     * Only collection and tags are enabled; all other fields (system + automated) are disabled.
     *
     * @param Tenant $tenant
     * @param Category $category
     * @return int Number of rows written (inserted)
     */
    public function applyMinimalDefaultsForCustomCategory(Tenant $tenant, Category $category): int
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $enabledFields = ['collection', 'tags'];

        $fields = DB::table('metadata_fields')
            ->where(function ($q) use ($tenant) {
                $q->where('scope', 'system')
                    ->orWhere(function ($q2) use ($tenant) {
                        $q2->where('scope', 'tenant')->where('tenant_id', $tenant->id);
                    });
            })
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->get(['id', 'key']);

        $rows = [];
        $brandId = $category->brand_id;
        $categoryId = $category->id;

        foreach ($fields as $field) {
            $enabled = in_array($field->key, $enabledFields, true);
            $rows[] = [
                'metadata_field_id' => $field->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'is_hidden' => !$enabled,
                'is_upload_hidden' => false,
                'is_filter_hidden' => false,
                'is_primary' => null,
                'is_edit_hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            Log::info('Tenant metadata visibility applied minimal defaults (no rows)', [
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
            ]);
            return 0;
        }

        DB::table('metadata_field_visibility')->insert($rows);

        Log::info('Tenant metadata visibility applied minimal defaults for custom category', [
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'rows_written' => count($rows),
        ]);

        return count($rows);
    }

    /**
     * Apply seeded default visibility for one category (from config/metadata_category_defaults.php).
     * Used by "Reset to default" and by SystemCategoryService when adding a new category (Phase 3b).
     * Deletes existing category-level visibility, then inserts rows matching the seeder defaults.
     *
     * @param Tenant $tenant
     * @param Category $category
     * @return int Number of rows written (inserted)
     */
    public function applySeededDefaultsForCategory(Tenant $tenant, Category $category): int
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $config = config('metadata_category_defaults', []);
        $categoryConfig = $config['category_config'] ?? [];
        $restrictFields = $config['restrict_fields'] ?? [];
        $tagsAndCollectionOnlySlugs = $config['tags_and_collection_only_slugs'] ?? ['video'];
        $dominantColorsVisibility = $config['dominant_colors_visibility'] ?? [];
        $systemAutomatedEnabledForAll = $config['system_automated_enabled_for_all'] ?? [];

        $slug = $category->slug;
        $assetTypeValue = $category->asset_type?->value ?? 'asset';
        $isImageCategory = ($assetTypeValue === 'asset');
        $brandId = $category->brand_id;
        $categoryId = $category->id;

        // All metadata fields for this tenant (system + tenant-scoped, exclude archived)
        $fields = DB::table('metadata_fields')
            ->where(function ($q) use ($tenant) {
                $q->where('scope', 'system')
                    ->orWhere(function ($q2) use ($tenant) {
                        $q2->where('scope', 'tenant')->where('tenant_id', $tenant->id);
                    });
            })
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->get(['id', 'key', 'scope']);

        $rows = [];

        foreach ($fields as $field) {
            $key = $field->key;
            $scope = $field->scope ?? 'system';
            $visibility = $this->computeSeededDefaultForField(
                $key,
                $scope,
                $slug,
                $isImageCategory,
                $categoryConfig,
                $restrictFields,
                $tagsAndCollectionOnlySlugs,
                $dominantColorsVisibility,
                $systemAutomatedEnabledForAll
            );
            if ($visibility === null) {
                continue; // Skip (no row = fall back to field/tenant defaults)
            }

            $rows[] = [
                'metadata_field_id' => $field->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'is_hidden' => $visibility['is_hidden'],
                'is_upload_hidden' => $visibility['is_upload_hidden'],
                'is_filter_hidden' => $visibility['is_filter_hidden'],
                'is_primary' => $visibility['is_primary'] ?? null,
                'is_edit_hidden' => $visibility['is_edit_hidden'] ?? false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Delete existing category-level visibility, then insert defaults
        $this->resetCategoryVisibility($tenant, $category);

        if (empty($rows)) {
            Log::info('Tenant metadata visibility applied seeded defaults (no rows)', [
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
            ]);
            return 0;
        }

        DB::table('metadata_field_visibility')->insert($rows);

        Log::info('Tenant metadata visibility applied seeded defaults for category', [
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'rows_written' => count($rows),
        ]);

        return count($rows);
    }

    /**
     * Repair visibility: insert only missing rows from config. Does NOT override existing values.
     * Safe to run repeatedly. Logs each inserted row (unless dryRun).
     *
     * @param Tenant $tenant
     * @param Category $category
     * @param bool $dryRun If true, return what would be inserted without making changes
     * @return array{inserted: int, changes: array<array{field_key: string, field_id: int}>}
     */
    public function repairVisibilityForCategory(Tenant $tenant, Category $category, bool $dryRun = false): array
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $config = config('metadata_category_defaults', []);
        $categoryConfig = $config['category_config'] ?? [];
        $restrictFields = $config['restrict_fields'] ?? [];
        $tagsAndCollectionOnlySlugs = $config['tags_and_collection_only_slugs'] ?? ['video'];
        $dominantColorsVisibility = $config['dominant_colors_visibility'] ?? [];
        $systemAutomatedEnabledForAll = $config['system_automated_enabled_for_all'] ?? [];

        $slug = $category->slug;
        $assetTypeValue = $category->asset_type?->value ?? 'asset';
        $isImageCategory = ($assetTypeValue === 'asset');
        $brandId = $category->brand_id;
        $categoryId = $category->id;

        $fields = DB::table('metadata_fields')
            ->where(function ($q) use ($tenant) {
                $q->where('scope', 'system')
                    ->orWhere(function ($q2) use ($tenant) {
                        $q2->where('scope', 'tenant')->where('tenant_id', $tenant->id);
                    });
            })
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->get(['id', 'key', 'scope']);

        $existingKeys = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brandId)
            ->where('category_id', $categoryId)
            ->pluck('metadata_field_id')
            ->flip()
            ->all();

        $inserted = 0;
        $changes = [];

        foreach ($fields as $field) {
            $key = $field->key;
            $scope = $field->scope ?? 'system';
            $visibility = $this->computeSeededDefaultForField(
                $key,
                $scope,
                $slug,
                $isImageCategory,
                $categoryConfig,
                $restrictFields,
                $tagsAndCollectionOnlySlugs,
                $dominantColorsVisibility,
                $systemAutomatedEnabledForAll
            );
            if ($visibility === null) {
                continue;
            }

            if (isset($existingKeys[$field->id])) {
                continue; // Row exists - do NOT override
            }

            $row = [
                'metadata_field_id' => $field->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'is_hidden' => $visibility['is_hidden'],
                'is_upload_hidden' => $visibility['is_upload_hidden'],
                'is_filter_hidden' => $visibility['is_filter_hidden'],
                'is_primary' => $visibility['is_primary'] ?? null,
                'is_edit_hidden' => $visibility['is_edit_hidden'] ?? false,
                'is_required' => $visibility['is_required'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (! $dryRun) {
                DB::table('metadata_field_visibility')->insert($row);
                Log::info('[metadata:repair-visibility] Inserted missing visibility row', [
                    'tenant_id' => $tenant->id,
                    'category_id' => $categoryId,
                    'category_slug' => $slug,
                    'field_key' => $key,
                    'field_id' => $field->id,
                ]);
            }

            $inserted++;
            $existingKeys[$field->id] = true; // Avoid duplicate in same batch
            $changes[] = ['field_key' => $key, 'field_id' => $field->id];
        }

        return ['inserted' => $inserted, 'changes' => $changes];
    }

    /**
     * Repair type field defaults: reset type fields (photo_type, logo_type, etc.) to their initial config.
     * Type fields should only be visible in their configured category slugs; hidden everywhere else.
     * Updates existing rows and inserts missing ones. Logs changes.
     *
     * @param Tenant $tenant
     * @param Category $category
     * @param bool $dryRun If true, return what would be changed without making changes
     * @return array{updated: int, inserted: int, changes: array<array{field_key: string, action: string}>}
     */
    public function repairTypeDefaultsForCategory(Tenant $tenant, Category $category, bool $dryRun = false): array
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $config = config('metadata_category_defaults', []);
        $categoryConfig = $config['category_config'] ?? [];
        $slug = $category->slug;
        $brandId = $category->brand_id;
        $categoryId = $category->id;

        $typeFieldKeys = array_keys($categoryConfig);
        if (empty($typeFieldKeys)) {
            return ['updated' => 0, 'inserted' => 0, 'changes' => []];
        }

        $fields = DB::table('metadata_fields')
            ->whereIn('key', $typeFieldKeys)
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->get(['id', 'key']);

        $existingRows = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brandId)
            ->where('category_id', $categoryId)
            ->whereIn('metadata_field_id', $fields->pluck('id'))
            ->get()
            ->keyBy('metadata_field_id');

        $updated = 0;
        $inserted = 0;
        $changes = [];

        foreach ($fields as $field) {
            $key = $field->key;
            $allowedSlugs = array_keys($categoryConfig[$key] ?? []);
            $isAllowed = in_array($slug, $allowedSlugs, true);

            if ($isAllowed) {
                $settings = $categoryConfig[$key][$slug] ?? [];
                $expected = [
                    'is_hidden' => ! ($settings['enabled'] ?? true),
                    'is_upload_hidden' => false,
                    'is_filter_hidden' => false,
                    'is_primary' => $settings['is_primary'] ?? null,
                    'is_edit_hidden' => $settings['is_edit_hidden'] ?? false,
                ];
            } else {
                $expected = [
                    'is_hidden' => true,
                    'is_upload_hidden' => true,
                    'is_filter_hidden' => true,
                    'is_primary' => null,
                    'is_edit_hidden' => true,
                ];
            }

            $existing = $existingRows[$field->id] ?? null;

            if ($existing) {
                $needsUpdate = $existing->is_hidden != $expected['is_hidden']
                    || $existing->is_upload_hidden != $expected['is_upload_hidden']
                    || $existing->is_filter_hidden != $expected['is_filter_hidden']
                    || ($existing->is_primary ?? null) != ($expected['is_primary'] ?? null)
                    || ($existing->is_edit_hidden ?? false) != ($expected['is_edit_hidden'] ?? false);

                if ($needsUpdate && ! $dryRun) {
                    DB::table('metadata_field_visibility')->where('id', $existing->id)->update([
                        'is_hidden' => $expected['is_hidden'],
                        'is_upload_hidden' => $expected['is_upload_hidden'],
                        'is_filter_hidden' => $expected['is_filter_hidden'],
                        'is_primary' => $expected['is_primary'],
                        'is_edit_hidden' => $expected['is_edit_hidden'],
                        'updated_at' => now(),
                    ]);
                    Log::info('[metadata:repair-type-defaults] Updated type field visibility', [
                        'tenant_id' => $tenant->id,
                        'category_id' => $categoryId,
                        'category_slug' => $slug,
                        'field_key' => $key,
                        'field_id' => $field->id,
                        'action' => $isAllowed ? 'enabled' : 'hidden',
                    ]);
                }
                if ($needsUpdate) {
                    $updated++;
                    $changes[] = ['field_key' => $key, 'action' => $isAllowed ? 'enabled' : 'hidden'];
                }
            } else {
                if (! $dryRun) {
                    $row = [
                        'metadata_field_id' => $field->id,
                        'tenant_id' => $tenant->id,
                        'brand_id' => $brandId,
                        'category_id' => $categoryId,
                        'is_hidden' => $expected['is_hidden'],
                        'is_upload_hidden' => $expected['is_upload_hidden'],
                        'is_filter_hidden' => $expected['is_filter_hidden'],
                        'is_primary' => $expected['is_primary'],
                        'is_edit_hidden' => $expected['is_edit_hidden'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    DB::table('metadata_field_visibility')->insert($row);
                    Log::info('[metadata:repair-type-defaults] Inserted type field visibility', [
                        'tenant_id' => $tenant->id,
                        'category_id' => $categoryId,
                        'category_slug' => $slug,
                        'field_key' => $key,
                        'field_id' => $field->id,
                        'action' => $isAllowed ? 'enabled' : 'hidden',
                    ]);
                }
                $inserted++;
                $changes[] = ['field_key' => $key, 'action' => $isAllowed ? 'enabled' : 'hidden'];
            }
        }

        return ['updated' => $updated, 'inserted' => $inserted, 'changes' => $changes];
    }

    /**
     * Compute default visibility for one field in one category from config.
     * Returns array with is_hidden, is_upload_hidden, is_filter_hidden, is_primary (optional), or null to skip row.
     *
     * System fields (scope=system) are enabled for all categories by default.
     *
     * @param string $fieldKey
     * @param string $fieldScope
     * @param string $categorySlug
     * @param bool $isImageCategory
     * @param array $categoryConfig
     * @param array $restrictFields
     * @param array $tagsAndCollectionOnlySlugs
     * @param array $dominantColorsVisibility
     * @return array<string, mixed>|null
     */
    private function computeSeededDefaultForField(
        string $fieldKey,
        string $fieldScope,
        string $categorySlug,
        bool $isImageCategory,
        array $categoryConfig,
        array $restrictFields,
        array $tagsAndCollectionOnlySlugs,
        array $dominantColorsVisibility,
        array $systemAutomatedEnabledForAll = []
    ): ?array {
        // System automated fields: always enabled for every category
        if (in_array($fieldKey, $systemAutomatedEnabledForAll, true)) {
            $v = $dominantColorsVisibility[$fieldKey] ?? [];
            if (!empty($v)) {
                return [
                    'is_hidden' => $v['is_hidden'] ?? false,
                    'is_upload_hidden' => $v['is_upload_hidden'] ?? false,
                    'is_filter_hidden' => $v['is_filter_hidden'] ?? false,
                    'is_primary' => $v['is_primary'] ?? null,
                    'is_edit_hidden' => $v['is_edit_hidden'] ?? false,
                ];
            }
            $alwaysHidden = config('metadata_category_defaults.always_hidden_fields', []);
            $isFilterHidden = in_array($fieldKey, $alwaysHidden, true);
            return [
                'is_hidden' => false,
                'is_upload_hidden' => false,
                'is_filter_hidden' => $isFilterHidden,
                'is_primary' => null,
                'is_edit_hidden' => false,
            ];
        }

        // Explicit per-slug config for this field (checked first so type fields can override tags_and_collection_only)
        if (isset($categoryConfig[$fieldKey][$categorySlug])) {
            $settings = $categoryConfig[$fieldKey][$categorySlug];
            $enabled = $settings['enabled'] ?? true;
            $result = [
                'is_hidden' => !$enabled,
                'is_upload_hidden' => false,
                'is_filter_hidden' => false,
                'is_primary' => $settings['is_primary'] ?? null,
            ];
            if (array_key_exists('is_edit_hidden', $settings)) {
                $result['is_edit_hidden'] = $settings['is_edit_hidden'];
            }
            return $result;
        }

        // Restrict fields: only enabled for slugs listed in category_config for that field.
        // Must run BEFORE tags_and_collection_only so e.g. template_type/audio_type/model_3d_type
        // are hidden for packaging (only packaging_type should show).
        if (in_array($fieldKey, $restrictFields, true)) {
            $enabledSlugs = array_keys($categoryConfig[$fieldKey] ?? []);
            $enabled = in_array($categorySlug, $enabledSlugs, true);
            $settings = $categoryConfig[$fieldKey][$categorySlug] ?? [];
            $result = [
                'is_hidden' => !$enabled,
                'is_upload_hidden' => !$enabled,
                'is_filter_hidden' => !$enabled,
                'is_primary' => $enabled ? ($settings['is_primary'] ?? null) : null,
            ];
            if (!$enabled) {
                $result['is_edit_hidden'] = true;
            }
            return $result;
        }

        // Video (and any tags_and_collection_only): system fields enabled; tenant fields limited to tags/collection
        if (in_array($categorySlug, $tagsAndCollectionOnlySlugs, true)) {
            $enabled = ($fieldScope === 'system') || in_array($fieldKey, ['tags', 'collection'], true);
            return [
                'is_hidden' => !$enabled,
                'is_upload_hidden' => false,
                'is_filter_hidden' => false,
                'is_primary' => null,
            ];
        }

        // Dominant color fields: special visibility for image categories
        if ($isImageCategory && isset($dominantColorsVisibility[$fieldKey])) {
            $v = $dominantColorsVisibility[$fieldKey];
            $result = [
                'is_hidden' => $v['is_hidden'] ?? false,
                'is_upload_hidden' => $v['is_upload_hidden'] ?? true,
                'is_filter_hidden' => $v['is_filter_hidden'] ?? true,
                'is_primary' => $v['is_primary'] ?? null,
            ];
            if (array_key_exists('is_edit_hidden', $v)) {
                $result['is_edit_hidden'] = $v['is_edit_hidden'];
            }
            return $result;
        }

        // Default: all system fields enabled for all categories; tenant fields limited to tags/collection
        $enabled = ($fieldScope === 'system') || in_array($fieldKey, ['tags', 'collection'], true);
        return [
            'is_hidden' => !$enabled,
            'is_upload_hidden' => false,
            'is_filter_hidden' => false,
            'is_primary' => null,
        ];
    }

    /**
     * Get sibling categories in other brands (same slug + asset_type) for "Apply to other brands".
     *
     * @param Tenant $tenant
     * @param Category $sourceCategory
     * @return array<array{brand_id: int, brand_name: string, category_id: int, category_name: string}>
     */
    public function getApplyToOtherBrandsTargets(Tenant $tenant, Category $sourceCategory): array
    {
        if ($sourceCategory->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $slug = $sourceCategory->slug;
        $assetTypeValue = $sourceCategory->asset_type?->value ?? 'asset';
        $sourceBrandId = $sourceCategory->brand_id;

        $siblings = \App\Models\Category::query()
            ->active()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('asset_type', $assetTypeValue)
            ->where('brand_id', '!=', $sourceBrandId)
            ->whereHas('brand')
            ->with('brand:id,name')
            ->get();

        $targets = [];
        foreach ($siblings as $cat) {
            $targets[] = [
                'brand_id' => $cat->brand_id,
                'brand_name' => $cat->brand->name ?? '',
                'category_id' => $cat->id,
                'category_name' => $cat->name,
            ];
        }

        return $targets;
    }

    /**
     * Apply current category's visibility settings to the same category type in all other brands.
     * For each sibling category (same slug + asset_type, different brand), copies visibility from source.
     *
     * @param Tenant $tenant
     * @param Category $sourceCategory
     * @return array<array{category_id: int, brand_name: string, category_name: string, rows_copied: int}>
     */
    public function applyCategoryVisibilityToOtherBrands(Tenant $tenant, Category $sourceCategory): array
    {
        $targets = $this->getApplyToOtherBrandsTargets($tenant, $sourceCategory);
        $results = [];

        foreach ($targets as $target) {
            $targetCategory = Category::where('id', $target['category_id'])
                ->where('tenant_id', $tenant->id)
                ->first();
            if (!$targetCategory) {
                continue;
            }
            $count = $this->copyCategoryVisibility($tenant, $sourceCategory, $targetCategory);
            $results[] = [
                'category_id' => $targetCategory->id,
                'brand_name' => $target['brand_name'],
                'category_name' => $target['category_name'],
                'rows_copied' => $count,
            ];
        }

        Log::info('Tenant metadata visibility applied to other brands', [
            'tenant_id' => $tenant->id,
            'source_category_id' => $sourceCategory->id,
            'targets_count' => count($results),
        ]);

        return $results;
    }

    /**
     * Phase 3a: Build a snapshot of category visibility for saving as a named profile.
     * Returns array of { metadata_field_id, is_hidden, is_upload_hidden, is_filter_hidden, is_primary, is_edit_hidden }.
     *
     * @param Tenant $tenant
     * @param Category $category
     * @return array<int, array<string, mixed>>
     */
    public function snapshotFromCategory(Tenant $tenant, Category $category): array
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $rows = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $category->brand_id)
            ->where('category_id', $category->id)
            ->get(['metadata_field_id', 'is_hidden', 'is_upload_hidden', 'is_filter_hidden', 'is_primary', 'is_edit_hidden']);

        $snapshot = [];
        foreach ($rows as $row) {
            $entry = [
                'metadata_field_id' => (int) $row->metadata_field_id,
                'is_hidden' => (bool) $row->is_hidden,
                'is_upload_hidden' => (bool) $row->is_upload_hidden,
                'is_filter_hidden' => (bool) $row->is_filter_hidden,
                'is_primary' => isset($row->is_primary) ? (bool) $row->is_primary : null,
            ];
            if (isset($row->is_edit_hidden)) {
                $entry['is_edit_hidden'] = (bool) $row->is_edit_hidden;
            }
            $snapshot[] = $entry;
        }

        return $snapshot;
    }

    /**
     * Phase 3a: Apply a saved profile snapshot to a category.
     * Deletes existing category-level visibility, then inserts rows from snapshot (only for field_ids that exist for tenant).
     *
     * @param Tenant $tenant
     * @param Category $category
     * @param array<int, array<string, mixed>> $snapshot Array of { metadata_field_id, is_hidden, is_upload_hidden, is_filter_hidden, is_primary, is_edit_hidden }
     * @return int Number of rows written
     */
    public function applySnapshotToCategory(Tenant $tenant, Category $category, array $snapshot): int
    {
        if ($category->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('Category must belong to the tenant.');
        }

        $validFieldIds = DB::table('metadata_fields')
            ->where(function ($q) use ($tenant) {
                $q->where('scope', 'system')
                    ->orWhere(function ($q2) use ($tenant) {
                        $q2->where('scope', 'tenant')->where('tenant_id', $tenant->id);
                    });
            })
            ->where('is_active', true)
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            ->pluck('id')
            ->flip()
            ->all();

        $this->resetCategoryVisibility($tenant, $category);

        $brandId = $category->brand_id;
        $categoryId = $category->id;
        $count = 0;

        foreach ($snapshot as $entry) {
            $fieldId = (int) ($entry['metadata_field_id'] ?? 0);
            if ($fieldId <= 0 || !isset($validFieldIds[$fieldId])) {
                continue;
            }

            $row = [
                'metadata_field_id' => $fieldId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'is_hidden' => (bool) ($entry['is_hidden'] ?? false),
                'is_upload_hidden' => (bool) ($entry['is_upload_hidden'] ?? false),
                'is_filter_hidden' => (bool) ($entry['is_filter_hidden'] ?? false),
                'is_primary' => array_key_exists('is_primary', $entry) ? ($entry['is_primary'] === null ? null : (bool) $entry['is_primary']) : null,
                'is_edit_hidden' => array_key_exists('is_edit_hidden', $entry) ? (bool) $entry['is_edit_hidden'] : false,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('metadata_field_visibility')->insert($row);
            $count++;
        }

        Log::info('Tenant metadata visibility profile applied to category', [
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'rows_written' => $count,
        ]);

        return $count;
    }
}
