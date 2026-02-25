<?php

namespace App\Services;

use App\Support\MetadataCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata Schema Resolver
 *
 * Phase 1.5 – Step 3: Read-only resolver for metadata schema.
 *
 * ⚠️ PHASE LOCK: This resolver is production-locked. Do not refactor behavior.
 *
 * This service computes the effective metadata schema for a given context
 * by applying visibility overrides in the correct inheritance order.
 *
 * Rules:
 * - Deterministic and side-effect free
 * - Never mutates data
 * - Never creates missing rows
 * - Never infers defaults not explicitly defined
 *
 * Inheritance order (lowest → highest priority):
 * 1. System defaults (metadata_fields)
 * 2. Tenant-level overrides
 * 3. Brand-level overrides
 * 4. Category-level overrides
 *
 * Last override wins.
 *
 * @see docs/PHASE_1_5_METADATA_SCHEMA.md
 */
class MetadataSchemaResolver
{
    /**
     * Resolve the metadata schema for a given context.
     *
     * @param int $tenantId Required tenant ID
     * @param int|null $brandId Optional brand ID
     * @param int|null $categoryId Optional category ID
     * @param string $assetType Required: 'image' | 'video' | 'document'
     * @return array Resolved schema with fields and options
     */
    public function resolve(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        string $assetType
    ): array {
        // Validate asset_type
        if (!in_array($assetType, ['image', 'video', 'document'], true)) {
            throw new \InvalidArgumentException("Invalid asset_type: {$assetType}. Must be 'image', 'video', or 'document'.");
        }

        $cacheKey = MetadataCache::schemaKey($tenantId, $brandId, $categoryId, $assetType);
        $cached = cache()->has($cacheKey);

        if (! app()->isProduction()) {
            logger()->debug(
                $cached ? 'Metadata schema cache HIT' : 'Metadata schema cache MISS',
                [
                    'tenant' => $tenantId,
                    'brand' => $brandId,
                    'category' => $categoryId,
                    'asset_type' => $assetType,
                    'key' => $cacheKey,
                ]
            );
        }

        $result = cache()->get($cacheKey);
        if ($result !== null) {
            return $result;
        }

        // Single rebuild per key to prevent thundering herd
        $lockKey = 'metadata_schema_build:' . $cacheKey;
        return Cache::lock($lockKey, 30)->block(30, function () use ($cacheKey, $tenantId, $brandId, $categoryId, $assetType) {
            $result = cache()->get($cacheKey);
            if ($result !== null) {
                return $result;
            }
            $data = $this->resolveUncached($tenantId, $brandId, $categoryId, $assetType);
            cache()->forever($cacheKey, $data);
            return $data;
        });
    }

    /**
     * Resolve schema without cache (used by resolve() and as cache callback).
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param string $assetType
     * @return array{fields: array}
     */
    protected function resolveUncached(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        string $assetType
    ): array {
        // Load all metadata fields that apply to this asset type
        // Phase C3: Pass tenant_id to filter tenant-scoped fields
        $fields = $this->loadApplicableFields($assetType, $tenantId);

        // Load visibility overrides in inheritance order
        $fieldVisibility = $this->loadFieldVisibility($tenantId, $brandId, $categoryId, array_keys($fields));
        $optionVisibility = $this->loadOptionVisibility($tenantId, $brandId, $categoryId);

        // Eager load all metadata_options for select/multiselect fields in one query (avoid N+1)
        $selectMultiselectFieldIds = [];
        foreach ($fields as $field) {
            if (in_array($field->type ?? '', ['select', 'multiselect'], true)) {
                $selectMultiselectFieldIds[] = $field->id;
            }
        }
        $optionsByFieldId = $this->loadAllOptionsForFieldIds($selectMultiselectFieldIds);

        // Resolve each field
        $resolvedFields = [];
        foreach ($fields as $fieldId => $field) {
            $resolved = $this->resolveField(
                $field,
                $fieldVisibility[$fieldId] ?? [],
                $optionVisibility,
                $assetType,
                $optionsByFieldId[$fieldId] ?? []
            );

            // Only include fields that are visible (not hidden)
            if ($resolved['is_visible']) {
                $resolvedFields[] = $resolved;
            } elseif (($resolved['key'] ?? null) === 'collection') {
                \Illuminate\Support\Facades\Log::info('[MetadataSchemaResolver] resolve EXCLUDED collection (is_visible=false)', [
                    'category_id' => $categoryId,
                    'asset_type' => $assetType,
                ]);
            }
        }

        $resolvedKeys = array_column($resolvedFields, 'key');
        \Illuminate\Support\Facades\Log::info('[MetadataSchemaResolver] resolve result', [
            'category_id' => $categoryId,
            'field_keys' => $resolvedKeys,
            'has_collection' => in_array('collection', $resolvedKeys, true),
        ]);

        return [
            'fields' => $resolvedFields,
        ];
    }

