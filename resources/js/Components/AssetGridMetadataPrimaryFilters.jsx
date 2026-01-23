/**
 * Asset Grid Metadata Primary Filters Component
 * 
 * Renders primary metadata filters inline in the primary filter bar area.
 * Primary metadata filters are metadata fields marked with is_primary === true.
 * 
 * This component uses existing filter helpers:
 * - normalizeFilterConfig: Normalizes Inertia props
 * - filterTierResolver.getPrimaryFilters(): Gets primary metadata filters from schema
 * - filterVisibilityRules.getVisibleFilters(): Filters to visible only (category/asset_type compatibility + available_values)
 * 
 * ⚠️ CONSTRAINTS:
 * - React component only (render-only)
 * - No backend changes
 * - No helper modifications
 * - No resolver changes
 * - UI-only, additive work
 * 
 * DESIGN:
 * - Primary metadata filters render inline (always visible, no expand/collapse)
 * - Only shows filters with available_values.length > 0
 * - Uses same filter control components as secondary filters (FilterFieldInput)
 * - Respects URL state and updates immediately (no Apply button)
 * - Filters update grid immediately via URL query params
 * 
 * SOURCE OF TRUTH:
 * - Metadata field property: is_primary === true
 * - If is_primary is missing → default to secondary (handled by filterTierResolver)
 * 
 * VISIBILITY RULES:
 * - Primary metadata filters are visible only if:
 *   1. Field has is_primary === true (explicit check, no fallthrough)
 *   2. Field is category-compatible (filterScopeRules.isFilterCompatible)
 *   3. Field is asset_type-compatible (filterScopeRules.isFilterCompatible)
 *   4. Field has available_values[field.key].length > 0 (primary filters require values)
 * - Hidden otherwise (no disabled states)
 * 
 * NOTE: Primary filters require available_values because they're always visible inline.
 * If a primary filter has no values, it shouldn't be shown (empty dropdown is UX-hostile).
 * 
 * FILTER ROUTING:
 * - PRIMARY filters: is_primary === true → rendered here
 * - SECONDARY filters: is_primary !== true → rendered by AssetGridSecondaryFilters
 * 
 * NO DUPLICATION:
 * - Fields rendered as primary MUST NOT appear in secondary filters
 * - AssetGridSecondaryFilters excludes is_primary === true fields
 * 
 * @module AssetGridMetadataPrimaryFilters
 */

import { useState, useEffect, useMemo } from 'react'
import { usePage, router } from '@inertiajs/react'
import { normalizeFilterConfig } from '../utils/normalizeFilterConfig'
import { getPrimaryFilters } from '../utils/filterTierResolver'
import { getVisibleFilters } from '../utils/filterVisibilityRules'

/**
 * Primary Metadata Filter Bar Component
 * 
 * Renders primary metadata filters inline in the primary filter bar area.
 * Only visible primary metadata filters are shown.
 * 
 * @param {Object} props
 * @param {Array} props.filterable_schema - Filterable metadata schema from backend
 * @param {number|null} props.selectedCategoryId - Currently selected category ID
 * @param {Object} props.available_values - Map of field_key to available values
 * @param {string} props.assetType - Current asset type (defaults to 'asset')
 */
