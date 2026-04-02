/**
 * Color Swatch Filter Component
 *
 * Renders selectable color swatches for filter_type === 'color' (e.g. dominant_hue_group).
 * Options come from field.options with { value, label, swatch }.
 * Multi-select allowed (future-safe); single value also supported.
 */

import { useMemo } from 'react'

export default function ColorSwatchFilter({
    field,
    value,
    onChange,
    filteredOptions = null,
    compact = false,
    /** One horizontal scroller (More filters panel) — avoids swatches wrapping under the label. */
    singleRow = false,
}) {
    const options = useMemo(() => {
        const list = (filteredOptions != null && filteredOptions.length > 0)
            ? filteredOptions
            : (field.options || [])
        return list.filter((opt) => opt && (opt.swatch || opt.value))
    }, [field.options, filteredOptions])

    // Group by row_group (1=Warm, 2=Cool, 3=Earth, 4=Neutrals) for visual clarity
    const groupedOptions = useMemo(() => {
        const groups = {}
        for (const opt of options) {
            const rg = opt.row_group ?? 4
            if (!groups[rg]) groups[rg] = []
            groups[rg].push(opt)
        }
        const order = [1, 2, 3, 4]
        return order.filter((rg) => groups[rg]?.length).map((rg) => groups[rg])
    }, [options])

    const selectedValues = Array.isArray(value) ? [...new Set(value)] : (value != null ? [value] : [])

    const handleToggle = (bucketValue) => {
        let nextValues = Array.isArray(value) ? [...new Set(value)] : (value != null ? [value] : [])

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
        ? 'flex flex-col gap-1'
        : 'flex flex-col gap-2 p-2 bg-gray-50 rounded-lg border border-gray-200'
    const rowClass = compact ? 'flex flex-wrap items-center gap-1' : 'flex flex-wrap items-center gap-1.5'

    const renderSwatch = (option) => {
        const optValue = option.value
        const hex = option.swatch || option.hex || '#808080'
        const isSelected = selectedValues.includes(optValue)
        const label = option.label || optValue
        const tooltip = option.tooltip || label
        const count = option.count
        const focusRing = singleRow
            ? 'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500'
            : 'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1'

        return (
            <div key={optValue} className={`flex flex-col items-center gap-0.5 ${singleRow ? 'shrink-0' : ''}`}>
                <button
                    type="button"
                    onClick={() => handleToggle(optValue)}
                    className={`
                        ${sizeClass} rounded-sm border-2 flex-shrink-0
                        transition-all hover:scale-110
                        ${focusRing}
                        ${isSelected
                            ? 'border-indigo-600 ring-2 ring-indigo-200 shadow-md'
                            : 'border-gray-300 hover:border-gray-400'
                        }
                    `}
                    style={{ backgroundColor: hex }}
                    title={tooltip}
                    aria-label={`${isSelected ? 'Deselect' : 'Select'} color ${label}`}
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
                {!compact && !singleRow && count != null && count > 0 && (
                    <span className="text-[10px] text-gray-500 font-medium tabular-nums">
                        ({count})
                    </span>
                )}
            </div>
        )
    }

    if (singleRow) {
        return (
            <div className="flex max-w-full flex-nowrap items-center gap-1.5 overflow-x-auto pb-0.5 [scrollbar-width:thin]">
                {options.map((option) => renderSwatch(option))}
            </div>
        )
    }

    return (
        <div className={containerClass}>
            {groupedOptions.map((rowOpts, rowIdx) => (
                <div key={rowIdx} className={rowClass}>
                    {rowOpts.map((option) => renderSwatch(option))}
                </div>
            ))}
        </div>
    )
}
