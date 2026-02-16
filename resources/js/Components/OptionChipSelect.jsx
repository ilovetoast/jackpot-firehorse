/**
 * Option Chip Select
 * Custom dropdown for select/multiselect filters that displays color chips when options have color.
 * Falls back to native select when no options have color (backward compatible).
 */

import { useState, useRef, useEffect } from 'react'
import { ChevronDownIcon } from '@heroicons/react/24/outline'
import { OptionIcon } from './OptionIconSelector'

/**
 * Check if any option has a color
 */
function hasOptionsWithColor(options) {
    return options?.some((o) => o?.color && /^#[0-9A-Fa-f]{6}$/.test(o.color))
}

function getLabel(opt) {
    return opt?.display_label ?? opt?.label ?? opt?.value ?? ''
}

/**
 * Single-select with optional color chips
 */
export function OptionChipSelect({
    options = [],
    value,
    onChange,
    placeholder = 'Any',
    multiple = false,
    size,
    className = '',
}) {
    const hasColor = hasOptionsWithColor(options)
    const [open, setOpen] = useState(false)
    const ref = useRef(null)

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false)
        }
        if (open) document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [open])

    const selectedValues = multiple ? (Array.isArray(value) ? value : value != null ? [value] : []) : []
    const selectedOpts = options.filter((o) =>
        multiple ? selectedValues.includes(o.value) : o.value === value
    )
    const displayLabel = multiple
        ? selectedOpts.length > 0
            ? selectedOpts.map(getLabel).join(', ')
            : placeholder
        : selectedOpts[0] ? getLabel(selectedOpts[0]) : placeholder

    if (!hasColor) {
        if (multiple) {
            return (
                <select
                    multiple
                    value={value ?? []}
                    onChange={(e) => {
                        const selected = Array.from(e.target.selectedOptions, (o) => o.value)
                        onChange(selected.length > 0 ? selected : null)
                    }}
                    className={className}
                    size={size ?? Math.min((options?.length || 0) + 1, 5)}
                >
                    {options.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                            {getLabel(opt)}
                        </option>
                    ))}
                </select>
            )
        }
        return (
            <select
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value || null)}
                className={className}
            >
                <option value="">{placeholder}</option>
                {options.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                        {getLabel(opt)}
                    </option>
                ))}
            </select>
        )
    }

    // Custom dropdown with color chips
    const toggleOption = (optValue) => {
        if (multiple) {
            const next = selectedValues.includes(optValue)
                ? selectedValues.filter((v) => v !== optValue)
                : [...selectedValues, optValue]
            onChange(next.length > 0 ? next : null)
        } else {
            onChange(optValue === value ? null : optValue)
            setOpen(false)
        }
    }

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className={`w-full text-left flex items-center gap-2 min-w-0 ${className}`}
            >
                <span className="flex-1 truncate flex items-center gap-1.5">
                    {selectedOpts.length > 0 ? (
                        selectedOpts.map((opt) => (
                            <span
                                key={opt.value}
                                className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium"
                                style={
                                    opt.color
                                        ? { backgroundColor: `${opt.color}20`, color: opt.color }
                                        : { backgroundColor: '#f3f4f6', color: '#374151' }
                                }
                            >
                                {opt.color && (
                                    <span
                                        className="w-2 h-2 rounded-full flex-shrink-0"
                                        style={{ backgroundColor: opt.color }}
                                    />
                                )}
                                {opt.icon && <OptionIcon icon={opt.icon} className="h-3 w-3" />}
                                {getLabel(opt)}
                            </span>
                        ))
                    ) : (
                        <span className="text-gray-500">{placeholder}</span>
                    )}
                </span>
                <ChevronDownIcon
                    className={`h-4 w-4 flex-shrink-0 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && (
                <div className="absolute z-10 mt-1 w-full rounded-md bg-white shadow-lg border border-gray-200 py-1 max-h-48 overflow-auto">
                    {options.map((opt) => {
                        const isSelected = multiple
                            ? selectedValues.includes(opt.value)
                            : opt.value === value
                        return (
                            <button
                                key={opt.value}
                                type="button"
                                onClick={() => toggleOption(opt.value)}
                                className={`w-full text-left px-2 py-1.5 flex items-center gap-2 hover:bg-gray-50 text-sm ${
                                    isSelected ? 'bg-indigo-50 text-indigo-700' : 'text-gray-900'
                                }`}
                            >
                                {opt.color && (
                                    <span
                                        className="w-3 h-3 rounded-full flex-shrink-0 border border-gray-200"
                                        style={{ backgroundColor: opt.color }}
                                    />
                                )}
                                {opt.icon && <OptionIcon icon={opt.icon} className="h-3.5 w-3.5" />}
                                <span className="truncate">{getLabel(opt)}</span>
                            </button>
                        )
                    })}
                </div>
            )}
        </div>
    )
}