export default function AssetGridMetadataPrimaryFilters({
    filterable_schema = [],
    selectedCategoryId = null,
    available_values = {},
    assetType = 'asset',
    compact = false, // Compact mode for toolbar placement
}) {
    const pageProps = usePage().props
    const { auth } = pageProps
    
    // Normalize filter config using existing helper
    // This ensures consistent shape from potentially inconsistent Inertia props
    const normalizedConfig = useMemo(() => {
        const normalized = normalizeFilterConfig({
            auth: pageProps.auth,
            selected_category: selectedCategoryId,
            asset_type: assetType,
            filterable_schema: filterable_schema,
            available_values: available_values,
        })
        
        return normalized
    }, [pageProps.auth, selectedCategoryId, assetType, filterable_schema, available_values])
    
    // Get primary metadata filters using existing helper
    // This returns FilterClassification objects with .field property
    // Only includes fields where is_primary === true
    const primaryFilterClassifications = useMemo(() => {
        return getPrimaryFilters(normalizedConfig)
    }, [normalizedConfig])
    
    // Get visible primary metadata filters using existing helper
    // This filters out any primary filters that shouldn't be shown
    // Note: getPrimaryFilters returns FilterClassification objects with .field property
    const visibilityContext = useMemo(() => {
        // Metadata filters use file type ('image', 'video', 'document') for asset_type compatibility
        // The normalizedConfig.asset_type might be organizational ('asset', 'marketing')
        // For metadata field compatibility, we need to use 'image' as the file type
        // since most assets are images and metadata schema is resolved with file type
        const context = {
            category_id: normalizedConfig.category_id,
            asset_type: 'image', // Use file type for metadata filter compatibility
            available_values: normalizedConfig.available_values,
        };
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - visibilityContext:', context);
        return context;
    }, [normalizedConfig])
    
    const visiblePrimaryFilters = useMemo(() => {
        // DEBUG: Log filterable_schema to see what we're working with
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - filterable_schema:', filterable_schema)
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - selectedCategoryId:', selectedCategoryId)
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - available_values:', available_values)
        
        // Extract .field from FilterClassification objects before checking visibility
        // getVisibleFilters expects filter objects, not classification objects
        const filterFields = primaryFilterClassifications.map(classification => classification.field || classification)
        
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - primaryFilterClassifications:', primaryFilterClassifications)
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - filterFields:', filterFields)
        
        // Filter to only metadata fields (exclude system filters like search, category, asset_type, brand)
        // System filters are handled by AssetGridPrimaryFilters component
        const metadataFields = filterFields.filter(field => {
            const fieldKey = field.field_key || field.key
            // Exclude system filter keys
            return fieldKey !== 'search' && 
                   fieldKey !== 'category' && 
                   fieldKey !== 'asset_type' && 
                   fieldKey !== 'brand'
        })
        
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - metadataFields:', metadataFields)
        
        // Explicit guard: Only include fields where is_primary === true
        // Primary filters = effective_is_primary === true (category-scoped, computed by MetadataSchemaResolver)
        // ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
        // A field may be primary in Photography but secondary in Logos.
        // The field.is_primary value is already the effective_is_primary from schema resolution.
        const primaryMetadataFields = metadataFields.filter(field => {
            const isPrimary = field.is_primary === true
            console.log('[AssetGridMetadataPrimaryFilters] DEBUG - field:', field.field_key || field.key, 'is_primary:', field.is_primary, 'matches:', isPrimary)
            return isPrimary
        })
        
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - primaryMetadataFields:', primaryMetadataFields)
        
        // Apply visibility rules (category compatibility, asset_type compatibility, available_values)
        const visible = getVisibleFilters(primaryMetadataFields, visibilityContext)
        
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - visiblePrimaryFilters:', visible)
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - visibilityContext:', visibilityContext)
        
        return visible
    }, [primaryFilterClassifications, visibilityContext, filterable_schema, selectedCategoryId, available_values])
    
    // Filter state (stored in URL query params)
    const [filters, setFilters] = useState({})
    
    // Load filters from URL params on mount
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search)
        const filtersParam = urlParams.get('filters')
        if (filtersParam) {
            try {
                setFilters(JSON.parse(decodeURIComponent(filtersParam)))
            } catch (e) {
                console.error('[AssetGridMetadataPrimaryFilters] Failed to parse filters from URL', e)
            }
        }
    }, [])
    
    // Update filter value and immediately update URL
    const handleFilterChange = (fieldKey, operator, value) => {
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - handleFilterChange:', {
            fieldKey,
            operator,
            value,
            value_type: typeof value,
        });
        
        const newFilters = {
            ...filters,
            [fieldKey]: { operator, value },
        }
        
        // Remove filter if value is empty/null
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            delete newFilters[fieldKey]
        }
        
        setFilters(newFilters)
        
        // Update URL immediately (no Apply button)
        const urlParams = new URLSearchParams(window.location.search)
        
        // Remove existing filters param
        urlParams.delete('filters')
        
        // Add new filters if any
        const activeFilters = Object.entries(newFilters).filter(([_, filter]) => {
            return filter && filter.value !== null && filter.value !== '' && 
                   (!Array.isArray(filter.value) || filter.value.length > 0)
        })
        
        if (activeFilters.length > 0) {
            const filtersObj = {}
            activeFilters.forEach(([key, filter]) => {
                filtersObj[key] = {
                    operator: filter.operator,
                    value: filter.value,
                }
            })
            const filtersJson = JSON.stringify(filtersObj)
            urlParams.set('filters', filtersJson)
            console.log('[AssetGridMetadataPrimaryFilters] DEBUG - Setting filters in URL:', {
                filtersObj,
                filtersJson,
                urlParams: Object.fromEntries(urlParams),
            });
        } else {
            console.log('[AssetGridMetadataPrimaryFilters] DEBUG - No active filters, removing from URL');
        }
        
        // Update URL without full page reload
        const urlParamsObj = Object.fromEntries(urlParams);
        console.log('[AssetGridMetadataPrimaryFilters] DEBUG - Router.get with params:', urlParamsObj);
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: ['assets'], // Only reload assets
        })
    }
    
    // Always render the primary filter container
    // Content changes based on category, but container persists
    // Show nothing if no filters available
    if (compact) {
        // Compact mode for toolbar placement (between search and controls)
        if (visiblePrimaryFilters.length === 0) {
            return null
        }
        
        return (
            <div className="flex items-center">
                <div className="flex items-center gap-2">
                    {visiblePrimaryFilters.map((field) => {
                        const fieldKey = field.field_key || field.key
                        const currentFilter = filters[fieldKey] || {}
                        const currentValue = currentFilter.value ?? null
                        const currentOperator = currentFilter.operator ?? (field.operators?.[0]?.value || 'equals')
                        
                        return (
                            <FilterFieldInput
                                key={fieldKey}
                                field={field}
                                value={currentValue}
                                operator={currentOperator}
                                availableValues={available_values[fieldKey] || []}
                                onChange={(operator, value) => handleFilterChange(fieldKey, operator, value)}
                            />
                        )
                    })}
                </div>
            </div>
        )
    }
    
    // Full-width mode (standalone bar)
    if (visiblePrimaryFilters.length === 0) {
        return null
    }
    
    return (
        <div className="bg-white border-b border-gray-200 px-4 py-3 sm:px-6">
            <div className="flex flex-wrap items-center gap-3">
                {visiblePrimaryFilters.map((field) => {
                const fieldKey = field.field_key || field.key
                const currentFilter = filters[fieldKey] || {}
                const currentValue = currentFilter.value ?? null
                const currentOperator = currentFilter.operator ?? (field.operators?.[0]?.value || 'equals')
                
                return (
                    <FilterFieldInput
                        key={fieldKey}
                        field={field}
                        value={currentValue}
                        operator={currentOperator}
                        availableValues={available_values[fieldKey] || []}
                        onChange={(operator, value) => handleFilterChange(fieldKey, operator, value)}
                    />
                )
                })}
            </div>
        </div>
    )
}

