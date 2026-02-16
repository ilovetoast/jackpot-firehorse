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
import { parseFiltersFromUrl, buildUrlParamsWithFlatFilters } from '../utils/filterUrlUtils'
import { updateFilterDebug } from '../utils/assetFilterDebug'
import { FilterFieldInput } from './FilterFieldInput'
import { resolve, CONTEXT, WIDGET } from '../utils/widgetResolver'
import { XMarkIcon } from '@heroicons/react/24/outline'

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
        // The normalizedConfig.asset_type might be organizational ('asset', 'deliverable')
        // For metadata field compatibility, we need to use 'image' as the file type
        // since most assets are images and metadata schema is resolved with file type
        return {
            category_id: normalizedConfig.category_id,
            asset_type: 'image', // Use file type for metadata filter compatibility
            available_values: normalizedConfig.available_values,
        };
    }, [normalizedConfig])
    
    const filterKeys = useMemo(() => 
        (filterable_schema || []).map(f => f.field_key || f.key).filter(Boolean),
        [filterable_schema]
    )
    
    const visiblePrimaryFilters = useMemo(() => {
        const filterFields = primaryFilterClassifications.map(classification => classification.field || classification)
        const metadataFields = filterFields.filter(field => {
            const fieldKey = field.field_key || field.key
            return fieldKey !== 'search' && fieldKey !== 'category' && fieldKey !== 'asset_type' && fieldKey !== 'brand'
        })
        const primaryMetadataFields = metadataFields.filter(field => field.is_primary === true)
        return getVisibleFilters(primaryMetadataFields, visibilityContext)
    }, [primaryFilterClassifications, visibilityContext, filterable_schema, selectedCategoryId, available_values])
    
    const page = usePage()
    const [filters, setFilters] = useState(() => {
        try {
            const search = typeof window !== 'undefined' ? window.location.search : (page.url?.split('?')[1] || '')
            const urlParams = new URLSearchParams(search)
            return parseFiltersFromUrl(urlParams, filterKeys)
        } catch (e) { /* ignore */ }
        return {}
    })
    
    // Sync filters from URL when page/URL changes (e.g. after router.get, back/forward, or initial load with query)
    useEffect(() => {
        const search = typeof window !== 'undefined' ? window.location.search : (page.url?.includes('?') ? '?' + page.url.split('?')[1] : '')
        const urlParams = new URLSearchParams(search)
        setFilters(parseFiltersFromUrl(urlParams, filterKeys))
    }, [page.url, filterKeys])
    
    const handleFilterChange = (fieldKey, operator, value) => {
        const newFilters = { ...filters, [fieldKey]: { operator, value } }
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            delete newFilters[fieldKey]
        }
        setFilters(newFilters)
        const urlParams = new URLSearchParams(window.location.search)
        const urlParamsObj = buildUrlParamsWithFlatFilters(urlParams, newFilters, filterKeys)
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url'],
        })
    }
    
    const handleRemoveFilter = (fieldKey) => {
        const newFilters = { ...filters }
        delete newFilters[fieldKey]
        setFilters(newFilters)
        const urlParams = new URLSearchParams(window.location.search)
        const urlParamsObj = buildUrlParamsWithFlatFilters(urlParams, newFilters, filterKeys)
        router.get(window.location.pathname, urlParamsObj, {
            preserveState: true,
            preserveScroll: true,
            only: ['assets', 'next_page_url'],
        })
    }
    
    // Single debug state update (inspect window.__assetFilterDebug in console)
    useEffect(() => {
        updateFilterDebug({
            filters,
            url: { search: typeof window !== 'undefined' ? window.location.search : '', flatParams: buildUrlParamsWithFlatFilters(new URLSearchParams(window.location.search || ''), filters, filterKeys) },
            visibility: {
                visiblePrimary: (visiblePrimaryFilters || []).map(f => f.field_key || f.key),
                hiddenCount: (filterable_schema || []).length - (visiblePrimaryFilters || []).length,
            },
            schema: { filterKeys, primaryKeys: (visiblePrimaryFilters || []).map(f => f.field_key || f.key) },
        })
    }, [filters, visiblePrimaryFilters, filterable_schema, filterKeys])
    
    const appliedFiltersList = useMemo(() => {
        return Object.entries(filters).filter(([key, def]) => {
            if (key === 'category' || key === 'asset_type') return false
            if (!def || (def.value !== 0 && !def.value)) return false
            if (Array.isArray(def.value) && def.value.length === 0) return false
            return true
        })
    }, [filters])

    // Only show active filter pills in the primary bar for primary fields; secondary filters show in "More filters" bar
    const primaryFilterKeys = useMemo(() =>
        new Set((visiblePrimaryFilters || []).map((f) => f.field_key || f.key)),
        [visiblePrimaryFilters]
    )
    const appliedPrimaryFiltersList = useMemo(() =>
        appliedFiltersList.filter(([key]) => primaryFilterKeys.has(key)),
        [appliedFiltersList, primaryFilterKeys]
    )
    
    const getFieldLabel = (fieldKey) => {
        const field = (filterable_schema || []).find(f => (f.field_key || f.key) === fieldKey)
        return field?.display_label || field?.label || fieldKey
    }
    
    // Always render the primary filter container
    if (compact) {
        if (visiblePrimaryFilters.length === 0 && appliedPrimaryFiltersList.length === 0) {
            return null
        }
        
        return (
            <div className="flex items-center flex-wrap gap-2">
                {/* Primary applied filter pills render in "More filters" bar (inline with More filters) */}
                <div className="flex items-center gap-1.5">
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
                                compact={true}
                                labelInDropdown={true}
                                variant="primary"
                            />
                        )
                    })}
                </div>
            </div>
        )
    }
    
    if (visiblePrimaryFilters.length === 0 && appliedPrimaryFiltersList.length === 0) {
        return null
    }
    
    return (
        <div className="bg-white border-b border-gray-200 px-4 py-3 sm:px-6">
            {appliedPrimaryFiltersList.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 mb-2">
                    <span className="text-xs text-gray-500 font-medium">Applied:</span>
                    {appliedPrimaryFiltersList.map(([fieldKey, def]) => {
                        const field = filterable_schema.find((f) => (f.field_key || f.key) === fieldKey)
                        const isBooleanToggle = field && resolve(field, CONTEXT.FILTER) === WIDGET.TOGGLE
                        const rawVal = Array.isArray(def.value) ? def.value[0] : def.value
                        let valueLabel = isBooleanToggle
                            ? (rawVal === true || rawVal === 'true' || rawVal === 1 || rawVal === '1' ? 'Yes' : 'No')
                            : (Array.isArray(def.value) ? (def.value[0] ?? '') : String(def.value ?? ''))
                        let optionColor = null
                        if (field && (field.type === 'select' || field.type === 'multiselect') && field.options?.length) {
                            const opt = field.options.find((o) => String(o.value) === String(rawVal))
                            if (opt) {
                                valueLabel = opt.display_label ?? opt.label ?? valueLabel
                                if (opt.color && /^#[0-9A-Fa-f]{6}$/.test(opt.color)) optionColor = opt.color
                            }
                        }
                        return (
                            <span
                                key={fieldKey}
                                className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded"
                            >
                                {getFieldLabel(fieldKey)}: {optionColor ? (
                                    <span className="inline-flex items-center gap-1">
                                        <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: optionColor }} />
                                        {valueLabel}
                                    </span>
                                ) : valueLabel}
                                <button type="button" onClick={() => handleRemoveFilter(fieldKey)} className="text-indigo-600 hover:text-indigo-800" aria-label={`Remove ${fieldKey} filter`}>
                                    <XMarkIcon className="h-3.5 w-3.5" />
                                </button>
                            </span>
                        )
                    })}
                </div>
            )}
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
                        compact={false}
                        variant="primary"
                    />
                )
                })}
            </div>
        </div>
    )
}