    /**
     * Load all metadata fields that apply to the given asset type.
     *
     * Phase C3: Filters tenant fields by tenant_id to ensure proper isolation.
     *
     * @param string $assetType
     * @param int|null $tenantId Optional tenant ID for filtering tenant-scoped fields
     * @return array Keyed by field ID
     */
    protected function loadApplicableFields(string $assetType, ?int $tenantId = null): array
    {
        $fields = DB::table('metadata_fields')
            ->where(function ($query) use ($assetType) {
                $query->where('applies_to', $assetType)
                    ->orWhere('applies_to', 'all');
            })
            ->whereNull('deprecated_at')
            ->whereNull('archived_at')
            // Phase C3: Filter tenant fields by tenant_id, include all system fields
            ->where(function ($query) use ($tenantId) {
                // Include all system fields (scope='system', tenant_id IS NULL)
                $query->where(function ($q) {
                    $q->where('scope', 'system')
                        ->whereNull('tenant_id');
                });
                
                // Include tenant fields only for the specified tenant (if tenant_id provided)
                if ($tenantId !== null) {
                    $query->orWhere(function ($q) use ($tenantId) {
                        $q->where('scope', 'tenant')
                            ->where('tenant_id', $tenantId)
                            ->where('is_active', true) // Only active tenant fields
                            ->whereNull('archived_at'); // Exclude archived
                    });
                }
            })
            // Phase B2: Select new attributes with safe defaults
            ->select(array_merge([
                'id',
                'key',
                'system_label',
                'type',
                'applies_to',
                'scope',
                'is_filterable',
                'is_user_editable',
                'is_ai_trainable',
                'is_upload_visible',
                'is_internal_only',
                'group_key',
                'plan_gate',
                'deprecated_at',
                'replacement_field_id',
                // Phase B2: New attributes with safe defaults
                DB::raw("COALESCE(population_mode, 'manual') as population_mode"),
                DB::raw("COALESCE(show_on_upload, true) as show_on_upload"),
                DB::raw("COALESCE(show_on_edit, true) as show_on_edit"),
                DB::raw("COALESCE(show_in_filters, true) as show_in_filters"),
                DB::raw("COALESCE(readonly, false) as readonly"),
                DB::raw("COALESCE(is_primary, false) as is_primary"),
            ], Schema::hasColumn('metadata_fields', 'display_widget') ? ['display_widget'] : []))
            ->get()
            ->keyBy('id');

        return $fields->toArray();
    }

