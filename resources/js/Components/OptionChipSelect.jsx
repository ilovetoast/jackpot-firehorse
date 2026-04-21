/**
 * Option Chip Select
 * Custom dropdown for select/multiselect filters that displays color chips when options have color.
 * Multiselect always uses a compact dropdown + checkboxes (no native <select multiple>).
 */

import { useState, useRef, useEffect } from 'react'
import { ChevronDownIcon } from '@heroicons/react/24/outline'
import { OptionIcon } from './OptionIconSelector'

/**
 * Check if any option has a color
 */
export function hasOptionsWithColor(options) {
    return options?.some((o) => o?.color && /^#[0-9A-Fa-f]{6}$/.test(o.color))
}

function getLabel(opt) {
    return opt?.display_label ?? opt?.label ?? opt?.value ?? ''
}

/**
 * Single-select with optional color chips; multiselect = dropdown + checkboxes (Tailwind, matches metadata modals).
 */
export function OptionChipSelect({
    options = [],
    value,
    onChange,
    placeholder = 'Any',
    multiple = false,
    size: _sizeIgnored,
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
        : selectedOpts[0]
          ? getLabel(selectedOpts[0])
          : placeholder

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

    // Single, no color chips — native select (compact row height)
    if (!hasColor && !multiple) {
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

    // Multiselect (any) or single with color — custom dropdown
    return (
        <div ref={ref} className="relative min-w-0">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                aria-haspopup="listbox"
                className={`flex w-full min-w-0 max-w-[22rem] items-center justify-between gap-2 text-left ${className}`}
            >
                <span className="min-w-0 flex-1 truncate">
                    {multiple ? (
                        selectedOpts.length > 0 ? (
                            <span className="text-slate-800" title={selectedOpts.map(getLabel).join(', ')}>
                                {displayLabel}
                            </span>
                        ) : (
                            <span className="font-normal text-slate-500">{placeholder}</span>
                        )
                    ) : selectedOpts.length > 0 ? (
                        <span className="flex flex-wrap items-center gap-1">
                            {selectedOpts.map((opt) => (
                                <span
                                    key={opt.value}
                                    className="inline-flex max-w-full items-center gap-1 truncate rounded px-1.5 py-0.5 text-xs font-medium"
                                    style={
                                        opt.color
                                            ? { backgroundColor: `${opt.color}20`, color: opt.color }
                                            : { backgroundColor: '#f3f4f6', color: '#374151' }
                                    }
                                >
                                    {opt.color && (
                                        <span
                                            className="h-2 w-2 shrink-0 rounded-full"
                                            style={{ backgroundColor: opt.color }}
                                        />
                                    )}
                                    {opt.icon && <OptionIcon icon={opt.icon} className="h-3 w-3 shrink-0" />}
                                    <span className="truncate">{getLabel(opt)}</span>
                                </span>
                            ))}
                        </span>
                    ) : (
                        <span className="font-normal text-slate-500">{placeholder}</span>
                    )}
                </span>
                <ChevronDownIcon
                    className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`}
                    aria-hidden
                />
            </button>
            {open && (
                <div
                    className="absolute left-0 z-[100] mt-1 max-h-56 w-full min-w-[12rem] max-w-[min(22rem,100vw-2rem)] overflow-y-auto rounded-lg border border-slate-200 bg-gradient-to-b from-white to-slate-50/90 py-1 shadow-lg"
                    role="listbox"
                    aria-multiselectable={multiple}
                >
                    {options.map((opt) => {
                        const isSelected = multiple
                            ? selectedValues.includes(opt.value)
                            : opt.value === value

                        if (multiple) {
                            return (
                                <label
                                    key={opt.value}
                                    className={`flex cursor-pointer items-start gap-2.5 px-2.5 py-2 text-sm transition-colors ${
                                        isSelected ? 'bg-indigo-50/80' : 'hover:bg-white'
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={isSelected}
                                        onChange={() => toggleOption(opt.value)}
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    {opt.color && (
                                        <span
                                            className="mt-1 h-3 w-3 shrink-0 rounded-full border border-slate-200"
                                            style={{ backgroundColor: opt.color }}
                                        />
                                    )}
                                    {opt.icon && <OptionIcon icon={opt.icon} className="mt-0.5 h-3.5 w-3.5 shrink-0" />}
                                    <span className="min-w-0 flex-1 leading-snug text-slate-800">{getLabel(opt)}</span>
                                </label>
                            )
                        }

                        return (
                            <button
                                key={opt.value}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                onClick={() => toggleOption(opt.value)}
                                className={`flex w-full items-center gap-2 px-2.5 py-2 text-left text-sm transition-colors ${
                                    isSelected ? 'bg-indigo-50 text-indigo-800' : 'text-slate-900 hover:bg-slate-50'
                                }`}
                            >
                                {opt.color && (
                                    <span
                                        className="h-3 w-3 shrink-0 rounded-full border border-slate-200"
                                        style={{ backgroundColor: opt.color }}
                                    />
                                )}
                                {opt.icon && <OptionIcon icon={opt.icon} className="h-3.5 w-3.5 shrink-0" />}
                                <span className="min-w-0 flex-1 truncate">{getLabel(opt)}</span>
                            </button>
                        )
                    })}
                </div>
            )}
        </div>
    )
}