/**
 * Filter Field Input Component
 * 
 * Renders a single filter field input based on field type.
 * Only shows options that exist in available_values (computed from current asset set).
 * 
 * This is the same component used by AssetGridSecondaryFilters to ensure consistency.
 */
function FilterFieldInput({ field, value, operator, onChange, availableValues = [] }) {
    const fieldKey = field.field_key || field.key
    const fieldType = field.type || 'text'
    
    // Filter options to only show values that exist in available_values
    // This ensures users only see options that actually have matching assets
    const filteredOptions = useMemo(() => {
        if (!field.options || !Array.isArray(field.options)) {
            return null // null means "use all options" (no filtering applied)
        }
        
        // If no available values provided, show all options (fallback)
        if (!availableValues || availableValues.length === 0) {
            return null // null means "use all options"
        }
        
        // Filter options to only those with values in available_values
        // available_values contains the actual values used in assets (e.g., 'action', 'lifestyle')
        // Options may have value, option_id, or both - check all possible formats
        const filtered = field.options.filter(option => {
            const optionValue = option.value
            const optionId = option.option_id
            // Check if either the value or option_id matches any available value
            // Convert to string for comparison to handle number/string mismatches
            return availableValues.some(av => 
                String(av) === String(optionValue) || 
                (optionId !== undefined && String(av) === String(optionId))
            )
        })
        
        // If filtering resulted in no matches, fall back to all options
        // This prevents empty dropdowns when there's a value format mismatch
        if (filtered.length === 0) {
            return null // null means "use all options" (fallback)
        }
        
        return filtered
    }, [field.options, availableValues, fieldKey])
    
    const handleOperatorChange = (e) => {
        onChange(e.target.value, value)
    }
    
    const handleValueChange = (newValue) => {
        onChange(operator, newValue)
    }
    
    return (
        <div className="flex-shrink-0">
            <label className="block text-xs font-medium text-gray-700 mb-1">
                {field.display_label || field.label}
            </label>
            <div className="flex items-center gap-2">
                {field.operators && field.operators.length > 1 && (
                    <select
                        value={operator}
                        onChange={handleOperatorChange}
                        className="flex-shrink-0 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        {field.operators.map((op) => (
                            <option key={op.value} value={op.value}>
                                {op.label}
                            </option>
                        ))}
                    </select>
                )}
                <FilterValueInput
                    field={field}
                    operator={operator}
                    value={value}
                    filteredOptions={filteredOptions}
                    onChange={handleValueChange}
                />
            </div>
        </div>
    )
}