    /**
     * Load field visibility overrides in inheritance order.
     *
     * Returns visibility flags keyed by field_id, with highest priority override winning.
     *
     * Inheritance order: tenant < brand < category (category wins)
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param array $fieldIds
     * @return array Keyed by field_id, containing visibility flags
     */
    protected function loadFieldVisibility(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        array $fieldIds
    ): array {
        if (empty($fieldIds)) {
            return [];
        }

        // Build OR conditions for all applicable scopes
        // C9.2: Explicitly select columns, conditionally include is_edit_hidden if it exists
        $selectColumns = [
            'metadata_field_id',
            'is_hidden',
            'is_upload_hidden',
            'is_filter_hidden',
            'is_primary', // Category-scoped primary filter placement
        ];
        if (\Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
            $selectColumns[] = 'is_edit_hidden'; // C9.2: Edit visibility (Quick View checkbox)
        }
        if (\Schema::hasColumn('metadata_field_visibility', 'is_required')) {
            $selectColumns[] = 'is_required'; // Category-scoped required field for upload
        }
        
        $query = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenantId)
            ->whereIn('metadata_field_id', $fieldIds)
            ->select($selectColumns)
            ->where(function ($q) use ($brandId, $categoryId) {
                // Tenant-level: brand_id IS NULL AND category_id IS NULL
                $q->where(function ($subQ) {
                    $subQ->whereNull('brand_id')->whereNull('category_id');
                });

                // Brand-level: brand_id = $brandId AND category_id IS NULL
                if ($brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId) {
                        $subQ->where('brand_id', $brandId)->whereNull('category_id');
                    });
                }

