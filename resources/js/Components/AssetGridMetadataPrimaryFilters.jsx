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
import TagPrimaryFilter from './TagPrimaryFilter'
import DominantColorsFilter from './DominantColorsFilter'
import ColorSwatchFilter from './ColorSwatchFilter'
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
            only: ['assets'],
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
            only: ['assets'],
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
                        const isBooleanToggle = fieldKey === 'starred' || (field?.type === 'boolean' && field?.display_widget === 'toggle')
                        const rawVal = Array.isArray(def.value) ? def.value[0] : def.value
                        const valueLabel = isBooleanToggle
                            ? (rawVal === true || rawVal === 'true' || rawVal === 1 || rawVal === '1' ? 'Yes' : 'No')
                            : (Array.isArray(def.value) ? (def.value[0] ?? '') : String(def.value ?? ''))
                        return (
                            <span
                                key={fieldKey}
                                className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded"
                            >
                                {getFieldLabel(fieldKey)}: {valueLabel}
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
function FilterFieldInput({ field, value, operator, onChange, availableValues = [], compact = false, labelInDropdown = false }) {
    const fieldKey = field.field_key || field.key
    const fieldType = field.type || 'text'
    
    // STEP 5: Dev warning if dominant_color_bucket rendered without color filter_type
    if (fieldKey === 'dominant_color_bucket' && field.filter_type !== 'color') {
        console.error('[AssetGridMetadataPrimaryFilters] dominant_color_bucket rendered without color filter_type', field)
    }
    
    // STEP 2 & 3: Color swatch filters (filter_type === 'color') - no operator dropdown, always equals, OR semantics
    const isColorFilter = field.filter_type === 'color'
    // C9.2: Collection = single select only, no operator dropdown (no "Contains any")
    const isCollectionFilter = fieldKey === 'collection'
    const isToggleBoolean = (fieldKey === 'starred' || field.display_widget === 'toggle') && fieldType === 'boolean'
    const isExpirationDateFilter = fieldKey === 'expiration_date'
    const isTagsFilter = fieldKey === 'tags'

    // Phase J.2.8: Special rendering for tags field (no label)
    if (fieldKey === 'tags') {
        return (
            <div className="flex-shrink-0">
                <FilterValueInput
                    field={field}
                    operator={operator}
                    value={value}
                    onChange={onChange}
                    compact={compact}
                />
            </div>
        )
    }
    
    // Filter options to only show values that exist in available_values
    // This ensures users only see options that actually have matching assets
    const filteredOptions = useMemo(() => {
        if (!field.options || !Array.isArray(field.options)) {
            return null // null means "use all options" (no filtering applied)
        }
        
        if (!availableValues || availableValues.length === 0) {
            return [] // Only available options: none → show no options
        }
        
        // Only show options that exist in available_values (rule: only available options)
        // Match case-insensitively so "Product" / "product" and "Wordmark" / "wordmark" align
        const filtered = field.options.filter(option => {
            const optionValue = option.value
            const optionId = option.option_id
            return availableValues.some(av =>
                String(av).toLowerCase() === String(optionValue).toLowerCase() ||
                (optionId !== undefined && String(av).toLowerCase() === String(optionId).toLowerCase())
            )
        })
        // No fallback to all options: if no match, show no options (only "Any" / placeholder)
        return filtered
    }, [field.options, availableValues, fieldKey])
    
    const handleOperatorChange = (e) => {
        onChange(e.target.value, value)
    }
    
    const handleValueChange = (newValueOrOperator, maybeValue) => {
        if (isTagsFilter && maybeValue !== undefined) {
            onChange(newValueOrOperator, maybeValue)
            return
        }
        if (isColorFilter) {
            if (newValueOrOperator && typeof newValueOrOperator === 'object' && 'operator' in newValueOrOperator && 'value' in newValueOrOperator) {
                onChange(newValueOrOperator.operator, newValueOrOperator.value)
            } else {
                const arrayValue = Array.isArray(newValueOrOperator) ? newValueOrOperator : (newValueOrOperator != null ? [newValueOrOperator] : null)
                onChange('equals', arrayValue)
            }
        } else if (isCollectionFilter) {
            onChange('equals', newValueOrOperator)
        } else if (isToggleBoolean) {
            onChange('equals', newValueOrOperator)
        } else {
            onChange(operator, newValueOrOperator)
        }
    }
    
    // STEP 3: For color filters, hardcode operator to 'equals' and normalize value to array
    // C9.2: Collection = single select, no operator dropdown, always 'equals'
    // Toggle boolean: no operator dropdown
    const effectiveOperator = isColorFilter || isCollectionFilter || isToggleBoolean ? 'equals' : operator
    const effectiveValue = isColorFilter
        ? (Array.isArray(value) ? value : (value != null ? [value] : null))
        : (isCollectionFilter ? (Array.isArray(value) ? value : (value != null ? [value] : null)) : (isToggleBoolean ? value : value))
    
    const displayLabel = field.display_label || field.label || fieldKey

    return (
        <div className="flex-shrink-0">
            <div className="flex items-center gap-1.5">
                {/* STEP 3: Hide operator dropdown for color filters; C9.2: hide for collection; toggle boolean (e.g. Starred) */}
                {!isColorFilter && !isCollectionFilter && !isToggleBoolean && !isExpirationDateFilter && !isTagsFilter && field.operators && field.operators.length > 1 && (
                    <select
                        value={effectiveOperator}
                        onChange={handleOperatorChange}
                        className="flex-shrink-0 px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
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
                    operator={effectiveOperator}
                    value={effectiveValue}
                    filteredOptions={filteredOptions}
                    onChange={handleValueChange}
                    compact={compact}
                    labelInDropdown={labelInDropdown}
                    placeholderLabel={displayLabel}
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
function FilterValueInput({ field, operator, value, onChange, filteredOptions = null, compact = false, labelInDropdown = false, placeholderLabel = null }) {
    const fieldType = field.type || 'text'
    const fieldKey = field.field_key || field.key
    
    // Phase J.2.8: Special handling for tags field
    if (fieldKey === 'tags') {
        // Get tenant ID from page props for tag autocomplete (activeCompany = current tenant)
        const pageProps = usePage().props
        const tenantId = pageProps.tenant?.id || pageProps.auth?.activeCompany?.id || pageProps.auth?.user?.current_tenant_id

        return (
            <TagPrimaryFilter
                value={value}
                onChange={onChange}
                tenantId={tenantId}
                placeholder="Filter by tags..."
                compact={true}
            />
        )
    }

    // Boolean with display_widget=toggle (e.g. Starred) — same layout as upload/edit
    if (fieldKey === 'starred' || (fieldType === 'boolean' && field.display_widget === 'toggle')) {
        const isOn = value === true || value === 'true'
        return (
            <label className="flex items-center gap-2 cursor-pointer flex-shrink-0">
                <span className={`text-xs ${compact ? 'text-gray-600' : 'text-gray-700'}`}>
                    {labelInDropdown && placeholderLabel ? placeholderLabel : (field.display_label || fieldKey)}
                </span>
                <div className="relative inline-flex items-center flex-shrink-0">
                    <input
                        type="checkbox"
                        checked={!!isOn}
                        onChange={(e) => onChange('equals', e.target.checked ? true : null)}
                        className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600" />
                </div>
            </label>
        )
    }

    // C9.2: Collection primary filter = dropdown with collection name (label), not text/number
    if (fieldKey === 'collection') {
        const opts = Array.isArray(filteredOptions) ? filteredOptions : (field.options || [])
        const label = (opt) => opt.display_label ?? opt.label ?? opt.value
        const placeholder = (labelInDropdown && placeholderLabel) ? placeholderLabel : 'Any'
        return (
            <select
                value={Array.isArray(value) ? value[0] ?? '' : (value ?? '')}
                onChange={(e) => {
                    const v = e.target.value
                    onChange(v ? [v] : null)
                }}
                className={`px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${
                    compact ? 'min-w-[80px]' : 'min-w-[100px]'
                }`}
            >
                <option value="">{placeholder}</option>
                {opts.map((option) => (
                    <option key={option.value} value={option.value}>
                        {label(option)}
                    </option>
                ))}
            </select>
        )
    }

    // Expiration date: preset options (Expired, Expires within X days)
    if (fieldKey === 'expiration_date') {
        const today = new Date()
        today.setHours(0, 0, 0, 0)
        const toISO = (d) => d.toISOString().slice(0, 10)
        const addDays = (n) => {
            const d = new Date(today)
            d.setDate(d.getDate() + n)
            return toISO(d)
        }
        const todayStr = toISO(today)
        const presets = [
            { preset: '', label: 'Any' },
            { preset: 'expired', label: 'Expired', operator: 'before', value: todayStr },
            { preset: 'within_7', label: 'Expires within 7 days', operator: 'range', value: [todayStr, addDays(7)] },
            { preset: 'within_30', label: 'Expires within 30 days', operator: 'range', value: [todayStr, addDays(30)] },
            { preset: 'within_60', label: 'Expires within 60 days', operator: 'range', value: [todayStr, addDays(60)] },
            { preset: 'within_90', label: 'Expires within 90 days', operator: 'range', value: [todayStr, addDays(90)] },
        ]
        let currentPreset = ''
        if (operator === 'before' && value === todayStr) currentPreset = 'expired'
        else if (operator === 'range' && Array.isArray(value) && value.length === 2) {
            const end = value[1]
            if (end === addDays(7)) currentPreset = 'within_7'
            else if (end === addDays(30)) currentPreset = 'within_30'
            else if (end === addDays(60)) currentPreset = 'within_60'
            else if (end === addDays(90)) currentPreset = 'within_90'
        }
        const placeholder = (labelInDropdown && placeholderLabel) ? placeholderLabel : 'Any'
        return (
            <select
                value={currentPreset}
                onChange={(e) => {
                    const key = e.target.value
                    if (!key) {
                        onChange('equals', null)
                        return
                    }
                    const presetEntry = presets.find(p => p.preset === key && p.operator)
                    if (presetEntry) onChange(presetEntry.operator, presetEntry.value)
                }}
                className={`px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${compact ? 'min-w-[80px]' : 'min-w-[100px]'}`}
            >
                {presets.map((p) => (
                    <option key={p.preset || 'any'} value={p.preset}>{p.label}</option>
                ))}
            </select>
        )
    }

    // Special handling for dominant_colors field - render color tiles
    if (fieldKey === 'dominant_colors') {
        // Extract color arrays from availableValues
        // availableValues for dominant_colors will be arrays of color objects
        const colorArrays = useMemo(() => {
            if (!availableValues || !Array.isArray(availableValues)) {
                return []
            }
            // availableValues is an array of color arrays: [[{hex, rgb, coverage}, ...], ...]
            return availableValues.filter(Array.isArray)
        }, [availableValues])
        
        return (
            <DominantColorsFilter
                value={value}
                onChange={onChange}
                availableValues={colorArrays}
                compact={compact}
            />
        )
    }

    // Color swatch filter (e.g. dominant_color_bucket): filter_type === 'color', options have swatch hex
    if (field.filter_type === 'color') {
        return (
            <ColorSwatchFilter
                field={field}
                value={value}
                onChange={onChange}
                filteredOptions={filteredOptions}
                compact={compact}
            />
        )
    }
    
    // Only available options: use filteredOptions when array (may be [] or [...])
    const options = Array.isArray(filteredOptions) ? filteredOptions : (field.options || [])
    
    const inputClass = `px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${compact ? 'min-w-[80px]' : 'min-w-[100px]'}`

    switch (fieldType) {
        case 'text':
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className={inputClass}
                    placeholder={placeholderLabel || 'Enter value...'}
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
                            className="px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 w-16 transition-colors"
                            placeholder="Min"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 w-16 transition-colors"
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
                    className={inputClass}
                    placeholder="Enter number..."
                />
            )
        
        case 'boolean': {
            const boolPlaceholder = (labelInDropdown && placeholderLabel) ? placeholderLabel : 'Any'
            return (
                <select
                    value={value === null ? '' : String(value)}
                    onChange={(e) => onChange(e.target.value === 'true' ? true : e.target.value === 'false' ? false : null)}
                    className={`px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${
                        compact ? 'min-w-[80px]' : 'min-w-[100px]'
                    }`}
                >
                    <option value="">{boolPlaceholder}</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                </select>
            )
        }
        
        case 'select':
        case 'rating': {
            const selectPlaceholder = (labelInDropdown && placeholderLabel) ? placeholderLabel : 'Any'
            return (
                <select
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className={`px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${
                        compact ? 'min-w-[80px]' : 'min-w-[100px]'
                    }`}
                >
                    <option value="">{selectPlaceholder}</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label ?? option.label ?? option.value}
                        </option>
                    ))}
                </select>
            )
        }

        case 'multiselect':
            return (
                <select
                    multiple
                    value={Array.isArray(value) ? value : []}
                    onChange={(e) => {
                        const selected = Array.from(e.target.selectedOptions, (opt) => opt.value)
                        onChange(selected.length > 0 ? selected : null)
                    }}
                    className={`px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${
                        compact ? 'min-w-[80px]' : 'min-w-[100px]'
                    }`}
                    size={Math.min((options?.length || 0) + 1, 5)}
                >
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label ?? option.label ?? option.value}
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
                            className="px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                        />
                    </div>
                )
            }
            return (
                <input
                    type="date"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className={inputClass}
                />
            )
        
        default:
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className={inputClass}
                    placeholder={placeholderLabel || 'Enter value...'}
                />
            )
    }
}