/**
 * Filter Value Input Component
 * 
 * Renders the appropriate input based on field type.
 * Uses filteredOptions to only show options that exist in available_values.
 * 
 * This is the same component used by AssetGridSecondaryFilters to ensure consistency.
 */
function FilterValueInput({ field, operator, value, onChange, filteredOptions = null }) {
    const fieldType = field.type || 'text'
    
    // Use filteredOptions if provided and non-empty, otherwise fall back to field.options
    // filteredOptions is null when not provided (use all options)
    // filteredOptions is [] when filtering resulted in no matches (still use all options as fallback)
    // filteredOptions has items when filtering found matches (use filtered list)
    const options = (filteredOptions !== null && filteredOptions.length > 0) 
        ? filteredOptions 
        : (field.options || [])
    
    switch (fieldType) {
        case 'text':
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                    placeholder="Enter value..."
                />
            )
        
        case 'number':
            if (operator === 'range') {
                return (
                    <div className="flex items-center gap-1">
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[0] : ''}
                            onChange={(e) => onChange([e.target.value || null, Array.isArray(value) ? value[1] : null])}
                            className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 w-20"
                            placeholder="Min"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 w-20"
                            placeholder="Max"
                        />
                    </div>
                )
            }
            return (
                <input
                    type="number"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                    placeholder="Enter number..."
                />
            )
        
        case 'boolean':
            return (
                <select
                    value={value === null ? '' : String(value)}
                    onChange={(e) => onChange(e.target.value === 'true' ? true : e.target.value === 'false' ? false : null)}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                >
                    <option value="">Any</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                </select>
            )
        
        case 'select':
            return (
                <select
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                >
                    <option value="">Any</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label || option.value}
                        </option>
                    ))}
                </select>
            )
        
        case 'multiselect':
            return (
                <select
                    multiple
                    value={Array.isArray(value) ? value : []}
                    onChange={(e) => {
                        const selected = Array.from(e.target.selectedOptions, (opt) => opt.value)
                        onChange(selected.length > 0 ? selected : null)
                    }}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                    size={Math.min((options?.length || 0) + 1, 5)}
                >
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label || option.value}
                        </option>
                    ))}
                </select>
            )
        
        case 'date':
            if (operator === 'range') {
                return (
                    <div className="flex items-center gap-1">
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[0] : ''}
                            onChange={(e) => onChange([e.target.value || null, Array.isArray(value) ? value[1] : null])}
                            className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        />
                    </div>
                )
            }
            return (
                <input
                    type="date"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                />
            )
        
        default:
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 min-w-[120px]"
                    placeholder="Enter value..."
                />
            )
    }
}
