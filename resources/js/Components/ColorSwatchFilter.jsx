/**
 * Color Swatch Filter Component
 *
 * Renders selectable color swatches for filter_type === 'color' (e.g. dominant_color_bucket).
 * Options come from field.options with { value, label, swatch }.
 * Multi-select allowed (future-safe); single value also supported.
 */

import { useEffect, useMemo } from 'react'

export default function ColorSwatchFilter({
    field,
    value,
    onChange,
    filteredOptions = null,
    compact = false,
}) {
    const options = useMemo(() => {
        const list = (filteredOptions != null && filteredOptions.length > 0)
            ? filteredOptions
            : (field.options || [])
        return list.filter((opt) => opt && (opt.swatch || opt.value))
    }, [field.options, filteredOptions])

    const selectedValues = Array.isArray(value) ? value : (value != null ? [value] : [])

    const fieldKey = field?.key ?? field?.field_key
    useEffect(() => {
        if (fieldKey === 'dominant_color_bucket' && !Array.isArray(value)) {
            console.error(
                'dominant_color_bucket value must be an array',
                value
            )
        }
    }, [fieldKey, value])

    const handleToggle = (bucketValue) => {
        let nextValues = Array.isArray(value) ? [...value] : (value != null ? [value] : [])

        if (nextValues.includes(bucketValue)) {
            nextValues = nextValues.filter(v => v !== bucketValue)
        } else {
            nextValues.push(bucketValue)
        }

        onChange({
            operator: 'equals',
            value: nextValues,
        })
    }

    if (options.length === 0) {
        return null
    }

    const sizeClass = compact ? 'w-4 h-4' : 'w-6 h-6'
    const containerClass = compact
        ? 'flex flex-wrap items-center gap-1'
        : 'flex flex-wrap items-center gap-1.5 p-2 bg-gray-50 rounded-lg border border-gray-200'

    return (
        <div className={containerClass}>
            {options.map((option) => {
                const optValue = option.value
                const hex = option.swatch || option.hex || '#808080'
                const isSelected = selectedValues.includes(optValue)

                return (
                    <button
                        key={optValue}
                        type="button"
                        onClick={() => handleToggle(optValue)}
                        className={`
                            ${sizeClass} rounded-sm border-2 flex-shrink-0
                            transition-all hover:scale-110 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1
                            ${isSelected
                                ? 'border-indigo-600 ring-2 ring-indigo-200 shadow-md'
                                : 'border-gray-300 hover:border-gray-400'
                            }
                        `}
                        style={{ backgroundColor: hex }}
                        title={option.label || optValue}
                        aria-label={`${isSelected ? 'Deselect' : 'Select'} color ${option.label || optValue}`}
                    >
                        {isSelected && (
                            <svg
                                className="w-full h-full text-white drop-shadow-md"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={compact ? 2.5 : 3}
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        )}
                    </button>
                )
            })}
        </div>
    )
}
