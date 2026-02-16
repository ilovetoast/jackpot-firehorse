/**
 * Filter Field Input Component
 *
 * Shared filter input for metadata fields. Uses WidgetResolver for centralized
 * widget rendering logic. Used by AssetGridMetadataPrimaryFilters and AssetGridSecondaryFilters.
 *
 * @module FilterFieldInput
 */

import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import TagPrimaryFilter from './TagPrimaryFilter'
import DominantColorsFilter from './DominantColorsFilter'
import ColorSwatchFilter from './ColorSwatchFilter'
import { OptionChipSelect } from './OptionChipSelect'
import { resolve, CONTEXT, WIDGET } from '../utils/widgetResolver'

/**
 * Filter options to only show values that exist in available_values
 */
function useFilteredOptions(field, availableValues, fieldKey) {
    return useMemo(() => {
        if (!field?.options || !Array.isArray(field.options)) return null
        if (!availableValues || availableValues.length === 0) return []
        return field.options.filter((option) => {
            const optionValue = option.value
            const optionId = option.option_id
            return availableValues.some(
                (av) =>
                    String(av).toLowerCase() === String(optionValue).toLowerCase() ||
                    (optionId !== undefined && String(av).toLowerCase() === String(optionId).toLowerCase())
            )
        })
    }, [field?.options, availableValues, fieldKey])
}

/**
 * FilterFieldInput - Wrapper that handles operator dropdown and value input
 */
