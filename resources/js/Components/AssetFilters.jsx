/**
 * Asset Filters Component
 *
 * Phase 2 – Step 8: Dynamic metadata-based filtering for asset grid.
 */

import { useState, useEffect } from 'react'
import { XMarkIcon, FunnelIcon, BookmarkIcon } from '@heroicons/react/24/outline'
import { router } from '@inertiajs/react'

export default function AssetFilters({ filterableSchema = [], categoryId, savedViews = [] }) {
    const [filters, setFilters] = useState({})
    const [isOpen, setIsOpen] = useState(false)
    const [savingView, setSavingView] = useState(false)
    const [viewName, setViewName] = useState('')

    // Load filters from URL params
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search)
        const filtersParam = urlParams.get('filters')
        if (filtersParam) {
            try {
                setFilters(JSON.parse(decodeURIComponent(filtersParam)))
            } catch (e) {
                console.error('[AssetFilters] Failed to parse filters from URL', e)
            }
        }
    }, [])

    // Apply filters to URL and reload
    const applyFilters = () => {
        const params = new URLSearchParams(window.location.search)
        
        // Remove existing filters param
        params.delete('filters')
        
        // Add new filters if any
        const activeFilters = Object.entries(filters).filter(([_, filter]) => {
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
            params.set('filters', JSON.stringify(filtersObj))
        }

        // Reload with new filters
        router.get(window.location.pathname, Object.fromEntries(params), {
            preserveState: true,
            preserveScroll: true,
        })
    }

    // Clear all filters
    const clearFilters = () => {
        setFilters({})
        const params = new URLSearchParams(window.location.search)
        params.delete('filters')
        router.get(window.location.pathname, Object.fromEntries(params), {
            preserveState: true,
            preserveScroll: true,
        })
    }

    // Update filter value
    const updateFilter = (fieldKey, operator, value) => {
        setFilters((prev) => ({
            ...prev,
            [fieldKey]: { operator, value },
        }))
    }

    // Remove filter
    const removeFilter = (fieldKey) => {
        setFilters((prev) => {
            const next = { ...prev }
            delete next[fieldKey]
            return next
        })
    }

    // Save current filters as view
    const saveView = async () => {
        if (!viewName.trim()) {
            alert('Please enter a name for this view')
            return
        }

        setSavingView(true)
        try {
            const response = await fetch('/app/assets/metadata/saved-views', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: viewName,
                    filters: filters,
                    category_id: categoryId,
                }),
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to save view')
            }

            setViewName('')
            // Reload saved views
            window.location.reload()
        } catch (err) {
            console.error('[AssetFilters] Failed to save view', err)
            alert(err.message || 'Failed to save view')
        } finally {
            setSavingView(false)
        }
    }

    // Load saved view
    const loadView = (view) => {
        setFilters(view.filters || {})
        applyFilters()
    }

    // Delete saved view
    const deleteView = async (viewId) => {
        if (!confirm('Are you sure you want to delete this saved view?')) {
            return
        }

        try {
            const response = await fetch(`/app/assets/metadata/saved-views/${viewId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                throw new Error('Failed to delete view')
            }

            // Reload saved views
            window.location.reload()
        } catch (err) {
            console.error('[AssetFilters] Failed to delete view', err)
            alert('Failed to delete view')
        }
    }

    // Count active filters
    const activeFilterCount = Object.values(filters).filter(
        (f) => f && f.value !== null && f.value !== '' && (!Array.isArray(f.value) || f.value.length > 0)
    ).length

    if (filterableSchema.length === 0) {
        return null // Hide if no filterable fields
    }

    return (
        <div className="relative">
            {/* Filter Toggle Button */}
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                <FunnelIcon className="h-4 w-4" />
                Filters
                {activeFilterCount > 0 && (
                    <span className="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-medium text-white bg-indigo-600 rounded-full">
                        {activeFilterCount}
                    </span>
                )}
            </button>

            {/* Filter Panel */}
            {isOpen && (
                <div className="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 z-50 max-h-[80vh] overflow-y-auto">
                    <div className="p-4">
                        {/* Header */}
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-sm font-semibold text-gray-900">Filters</h3>
                            <button
                                type="button"
                                onClick={() => setIsOpen(false)}
                                className="text-gray-400 hover:text-gray-500"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>

                        {/* Active Filters */}
                        {activeFilterCount > 0 && (
                            <div className="mb-4">
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-xs font-medium text-gray-700">Active Filters</span>
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="text-xs text-indigo-600 hover:text-indigo-700"
                                    >
                                        Clear All
                                    </button>
                                </div>
                                <div className="space-y-1">
                                    {Object.entries(filters).map(([fieldKey, filter]) => {
                                        if (!filter || filter.value === null || filter.value === '') {
                                            return null
                                        }
                                        const field = filterableSchema.find((f) => f.field_key === fieldKey)
                                        if (!field) return null

                                        return (
                                            <div
                                                key={fieldKey}
                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded"
                                            >
                                                <span>{field.display_label}: {String(filter.value)}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeFilter(fieldKey)}
                                                    className="text-indigo-600 hover:text-indigo-800"
                                                >
                                                    <XMarkIcon className="h-3 w-3" />
                                                </button>
                                            </div>
                                        )
                                    })}
                                </div>
                            </div>
                        )}

                        {/* Filter Fields */}
                        <div className="space-y-4 mb-4">
                            {filterableSchema.map((field) => (
                                <FilterFieldInput
                                    key={field.field_id}
                                    field={field}
                                    value={filters[field.field_key]?.value ?? null}
                                    operator={filters[field.field_key]?.operator ?? field.operators[0]?.value}
                                    onChange={(operator, value) => updateFilter(field.field_key, operator, value)}
                                />
                            ))}
                        </div>

                        {/* Actions */}
                        <div className="flex items-center gap-2 mb-4">
                            <button
                                type="button"
                                onClick={applyFilters}
                                className="flex-1 px-3 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Apply Filters
                            </button>
                            {activeFilterCount > 0 && (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    Clear
                                </button>
                            )}
                        </div>

                        {/* Saved Views */}
                        <div className="border-t border-gray-200 pt-4">
                            <div className="flex items-center justify-between mb-2">
                                <h4 className="text-xs font-medium text-gray-700">Saved Views</h4>
                                <button
                                    type="button"
                                    onClick={saveView}
                                    disabled={savingView || activeFilterCount === 0}
                                    className="text-xs text-indigo-600 hover:text-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {savingView ? 'Saving...' : 'Save Current'}
                                </button>
                            </div>
                            {activeFilterCount > 0 && (
                                <div className="mb-2">
                                    <input
                                        type="text"
                                        value={viewName}
                                        onChange={(e) => setViewName(e.target.value)}
                                        placeholder="View name..."
                                        className="block w-full px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                                    />
                                </div>
                            )}
                            <div className="space-y-1">
                                {savedViews.length === 0 ? (
                                    <div className="text-xs text-gray-500">No saved views</div>
                                ) : (
                                    savedViews.map((view) => (
                                        <div
                                            key={view.id}
                                            className="flex items-center justify-between px-2 py-1 text-xs hover:bg-gray-50 rounded"
                                        >
                                            <button
                                                type="button"
                                                onClick={() => loadView(view)}
                                                className="flex items-center gap-1 text-gray-700 hover:text-indigo-600"
                                            >
                                                <BookmarkIcon className="h-3 w-3" />
                                                {view.name}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => deleteView(view.id)}
                                                className="text-gray-400 hover:text-red-600"
                                            >
                                                <XMarkIcon className="h-3 w-3" />
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}

/**
 * Filter Field Input Component
 */
function FilterFieldInput({ field, value, operator, onChange }) {
    const handleOperatorChange = (e) => {
        onChange(e.target.value, value)
    }

    const handleValueChange = (newValue) => {
        onChange(operator, newValue)
    }

    return (
        <div className="space-y-1">
            <label className="block text-xs font-medium text-gray-700">
                {field.display_label}
            </label>
            <div className="flex items-center gap-2">
                {field.operators.length > 1 && (
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
                    onChange={handleValueChange}
                />
            </div>
        </div>
    )
}

/**
 * Filter Value Input Component
 */
function FilterValueInput({ field, operator, value, onChange }) {
    switch (field.type) {
        case 'text':
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter value..."
                />
            )

        case 'number':
            if (operator === 'range') {
                return (
                    <div className="flex items-center gap-1 flex-1">
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[0] : ''}
                            onChange={(e) => onChange([e.target.value || null, Array.isArray(value) ? value[1] : null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="Min"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="number"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
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
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter number..."
                />
            )

        case 'boolean':
            return (
                <select
                    value={value === null ? '' : String(value)}
                    onChange={(e) => onChange(e.target.value === 'true' ? true : e.target.value === 'false' ? false : null)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
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
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="">Any</option>
                    {field.options.map((option) => (
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
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    size={Math.min(field.options.length + 1, 5)}
                >
                    {field.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.display_label || option.value}
                        </option>
                    ))}
                </select>
            )

        case 'date':
            if (operator === 'range') {
                return (
                    <div className="flex items-center gap-1 flex-1">
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[0] : ''}
                            onChange={(e) => onChange([e.target.value || null, Array.isArray(value) ? value[1] : null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        />
                        <span className="text-xs text-gray-500">-</span>
                        <input
                            type="date"
                            value={Array.isArray(value) ? value[1] : ''}
                            onChange={(e) => onChange([Array.isArray(value) ? value[0] : null, e.target.value || null])}
                            className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                        />
                    </div>
                )
            }
            return (
                <input
                    type="date"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value || null)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
            )

        default:
            return (
                <input
                    type="text"
                    value={value || ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex-1 px-2 py-1 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter value..."
                />
            )
    }
}