                // Category-level: brand_id = $brandId AND category_id = $categoryId
                if ($categoryId !== null && $brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId, $categoryId) {
                        $subQ->where('brand_id', $brandId)->where('category_id', $categoryId);
                    });
                }
            })
            ->orderByRaw('
                CASE
                    WHEN category_id IS NOT NULL THEN 3
                    WHEN brand_id IS NOT NULL THEN 2
                    ELSE 1
                END DESC
            ')
            ->get();

        // Group by field_id and take the first (highest priority) override
        // Since we order DESC, highest priority (category=3) comes first
        $results = [];
        foreach ($query as $row) {
            // Only set if not already set (first = highest priority wins)
            if (!isset($results[$row->metadata_field_id])) {
                $results[$row->metadata_field_id] = [
                    'is_hidden' => (bool) $row->is_hidden,
                    'is_upload_hidden' => (bool) $row->is_upload_hidden,
                    'is_edit_hidden' => (bool) ($row->is_edit_hidden ?? false), // C9.2: Edit visibility
                    'is_filter_hidden' => (bool) $row->is_filter_hidden,
                    'is_primary' => isset($row->is_primary) ? ($row->is_primary === 1 || $row->is_primary === true) : null,
                    'is_required' => isset($row->is_required) ? ($row->is_required === 1 || $row->is_required === true) : null,
                ];
            }
        }

        return $results;
    }

    /**
     * Load option visibility overrides in inheritance order.
     *
     * Inheritance order: tenant < brand < category (category wins)
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array Keyed by option_id, containing is_hidden flag
     */
    protected function loadOptionVisibility(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId
    ): array {
        // Build OR conditions for all applicable scopes
        $query = DB::table('metadata_option_visibility')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($brandId, $categoryId) {
                // Tenant-level: brand_id IS NULL AND category_id IS NULL
                $q->where(function ($subQ) {
                    $subQ->whereNull('brand_id')->whereNull('category_id');
                });

                // Brand-level: brand_id = $brandId AND category_id IS NULL
                if ($brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId) {
                        $subQ->where('brand_id', $brandId)->whereNull('category_id');
                    });
                }

                // Category-level: brand_id = $brandId AND category_id = $categoryId
                if ($categoryId !== null && $brandId !== null) {
                    $q->orWhere(function ($subQ) use ($brandId, $categoryId) {
                        $subQ->where('brand_id', $brandId)->where('category_id', $categoryId);
                    });
                }
            })
            ->orderByRaw('
                CASE
                    WHEN category_id IS NOT NULL THEN 3
                    WHEN brand_id IS NOT NULL THEN 2
                    ELSE 1
                END DESC
            ')
            ->get();

        // Group by option_id and take the first (highest priority) override
        // Since we order DESC, highest priority (category=3) comes first
        $results = [];
        foreach ($query as $row) {
            // Only set if not already set (first = highest priority wins)
            if (!isset($results[$row->metadata_option_id])) {
                $results[$row->metadata_option_id] = (bool) $row->is_hidden;
            }
        }

        return $results;
    }

    /**
     * Load all metadata_options for the given field IDs in one query.
     * Returns map of field_id => list of option rows (ordered by system_label asc).
     *
     * @param int[] $fieldIds
     * @return array<int, \Illuminate\Support\Collection>
     */
    protected function loadAllOptionsForFieldIds(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        $rows = DB::table('metadata_options')
            ->whereIn('metadata_field_id', $fieldIds)
            ->orderBy('system_label', 'asc')
            ->get();

        $byFieldId = [];
        foreach ($rows as $row) {
            $byFieldId[$row->metadata_field_id] = $byFieldId[$row->metadata_field_id] ?? collect();
            $byFieldId[$row->metadata_field_id]->push($row);
        }

        return $byFieldId;
    }

    /**
     * Resolve a single metadata field with its options.
     *
     * @param object $field Raw field data from database
     * @param array $visibilityOverrides Visibility flags from overrides
     * @param array $optionVisibility Option visibility map
     * @param string $assetType
     * @param \Illuminate\Support\Collection|array $preloadedOptions Optional pre-loaded option rows for this field (avoids N+1)
     * @return array Resolved field schema
     */
    protected function resolveField(
        object $field,
        array $visibilityOverrides,
        array $optionVisibility,
        string $assetType,
        $preloadedOptions = []
    ): array {
        // Start with system defaults
        $isHidden = false; // Category suppression only (big toggle)
        $isUploadHidden = !$field->is_upload_visible;
        $isEditHidden = !($field->show_on_edit ?? true); // C9.2: Edit visibility (Quick View)
        $isFilterHidden = !$field->is_filterable;

        // Apply visibility overrides (last override wins)
        if (isset($visibilityOverrides['is_hidden'])) {
            $isHidden = $visibilityOverrides['is_hidden']; // Category suppression only
        }
        if (isset($visibilityOverrides['is_upload_hidden'])) {
            $isUploadHidden = $visibilityOverrides['is_upload_hidden'];
        }
        if (isset($visibilityOverrides['is_edit_hidden'])) {
            $isEditHidden = $visibilityOverrides['is_edit_hidden']; // C9.2: Edit visibility override
        }
        if (isset($visibilityOverrides['is_filter_hidden'])) {
            $isFilterHidden = $visibilityOverrides['is_filter_hidden'];
        }

        // dominant_hue_group: filter-only system field — hard-enforce
        if (($field->key ?? null) === 'dominant_hue_group') {
            $isEditHidden = true;
            $isUploadHidden = true;
        }

        // Resolve effective_is_primary from category override
        // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
        // A field may be primary in Photography but secondary in Logos.
        //
        // Resolution order:
        // 1. dominant_hue_group: always false (filter-only, secondary only)
        // 2. Category override (metadata_field_visibility.is_primary where category_id is set) - highest priority
        // 3. Fallback to global metadata_fields.is_primary (backward compatibility, deprecated)
        // 4. Default to false if neither exists
        //
        // This ensures that filter placement is category-specific, not global.
        // Never use global is_primary directly - always resolve through category context.
        $effectiveIsPrimary = false;
        if (($field->key ?? null) === 'dominant_hue_group') {
            $effectiveIsPrimary = false;
        } elseif (isset($visibilityOverrides['is_primary']) && $visibilityOverrides['is_primary'] !== null) {
            // Category override exists (highest priority)
            $effectiveIsPrimary = (bool) $visibilityOverrides['is_primary'];
        } elseif (isset($field->is_primary)) {
            // Fallback to global is_primary (backward compatibility)
            // TODO: Deprecate metadata_fields.is_primary - migrate all fields to category overrides
            $effectiveIsPrimary = (bool) $field->is_primary;
        }

        // Resolve effective_is_required from category override
        // ARCHITECTURAL RULE: Required status MUST be category-scoped (like is_primary).
        // A field may be required in Photography but optional in Logos.
        $effectiveIsRequired = false;
        if (isset($visibilityOverrides['is_required']) && $visibilityOverrides['is_required'] !== null) {
            $effectiveIsRequired = (bool) $visibilityOverrides['is_required'];
        }

        // Resolve display label (stub for future label override table)
        $displayLabel = $this->resolveDisplayLabel($field);

        // Load and resolve options for select/multiselect fields (use pre-loaded when provided to avoid N+1)
        $options = [];
        if (in_array($field->type, ['select', 'multiselect'], true)) {
            $optionRows = $preloadedOptions instanceof \Illuminate\Support\Collection
                ? $preloadedOptions
                : collect($preloadedOptions);
            $options = $this->resolveOptionsFromRows($optionRows, $optionVisibility);
        }

        return [
            'field_id' => $field->id,
            'key' => $field->key,
            'display_label' => $displayLabel,
            'type' => $field->type,
            'group_key' => $field->group_key,
            'applies_to' => $field->applies_to,
            'display_widget' => $field->display_widget ?? null,
            'is_visible' => !$isHidden, // Category suppression only (big toggle)
            'is_upload_visible' => !$isUploadHidden,
            'is_filterable' => !$isFilterHidden,
            'is_internal_only' => (bool) $field->is_internal_only,
            // Phase B2: Add population and visibility attributes (safe defaults applied)
            'population_mode' => $field->population_mode ?? 'manual',
            // C9.2: Apply category-level edit visibility override
            // show_on_edit: base field setting OR category override (is_edit_hidden)
            'show_on_upload' => isset($field->show_on_upload) ? (bool) $field->show_on_upload : true,
            'show_on_edit' => !$isEditHidden, // C9.2: Apply category-level edit visibility override
            'show_in_filters' => isset($field->show_in_filters) ? (bool) $field->show_in_filters : true,
            'readonly' => isset($field->readonly) ? (bool) $field->readonly : false,
            // Category-scoped primary filter placement
            // effective_is_primary: true = primary for this category, false = secondary for this category
            // Resolution: category override > global is_primary (deprecated) > false
            'is_primary' => $effectiveIsPrimary,
            // Category-scoped required field for upload
            // effective_is_required: true = must be filled when adding assets to this category
            'is_required' => $effectiveIsRequired,
            'required' => $effectiveIsRequired, // Alias for upload validation compatibility
            'options' => $options,
        ];
    }

    /**
     * Resolve display label for a field.
     *
     * Currently returns system_label. Future: apply label overrides.
     *
     * @param object $field
     * @return string
     */
    protected function resolveDisplayLabel(object $field): string
    {
        // TODO: Phase 1.5 Step 4+ will add label override table
        // For now, return system_label as display_label
        return $field->system_label;
    }

    /**
     * Resolve options from pre-loaded option rows (visibility applied).
     * Used when options are batch-loaded to avoid N+1.
     *
     * @param \Illuminate\Support\Collection|iterable $optionRows
     * @param array $optionVisibility Map of option_id => is_hidden
     * @return array Resolved options (only visible ones)
     */
    protected function resolveOptionsFromRows($optionRows, array $optionVisibility): array
    {
        $resolved = [];
        foreach ($optionRows as $option) {
            $isHidden = $optionVisibility[$option->id] ?? false;
            if (!$isHidden) {
                $resolved[] = [
                    'option_id' => $option->id,
                    'value' => $option->value,
                    'display_label' => $option->system_label,
                    'label' => $option->system_label,
                    'color' => $option->color ?? null,
                    'icon' => $option->icon ?? null,
                ];
            }
        }
        return $resolved;
    }

    /**
     * Resolve options for a select/multiselect field (single-field query).
     * Prefer batch-loading via loadAllOptionsForFieldIds + resolveOptionsFromRows to avoid N+1.
     *
     * @param int $fieldId
     * @param array $optionVisibility Map of option_id => is_hidden
     * @return array Resolved options (only visible ones)
     */
    protected function resolveOptions(int $fieldId, array $optionVisibility): array
    {
        $options = DB::table('metadata_options')
            ->where('metadata_field_id', $fieldId)
            ->orderBy('system_label', 'asc')
            ->get();

        return $this->resolveOptionsFromRows($options, $optionVisibility);
    }
}