export function FilterFieldInput({
    field,
    value,
    operator,
    onChange,
    availableValues = [],
    compact = false,
    labelInDropdown = false,
    variant = 'primary', // 'primary' | 'secondary'
}) {
    const fieldKey = field?.field_key || field?.key
    const widget = resolve(field, CONTEXT.FILTER)

    const isColorFilter = widget === WIDGET.COLOR_SWATCH
    const isCollectionFilter = widget === WIDGET.COLLECTION_BADGES
    const isToggleBoolean = widget === WIDGET.TOGGLE
    const isTagsFilter = widget === WIDGET.TAG_MANAGER
    const isExpirationDateFilter = widget === WIDGET.EXPIRATION_DATE

    const filteredOptions = useFilteredOptions(field, availableValues, fieldKey)

    const handleOperatorChange = (e) => onChange(e.target.value, value)

    const handleValueChange = (newValueOrOperator, maybeValue) => {
        // Tags: TagPrimaryFilter passes (operator, value) e.g. ('in', tagIds)
        if (isTagsFilter) {
            onChange(newValueOrOperator, maybeValue ?? null)
            return
        }
        if (isColorFilter) {
            if (newValueOrOperator && typeof newValueOrOperator === 'object' && 'operator' in newValueOrOperator && 'value' in newValueOrOperator) {
                onChange(newValueOrOperator.operator, newValueOrOperator.value)
            } else {
                const arrayValue = Array.isArray(newValueOrOperator) ? newValueOrOperator : newValueOrOperator != null ? [newValueOrOperator] : null
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

    const effectiveOperator = isColorFilter || isCollectionFilter || isToggleBoolean ? 'equals' : operator
    const effectiveValue = isColorFilter
        ? Array.isArray(value) ? value : value != null ? [value] : null
        : isCollectionFilter
          ? Array.isArray(value) ? value : value != null ? [value] : null
          : isToggleBoolean
            ? value
            : value

    const displayLabel = field?.display_label || field?.label || fieldKey

    // Tags: no label, just the input (primary style)
    if (isTagsFilter) {
        return (
            <div className="flex-shrink-0">
                <FilterValueInput
                    field={field}
                    operator={effectiveOperator}
                    value={effectiveValue}
                    onChange={handleValueChange}
                    filteredOptions={filteredOptions}
                    availableValues={availableValues}
                    compact={compact}
                    labelInDropdown={labelInDropdown}
                    placeholderLabel={displayLabel}
                    variant={variant}
                />
            </div>
        )
    }

    // Secondary: label above, different layout
    if (variant === 'secondary') {
        return (
            <div className="space-y-1">
                <label className="block text-xs font-medium text-gray-700">{displayLabel}</label>
                <div className="flex items-center gap-2">
                    {!isColorFilter && !isCollectionFilter && !isToggleBoolean && !isExpirationDateFilter && field?.operators && field.operators.length > 1 && (
                        <select
                            value={effectiveOperator}
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
                        operator={effectiveOperator}
                        value={effectiveValue}
                        onChange={handleValueChange}
                        filteredOptions={filteredOptions}
                        availableValues={availableValues}
                        compact={compact}
                        variant={variant}
                    />
                </div>
            </div>
        )
    }

    // Primary: inline, operator + value
    return (
        <div className="flex-shrink-0">
            <div className="flex items-center gap-1.5">
                {!isColorFilter && !isCollectionFilter && !isToggleBoolean && !isExpirationDateFilter && !isTagsFilter && field?.operators && field.operators.length > 1 && (
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
                    onChange={handleValueChange}
                    filteredOptions={filteredOptions}
                    availableValues={availableValues}
                    compact={compact}
                    labelInDropdown={labelInDropdown}
                    placeholderLabel={displayLabel}
                    variant={variant}
                />
            </div>
        </div>
    )
}

/**
 * FilterValueInput - Renders the appropriate input based on WidgetResolver
 */
export function FilterValueInput({
    field,
    operator,
    value,
    onChange,
    filteredOptions = null,
    availableValues = [],
    compact = false,
    labelInDropdown = false,
    placeholderLabel = null,
    variant = 'primary',
}) {
    const widget = resolve(field, CONTEXT.FILTER)
    const fieldKey = field?.field_key || field?.key
    const fieldType = field?.type || 'text'
    const pageProps = usePage().props
    const tenantId = pageProps.tenant?.id || pageProps.auth?.activeCompany?.id || pageProps.auth?.user?.current_tenant_id

    const inputClass = variant === 'primary'
        ? `px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${compact ? 'min-w-[80px]' : 'min-w-[100px]'}`
        : 'flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500'

    // Tag filter
    if (widget === WIDGET.TAG_MANAGER) {
        return (
            <TagPrimaryFilter
                value={Array.isArray(value) ? value : value ? [value] : []}
                onChange={onChange}
                tenantId={tenantId}
                placeholder={variant === 'primary' ? 'Filter by tags...' : 'Search...'}
                compact={true}
                fullWidth={variant === 'secondary'}
            />
        )
    }

    // Toggle (starred, boolean with display_widget=toggle)
    if (widget === WIDGET.TOGGLE) {
        const isOn = value === true || value === 'true'
        return (
            <label className={`flex items-center gap-2 cursor-pointer ${variant === 'primary' ? 'flex-shrink-0' : ''}`}>
                {variant === 'primary' && (
                    <span className={`text-xs ${compact ? 'text-gray-600' : 'text-gray-700'}`}>
                        {labelInDropdown && placeholderLabel ? placeholderLabel : field?.display_label || fieldKey}
                    </span>
                )}
                <div className="relative inline-flex items-center flex-shrink-0">
                    <input
                        type="checkbox"
                        checked={!!isOn}
                        onChange={(e) => onChange('equals', e.target.checked ? true : null)}
                        className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600" />
                </div>
                {variant === 'secondary' && <span className="text-xs text-gray-600">{isOn ? 'Yes' : 'Any'}</span>}
            </label>
        )
    }

    // Collection dropdown (COLLECTION_BADGES in filter context)
    if (widget === WIDGET.COLLECTION_BADGES) {
        const opts = Array.isArray(filteredOptions) ? filteredOptions : field?.options || []
        const label = (opt) => opt.display_label ?? opt.label ?? opt.value
        const placeholder = labelInDropdown && placeholderLabel ? placeholderLabel : 'Any'
        return (
            <select
                value={Array.isArray(value) ? value[0] ?? '' : value ?? ''}
                onChange={(e) => {
                    const v = e.target.value
                    onChange(v ? [v] : null)
                }}
                className={variant === 'primary' ? `px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${compact ? 'min-w-[80px]' : 'min-w-[100px]'}` : 'flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500'}
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

    // Expiration date presets
    if (widget === WIDGET.EXPIRATION_DATE) {
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
        const placeholder = labelInDropdown && placeholderLabel ? placeholderLabel : 'Any'
        return (
            <select
                value={currentPreset}
                onChange={(e) => {
                    const key = e.target.value
                    if (!key) {
                        onChange('equals', null)
                        return
                    }
                    const presetEntry = presets.find((p) => p.preset === key && p.operator)
                    if (presetEntry) onChange(presetEntry.operator, presetEntry.value)
                }}
                className={variant === 'primary' ? `px-2 py-1 text-xs border border-gray-300 rounded shadow-sm focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-colors ${compact ? 'min-w-[80px]' : 'min-w-[100px]'}` : 'flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500'}
            >
                {presets.map((p) => (
                    <option key={p.preset || 'any'} value={p.preset}>
                        {p.label}
                    </option>
                ))}
            </select>
        )
    }

    // Dominant colors filter (DOMINANT_COLORS in filter context)
    if (widget === WIDGET.DOMINANT_COLORS) {
        const colorArrays = variant === 'primary' && availableValues?.length
            ? availableValues.filter(Array.isArray)
            : availableValues
        return (
            <DominantColorsFilter
                value={value}
                onChange={onChange}
                availableValues={colorArrays}
                compact={compact || variant === 'secondary'}
            />
        )
    }

    // Color swatch filter (dominant_color_bucket)
    if (widget === WIDGET.COLOR_SWATCH) {
        return (
            <ColorSwatchFilter
                field={field}
                value={value}
                onChange={onChange}
                filteredOptions={filteredOptions}
                compact={compact || variant === 'secondary'}
            />
        )
    }

    // Generic inputs by type
    const options = Array.isArray(filteredOptions) ? filteredOptions : field?.options || []

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
            const boolPlaceholder = labelInDropdown && placeholderLabel ? placeholderLabel : 'Any'
            return (
                <select
                    value={value === null ? '' : String(value)}
                    onChange={(e) => onChange(e.target.value === 'true' ? true : e.target.value === 'false' ? false : null)}
                    className={inputClass}
                >
                    <option value="">{boolPlaceholder}</option>
                    <option value="true">Yes</option>
                    <option value="false">No</option>
                </select>
            )
        }
        case 'select':
        case 'rating': {
            const selectPlaceholder = labelInDropdown && placeholderLabel ? placeholderLabel : 'Any'
            return (
                <OptionChipSelect
                    options={options}
                    value={value ?? ''}
                    onChange={onChange}
                    placeholder={selectPlaceholder}
                    multiple={false}
                    className={inputClass}
                />
            )
        }
        case 'multiselect':
            return (
                <OptionChipSelect
                    options={options}
                    value={Array.isArray(value) ? value : []}
                    onChange={onChange}
                    placeholder="Any"
                    multiple={true}
                    size={Math.min((options?.length || 0) + 1, 5)}
                    className={inputClass}
                />
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
